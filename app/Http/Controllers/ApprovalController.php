<?php

namespace App\Http\Controllers;

use App\Models\ApprovalRequest;
use App\Models\User;
use App\Services\Autopilot\ApprovalService;
use Illuminate\Http\Request;

class ApprovalController extends Controller
{
    public function index(Request $request, ApprovalService $svc)
    {
        $status = $request->input('status', 'open');
        $user = auth()->user();
        $q = ApprovalRequest::with(['assignee', 'subject'])->latest();
        $status === 'open' ? $q->open() : ($status !== 'all' ? $q->where('status', $status) : null);
        if (!$user->isAdmin()) {
            // filter in SQL first (sales-type, unassigned or mine), then lead visibility on the page only
            $q->whereIn('type', ApprovalService::SALES_TYPES)->where(fn ($w) => $w->whereNull('assigned_user_id')->orWhere('assigned_user_id', $user->id));
        }
        $items = $q->paginate(50)->withQueryString();
        if (!$user->isAdmin()) {
            $items->setCollection($items->getCollection()->filter(fn ($r) => $svc->canDecide($user, $r))->values());
        }

        return view('approvals.index', compact('items', 'status'));
    }

    public function show(ApprovalRequest $approval, ApprovalService $svc)
    {
        $user = auth()->user();
        abort_unless($user->isAdmin() || $svc->canDecide($user, $approval), 403);
        $approval->load('events.user', 'assignee', 'decider');

        return view('approvals.show', [
            'a' => $approval,
            'canDecide' => $svc->canDecide($user, $approval),
            'users' => User::where('is_active', true)->orderBy('name')->get(['id', 'name', 'role']),
            'lead' => $svc->leadOf($approval),
        ]);
    }

    public function decide(Request $request, ApprovalRequest $approval, ApprovalService $svc)
    {
        $data = $request->validate([
            'action' => ['required', 'in:' . implode(',', ApprovalService::ACTIONS)],
            'note' => ['nullable', 'string', 'max:2000'],
            'user_id' => ['nullable', 'integer'],
            'payload' => ['nullable', 'array'],
            'payload.subject' => ['nullable', 'string', 'max:255'],
            'payload.body' => ['nullable', 'string', 'max:20000'],
            'payload.category' => ['nullable', 'in:interested,not_interested,request_info,objection,referral,out_of_office,unsubscribe,uncertain'],
        ]);
        $req = $svc->decide($approval, $request->user(), $data['action'], $data);

        return redirect()->route('approvals.show', $req)->with($req->status === 'failed' ? 'error' : 'success',
            'Decision recorded: ' . str_replace('_', ' ', $req->status) . ($req->execution_result ? ' — ' . $req->execution_result : ''));
    }
}
