<?php

namespace App\Services\Autopilot;

use App\Models\Company;
use App\Models\Lead;
use App\Services\Audit\WebsiteAuditService;
use App\Services\EmailVerificationService;
use App\Services\Hunting\LeadHuntService;
use App\Services\LeadScoringService;
use App\Services\Mail\ReplySyncService;
use App\Services\Outreach\SequenceEngine;
use App\Support\EmailStatus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * One autopilot cycle (scheduled every 15 min). Steps are independent: a failure in one is logged
 * and the rest continue. Overlapping cycles are prevented with a cache lock. Every step respects its
 * workflow pause switch and the autonomy policy. All work is idempotent, so a restart simply resumes.
 */
class AutopilotRunner
{
    public function run(bool $dryRun = false): array
    {
        $lock = Cache::lock('leadpilot:autopilot', 1800);
        if (!$lock->get()) {
            return ['skipped' => 'another autopilot cycle is still running'];
        }
        $t0 = microtime(true);
        $wf = app(WorkflowControlService::class);
        $policy = app(AutonomyPolicyService::class);
        $cfg = config('leadpilot.autopilot');
        $out = ['dry_run' => $dryRun, 'steps' => []];

        $step = function (string $name, callable $fn) use (&$out) {
            $s = microtime(true);
            try {
                $out['steps'][$name] = ['ok' => true, 'result' => $fn(), 'ms' => (int) ((microtime(true) - $s) * 1000)];
            } catch (\Throwable $e) {
                Log::error("Autopilot step {$name} failed: " . $e->getMessage());
                $out['steps'][$name] = ['ok' => false, 'error' => mb_substr($e->getMessage(), 0, 500), 'ms' => (int) ((microtime(true) - $s) * 1000)];
            }
        };

        try {
            $step('monitor', fn () => array_map(fn ($i) => $i->title, app(HealthService::class)->monitor()));
            $step('approvals', fn () => app(ApprovalService::class)->processOverdue());

            $step('reply_sync', function () use ($wf, $policy) {
                $svc = app(ReplySyncService::class);
                if (!$svc->isConfigured()) {
                    return 'not configured';
                }

                return $wf->isPaused('reply_sync') || !$policy->isAutomatic('reply.classify') ? 'paused' : $svc->sync();
            });

            $step('discovery', function () use ($wf, $policy, $cfg, $dryRun) {
                if ($wf->isPaused('discovery') || !$policy->isAutomatic('discovery.free')) {
                    return 'paused or not automatic';
                }
                $done = [];
                foreach (app(SearchAllocator::class)->pick((int) $cfg['discovery_runs_per_cycle']) as $arm) {
                    if ($dryRun) {
                        $done[] = "would run {$arm['slug']} for ICP #{$arm['icp']->id}";
                        continue;
                    }
                    $runs = app(LeadHuntService::class)->hunt($arm['icp'], null, false, 0, $arm['slug']);
                    foreach ($runs as $r) {
                        $done[] = "{$r->source_slug} (ICP #{$arm['icp']->id}): {$r->status}, {$r->new_leads} new, {$r->excluded} excluded" . ($r->errors ? ' — ' . implode('; ', (array) $r->errors) : '');
                    }
                }

                return $done ?: 'no eligible search arms';
            });

            $step('enrichment', function () use ($wf, $policy, $cfg, $dryRun) {
                if ($wf->isPaused('enrichment')) {
                    return 'paused';
                }
                $res = ['audited' => 0, 'verified' => 0];
                if ($policy->isAutomatic('enrichment.audit')) {
                    // any audit ATTEMPT in the last 7 days counts (failed/unreachable sites are not retried every cycle)
                    $companies = Company::whereNotNull('website')->whereHas('leads')
                        ->whereDoesntHave('websiteAudits', fn ($q) => $q->where('created_at', '>=', now()->subDays(7)))
                        ->limit((int) $cfg['audits_per_cycle'])->get();
                    foreach ($companies as $c) {
                        if (!$dryRun) {
                            app(WebsiteAuditService::class)->audit($c);
                        }
                        $res['audited']++;
                    }
                }
                if ($policy->isAutomatic('enrichment.verify_email')) {
                    // Free-first waterfall: the SMTP probe always runs; Hunter is used inside the service only when
                    // the probe could not reach the mailbox and the budget allows it. MX-only results are retried
                    // once the inconclusive cache window has passed.
                    $recheckBefore = now()->subHours((int) config('leadpilot.email_verification.inconclusive_cache_hours', 24));
                    $leads = Lead::where('contact_status', 'identified')->whereNotNull('email')
                        ->where(fn ($q) => $q->where('email_verification_status', EmailStatus::UNVERIFIED)
                            ->orWhere(fn ($q) => $q->whereIn('email_verification_status', [EmailStatus::MX_ONLY, EmailStatus::UNKNOWN])
                                ->where(fn ($q) => $q->whereNull('last_verified_at')->orWhere('last_verified_at', '<', $recheckBefore))))
                        ->orderByRaw("CASE WHEN email_verification_status = 'unverified' THEN 0 ELSE 1 END")
                        ->limit(10)->get();
                    foreach ($leads as $l) {
                        if (!$dryRun) {
                            app(EmailVerificationService::class)->verifyEmail($l->email, $l);
                            $res['verified']++;
                        }
                    }
                }

                return $res;
            });

            $step('scoring', function () use ($dryRun) {
                $n = 0;
                $leads = Lead::where(fn ($q) => $q->whereNull('scored_at')->orWhere('scored_at', '<', now()->subDays(7))
                    ->orWhereHas('company', fn ($c) => $c->whereColumn('companies.website_audited_at', '>', 'leads.scored_at')))->limit(100)->get();
                $ladder = app(\App\Services\Outreach\WinLadderService::class);
                foreach ($leads as $l) {
                    if (!$dryRun) {
                        app(LeadScoringService::class)->evaluateLead($l);
                        $ladder->refresh($l->fresh());
                    }
                    $n++;
                }
                $ranked = $dryRun ? 0 : array_sum($ladder->refreshAll(true)); // new or changed leads since the last cycle

                return "{$n} lead(s) scored, {$ranked} re-ranked on the Win Ladder";
            });

            $step('outreach', function () use ($cfg, $dryRun) {
                if ($dryRun) {
                    return 'dry run: no drafts or sends';
                }
                $engine = app(SequenceEngine::class);

                return [
                    'first_touch' => $engine->planFirstTouches((int) $cfg['drafts_per_cycle']),
                    'follow_ups' => $engine->planFollowUps(),
                    'dispatch' => $engine->dispatchDue(),
                ];
            });
        } finally {
            $out['ms'] = (int) ((microtime(true) - $t0) * 1000);
            app(HealthService::class)->beat('autopilot', ['ok' => collect($out['steps'])->every(fn ($s) => $s['ok']), 'ms' => $out['ms'], 'at' => now()->toIso8601String()]);
            $lock->release();
        }

        return $out;
    }
}
