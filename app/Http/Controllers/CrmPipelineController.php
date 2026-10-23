<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\Meeting;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CrmPipelineController extends Controller
{
    public function index(Request $request)
    {
        $assignedTo = $request->query('assigned_to');
        $country = $request->query('country');

        $query = Lead::with(['company', 'assignedUser', 'deals', 'tasks']);

        if ($assignedTo) {
            $query->where('assigned_user_id', $assignedTo);
        }
        if ($country) {
            $query->where('country', $country);
        }

        $allLeads = $query->orderByDesc('overall_score')->get();

        $pipelineStages = [
            'new' => ['label' => 'New Leads', 'color' => 'secondary'],
            'researching' => ['label' => 'Researching', 'color' => 'info'],
            'qualified' => ['label' => 'Qualified', 'color' => 'primary'],
            'ready_for_review' => ['label' => 'Ready for Review', 'color' => 'warning'],
            'contacted' => ['label' => 'Contacted', 'color' => 'info'],
            'replied' => ['label' => 'Replied', 'color' => 'primary'],
            'interested' => ['label' => 'Interested', 'color' => 'success'],
            'meeting_booked' => ['label' => 'Meeting Booked', 'color' => 'success'],
            'proposal_sent' => ['label' => 'Proposal Sent', 'color' => 'purple'],
            'negotiation' => ['label' => 'Negotiation', 'color' => 'warning'],
            'won' => ['label' => 'Deals Won', 'color' => 'success'],
            'lost' => ['label' => 'Deals Lost', 'color' => 'danger'],
        ];

        $columnLeads = [];
        foreach ($pipelineStages as $stageKey => $meta) {
            $columnLeads[$stageKey] = $allLeads->where('lifecycle_stage', $stageKey);
        }

        $salesReps = User::whereIn('role', ['sales', 'admin'])->get();
        $countries = Lead::whereNotNull('country')->distinct()->pluck('country');

        return view('crm.pipeline', compact('columnLeads', 'pipelineStages', 'salesReps', 'countries', 'assignedTo', 'country'));
    }

    public function updateStage(Request $request, Lead $lead)
    {
        $data = $request->validate([
            'lifecycle_stage' => ['required', 'string'],
        ]);

        $oldStage = $lead->lifecycle_stage;
        $newStage = $data['lifecycle_stage'];

        $lead->update(['lifecycle_stage' => $newStage]);

        AuditLog::record('lead_stage_changed', $lead, ['lifecycle_stage' => $oldStage], ['lifecycle_stage' => $newStage]);

        if ($request->wantsJson()) {
            return response()->json([
                'status' => 'success',
                'message' => "Lead moved to {$newStage}.",
                'lead_id' => $lead->id,
                'stage' => $newStage
            ]);
        }

        return back()->with('success', "Lead '{$lead->full_name}' transitioned to " . ucwords(str_replace('_', ' ', $newStage)));
    }

    public function storeTask(Request $request)
    {
        $data = $request->validate([
            'lead_id' => ['nullable', 'exists:leads,id'],
            'company_id' => ['nullable', 'exists:companies,id'],
            'assigned_to' => ['required', 'exists:users,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'due_date' => ['required', 'date'],
            'priority' => ['required', 'in:low,medium,high,urgent'],
        ]);

        $task = Task::create($data);
        AuditLog::record('task_created', $task);

        return back()->with('success', "Follow-up task '{$task->title}' scheduled for {$task->due_date->format('M d, Y')}.");
    }

    public function completeTask(Task $task)
    {
        $task->update([
            'status' => 'completed',
            'completed_at' => now(),
        ]);

        AuditLog::record('task_completed', $task);

        return back()->with('success', 'Task marked as completed.');
    }

    public function storeMeeting(Request $request)
    {
        $data = $request->validate([
            'lead_id' => ['required', 'exists:leads,id'],
            'company_id' => ['nullable', 'exists:companies,id'],
            'host_user_id' => ['required', 'exists:users,id'],
            'title' => ['required', 'string', 'max:255'],
            'meeting_url' => ['nullable', 'url', 'max:255'],
            'scheduled_start' => ['required', 'date'],
            'outcome_notes' => ['nullable', 'string'],
        ]);

        $meeting = Meeting::create($data);

        // Auto move lead to meeting_booked
        $lead = Lead::find($data['lead_id']);
        if ($lead && in_array($lead->lifecycle_stage, ['new', 'qualified', 'contacted', 'replied', 'interested'])) {
            $lead->update(['lifecycle_stage' => 'meeting_booked']);
        }

        AuditLog::record('meeting_scheduled', $meeting);

        return back()->with('success', "Meeting '{$meeting->title}' booked. Lead updated to Meeting Booked stage.");
    }

    public function storeDeal(Request $request)
    {
        $data = $request->validate([
            'lead_id' => ['nullable', 'exists:leads,id'],
            'company_id' => ['required', 'exists:companies,id'],
            'owner_id' => ['required', 'exists:users,id'],
            'title' => ['required', 'string', 'max:255'],
            'value' => ['required', 'numeric', 'min:0'],
            'currency' => ['required', 'string', 'max:10'],
            'stage' => ['required', 'in:discovery,proposal_sent,negotiation,won,lost'],
            'probability' => ['required', 'integer', 'between:0,100'],
            'expected_close_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        $deal = Deal::create($data);

        if (!empty($data['lead_id'])) {
            $lead = Lead::find($data['lead_id']);
            if ($lead && $data['stage'] === 'proposal_sent') {
                $lead->update(['lifecycle_stage' => 'proposal_sent']);
            } elseif ($lead && $data['stage'] === 'won') {
                $lead->update(['lifecycle_stage' => 'won']);
            }
        }

        AuditLog::record('deal_created', $deal);

        return back()->with('success', "Deal '{$deal->title}' ({$deal->currency} " . number_format($deal->value, 2) . ") created in pipeline.");
    }
}
