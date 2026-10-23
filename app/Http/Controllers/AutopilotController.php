<?php

namespace App\Http\Controllers;

use App\Jobs\AutopilotCycleJob;
use App\Models\ApprovalRequest;
use App\Models\CampaignRecipient;
use App\Models\DiscoveryRun;
use App\Models\Incident;
use App\Models\InboundReply;
use App\Models\Lead;
use App\Models\SearchArm;
use App\Models\SpendBudget;
use App\Models\SystemHeartbeat;
use App\Services\Autopilot\AutonomyPolicyService;
use App\Services\Autopilot\HealthService;
use App\Services\Autopilot\IncidentService;
use App\Services\Autopilot\LeadStateService;
use App\Services\Autopilot\PerformanceReport;
use App\Services\Autopilot\WorkflowControlService;
use App\Services\Integrations\CostLedger;
use App\Services\Integrations\ProviderRegistry;
use Illuminate\Http\Request;

class AutopilotController extends Controller
{
    protected function admin(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
    }

    public function index(HealthService $health, WorkflowControlService $wf, ProviderRegistry $registry, CostLedger $ledger)
    {
        $this->admin();
        $today = now(config('leadpilot.timezone'))->startOfDay()->utc();
        $stats = [
            'new_leads' => Lead::where('created_at', '>=', $today)->count(),
            'qualified' => Lead::whereIn('pipeline_state', ['qualified', 'eligible_for_outreach'])->count(),
            'sent_today' => CampaignRecipient::where('sent_at', '>=', $today)->count(),
            'replies_today' => InboundReply::where('received_at', '>=', $today)->count(),
            'spend_today' => $ledger->spentCents(null, 'daily') / 100,
            'spend_month' => $ledger->spentCents(null, 'monthly') / 100,
        ];
        $states = Lead::selectRaw('pipeline_state, count(*) as n')->groupBy('pipeline_state')->pluck('n', 'pipeline_state');

        return view('autopilot.index', [
            'stats' => $stats,
            'states' => $states,
            'workflows' => $wf->states(),
            'incidents' => Incident::whereIn('status', ['open', 'acknowledged'])->latest()->get(),
            'approvals' => ApprovalRequest::open()->orderByRaw("case priority when 'critical' then 0 when 'high' then 1 when 'normal' then 2 else 3 end")->oldest()->limit(8)->get(),
            'providers' => $registry->statusReport(),
            'heartbeats' => SystemHeartbeat::all()->keyBy('component'),
            'runs' => DiscoveryRun::with('icpProfile')->latest()->limit(10)->get(),
            'arms' => SearchArm::with('icpProfile')->orderByDesc('qualified')->limit(10)->get(),
            'summary' => $health->summary(),
        ]);
    }

    public function runNow()
    {
        $this->admin();
        AutopilotCycleJob::dispatch();

        return back()->with('success', 'Autopilot cycle queued. It runs on the queue worker; refresh in a minute.');
    }

    public function toggleWorkflow(Request $request, WorkflowControlService $wf, string $workflow)
    {
        $this->admin();
        abort_unless(in_array($workflow, WorkflowControlService::WORKFLOWS, true), 404);
        $request->boolean('pause') ? $wf->pause($workflow, 'Paused manually by ' . auth()->user()->name) : $wf->resume($workflow);

        return back()->with('success', ucfirst(str_replace('_', ' ', $workflow)) . ($request->boolean('pause') ? ' paused.' : ' resumed.'));
    }

    public function resolveIncident(Request $request, Incident $incident, IncidentService $svc)
    {
        $this->admin();
        $svc->resolve($incident, auth()->id(), $request->boolean('resume', true));

        return back()->with('success', "Incident #{$incident->id} resolved" . ($request->boolean('resume', true) ? ' and workflows resumed.' : '.'));
    }

    public function policy(AutonomyPolicyService $policy)
    {
        $this->admin();

        return view('autopilot.policy', [
            'modes' => $policy->all(),
            'budgets' => SpendBudget::orderBy('scope')->get(),
            'globalDaily' => config('leadpilot.budgets.global_daily'),
            'globalMonthly' => config('leadpilot.budgets.global_monthly'),
        ]);
    }

    public function updatePolicy(Request $request, AutonomyPolicyService $policy)
    {
        $this->admin();
        $data = $request->validate(['modes' => ['required', 'array'], 'modes.*' => ['in:automatic,approval,prohibited']]);
        foreach ($data['modes'] as $action => $mode) {
            if (array_key_exists($action, $policy->all())) {
                // commercial commitments can never be automatic
                if (in_array($action, ['proposal.send', 'spend.over_budget', 'integration.enable'], true) && $mode === 'automatic') {
                    continue;
                }
                $policy->set($action, $mode, auth()->id());
            }
        }

        return back()->with('success', 'Autonomy policy saved.');
    }

    public function saveBudget(Request $request)
    {
        $this->admin();
        $d = $request->validate([
            'scope' => ['required', 'in:global,provider,campaign,icp'], 'scope_ref' => ['nullable', 'string', 'max:80'],
            'period' => ['required', 'in:daily,monthly'], 'limit_usd' => ['required', 'numeric', 'min:0', 'max:100000'],
        ]);
        SpendBudget::updateOrCreate(['scope' => $d['scope'], 'scope_ref' => $d['scope'] === 'global' ? '*' : ($d['scope_ref'] ?? ''), 'period' => $d['period']],
            ['limit_cents' => (int) round($d['limit_usd'] * 100), 'is_active' => true]);

        return back()->with('success', 'Budget saved.');
    }

    public function setup(HealthService $health)
    {
        $this->admin();

        return view('autopilot.setup', ['checks' => $health->checks()]);
    }

    public function applyDefaults(HealthService $health)
    {
        $this->admin();

        return back()->with('success', implode(' ', $health->applyDefaults()));
    }

    public function performance(Request $request, PerformanceReport $report)
    {
        $this->admin();
        return view('autopilot.performance', ['r' => $report->build((int) $request->input('days', 90)), 'states' => LeadStateService::STATES]);
    }
}
