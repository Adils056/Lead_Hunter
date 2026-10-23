<?php

namespace App\Services\Hunting;

use App\Models\Company;
use App\Models\DiscoveryRun;
use App\Models\IcpProfile;
use App\Models\Lead;
use App\Services\Audit\WebsiteAuditService;
use App\Services\Discovery\DiscoveryManager;
use App\Services\LeadScoringService;
use Illuminate\Support\Facades\Log;

/**
 * Orchestrates a hunt: ICP -> per-source search config -> discovery -> (optional) website audit
 * -> scoring against the ICP -> logged DiscoveryRun rows.
 */
class LeadHuntService
{
    public function __construct(
        protected DiscoveryManager $discovery,
        protected IcpSearchConfigBuilder $builder,
        protected LeadScoringService $scoring,
        protected WebsiteAuditService $audits,
    ) {
    }

    /** @return DiscoveryRun[] */
    public function hunt(IcpProfile $icp, ?int $userId = null, bool $audit = true, int $maxAudits = 15, ?string $onlySlug = null, bool $paidApproved = false): array
    {
        $runs = [];
        $newLeadIds = [];
        $runBySlug = [];
        $registry = app(\App\Services\Integrations\ProviderRegistry::class);
        $policy = app(\App\Services\Autopilot\AutonomyPolicyService::class);
        $budget = app(\App\Services\Integrations\BudgetGuard::class);
        $health = app(\App\Services\Integrations\ProviderHealthService::class);

        if (app(\App\Services\Autopilot\WorkflowControlService::class)->isPaused('discovery')) {
            return [DiscoveryRun::create(['icp_profile_id' => $icp->id, 'source_slug' => $onlySlug ?? 'all', 'status' => 'skipped',
                'errors' => ['Discovery workflow is paused (see Autopilot dashboard).'], 'triggered_by' => $userId, 'started_at' => now(), 'finished_at' => now()])];
        }

        foreach ($this->builder->build($icp) as $slug => $options) {
            if ($onlySlug && $slug !== $onlySlug) {
                continue;
            }
            $adapter = $this->discovery->getAdapter($slug);
            $run = DiscoveryRun::create([
                'icp_profile_id' => $icp->id,
                'source_slug' => $slug,
                'status' => 'running',
                'options' => $options,
                'triggered_by' => $userId,
                'started_at' => now(),
            ]);

            if (!$adapter) {
                $run->update(['status' => 'skipped', 'errors' => ["Unknown source '{$slug}'."], 'finished_at' => now()]);
                $runs[] = $run;
                continue;
            }
            if (!$adapter->isConfigured()) {
                $run->update(['status' => 'skipped', 'errors' => ["{$adapter->getSourceName()} is not configured."], 'finished_at' => now()]);
                $runs[] = $run;
                continue;
            }

            // Paid providers: autonomy policy + budget gate (free-first)
            $def = $registry->definition($slug);
            if ($def && $def['paid']) {
                $est = max(1, count($options['queries'] ?? [1])) * (int) $def['est_cost_cents'];
                $mode = $policy->mode('discovery.paid');
                $check = $budget->check($slug, $est, null, $icp->id);
                if ($mode === 'prohibited' || !$check['allowed'] || ($mode === 'approval' && !$paidApproved)) {
                    $why = $mode === 'prohibited' ? 'Paid discovery is prohibited by the Autonomy policy.' : ($check['allowed'] ? 'Paid discovery needs approval.' : $check['reason']);
                    $run->update(['status' => 'skipped', 'errors' => [$why], 'finished_at' => now()]);
                    $runs[] = $run;
                    $type = $check['allowed'] ? 'paid_discovery' : 'spend_over_budget';
                    $askable = $mode !== 'prohibited'
                        && !($type === 'spend_over_budget' && $policy->isProhibited('spend.over_budget'))
                        && !\App\Models\ApprovalRequest::where('type', $type)->where('subject_id', $icp->id)->where('status', 'rejected')
                            ->where('decided_at', '>=', now()->subDays(7))->where('payload->provider', $slug)->exists();
                    if ($askable) {
                        app(\App\Services\Autopilot\ApprovalService::class)->request($type,
                            "paid:{$slug}:{$icp->id}:" . now()->format('Ymd'), [
                                'title' => "Approve paid search on {$def['label']} for \"{$icp->name}\"",
                                'summary' => "Estimated cost about $" . number_format($est / 100, 2) . " ({$why}). Free sources have already been used where available.",
                                'priority' => 'normal',
                                'payload' => ['provider' => $slug, 'icp_profile_id' => $icp->id, 'estimate_cents' => $est,
                                    'new_limit_cents' => app(\App\Services\Integrations\CostLedger::class)->spentCents($slug, 'monthly') + max($est * 5, 500)],
                                'evidence' => ['queries' => $options['queries'] ?? [], 'budget' => $check['reason'] ?? 'within budget'],
                                'options' => ['approve', 'reject'],
                                'recommended' => 'reject',
                            ], $icp);
                    }
                    continue;
                }
            }

            $result = $adapter->discover($options + ['icp' => $icp]);
            $newLeadIds = array_merge($newLeadIds, $result->newLeadIds);
            $runBySlug[$slug] = $result->newLeadIds;
            if ($result->errors && !$result->totalFound) {
                $health->failure($slug, implode(' | ', $result->errors), preg_match('/HTTP (401|403)/', implode(' ', $result->errors), $m) ? (int) $m[1] : null);
            } else {
                $health->success($slug, true);
            }
            if ($result->estimatedCostCents > 0) {
                app(\App\Services\Integrations\CostLedger::class)->record($slug, $def['capability'] ?? 'discovery', $result->estimatedCostCents, true, ['icp_profile_id' => $icp->id, 'run' => $run->id]);
            }

            $run->update([
                'status' => ($result->errors && !$result->totalFound) ? 'failed' : 'completed',
                'total_found' => $result->totalFound,
                'new_companies' => $result->newCompaniesCount,
                'new_leads' => $result->newLeadsCount,
                'new_signals' => $result->newSignalsCount,
                'duplicates' => $result->duplicatesCount,
                'excluded' => $result->excludedCount,
                'errors' => $result->errors ?: null,
                'estimated_cost_cents' => $result->estimatedCostCents,
                'finished_at' => now(),
            ]);
            $runs[] = $run;
        }

        $leads = Lead::with('company')->whereIn('id', $newLeadIds)->get();

        // Audit websites of new prospects (real HTTP checks; also finds public business emails).
        if ($audit) {
            $audited = 0;
            foreach ($leads as $lead) {
                $company = $lead->company;
                if ($audited >= $maxAudits || !$company || !$company->website) {
                    continue;
                }
                try {
                    $this->audits->audit($company);
                    $audited++;
                } catch (\Throwable $e) {
                    Log::warning("Audit failed for company #{$company->id}: {$e->getMessage()}");
                }
            }
        }

        foreach ($leads as $lead) {
            try {
                $this->scoring->evaluateLead($lead->fresh(), $userId);
            } catch (\Throwable $e) {
                Log::warning("Scoring failed for lead #{$lead->id}: {$e->getMessage()}");
            }
        }

        foreach ($runBySlug as $slug => $ids) {
            $arm = \App\Models\SearchArm::firstOrCreate(['icp_profile_id' => $icp->id, 'source_slug' => $slug]);
            $run = collect($runs)->firstWhere('source_slug', $slug);
            $arm->update([
                'runs' => $arm->runs + 1,
                'leads' => $arm->leads + count($ids),
                'qualified' => $arm->qualified + Lead::whereIn('id', $ids)->where('overall_score', '>=', \App\Support\ScoreBands::qualified())->count(),
                'cost_cents' => $arm->cost_cents + (int) ($run->estimated_cost_cents ?? 0),
                'last_run_at' => now(),
                // nothing new twice in a row -> rest this arm for 3 days (saves quota). Only a run that actually
                // completed counts: a failed/skipped run (timeout, provider down, no key) says nothing about the arm.
                'exhausted_until' => count($ids) === 0 && $arm->last_run_at && $arm->leads === $arm->getOriginal('leads')
                    && !in_array($run->status ?? null, ['failed', 'skipped'], true)
                    // capped run (found == limit): the source still has more; only an uncapped empty run proves exhaustion
                    && !(isset($run->options['limit']) && (int) $run->total_found >= (int) $run->options['limit'] && (int) $run->options['limit'] > 0)
                    ? now()->addDays(3) : null,
            ]);
        }

        $icp->update(['last_run_at' => now()]);

        return $runs;
    }
}
