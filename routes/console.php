<?php

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Services\CampaignDeliveryService;
use App\Services\LeadScoringService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/**
 * Dispatch due outreach emails across running campaigns with quota compliance.
 */
Artisan::command('leadpilot:dispatch-campaigns', function (CampaignDeliveryService $deliveryService) {
    $this->info("Scanning running campaigns for scheduled outreach recipients...");

    $campaigns = Campaign::where('status', 'running')
        ->where('emergency_stopped', false)
        ->where('is_autopilot', false) // v4: autopilot campaigns are sent only by SequenceEngine (guards, window, caps)
        ->get();

    if ($campaigns->isEmpty()) {
        $this->comment("No active campaigns currently running.");
        return 0;
    }

    $totalDispatched = 0;

    foreach ($campaigns as $campaign) {
        $this->line("Processing campaign: {$campaign->name} (Limit: {$campaign->sent_today}/{$campaign->daily_limit})");

        if (!$campaign->canSendToday()) {
            $this->warn("  Daily limit reached for '{$campaign->name}'. Skipping remaining recipients until tomorrow.");
            continue;
        }

        $recipients = $campaign->recipients()
            ->where('status', 'approved')
            ->where(function ($q) {
                $q->whereNull('scheduled_at')
                  ->orWhere('scheduled_at', '<=', now());
            })
            ->limit($campaign->daily_limit - $campaign->sent_today)
            ->get();

        foreach ($recipients as $recipient) {
            $success = $deliveryService->dispatchRecipient($recipient, true); // scheduled send = automated (dry-run enforced)
            if ($success) {
                $totalDispatched++;
                $this->info("  [SENT] Dispatched email to {$recipient->lead->email}");
            } else {
                $this->warn("  [SKIPPED] Recipient #{$recipient->id} ({$recipient->status}): {$recipient->error_message}");
            }

            if (!$campaign->canSendToday()) {
                break;
            }
        }
    }

    $this->info("Campaign dispatch cycle complete. Total sent: {$totalDispatched}");
    return 0;
})->purpose('Dispatch scheduled outreach emails across running campaigns');

/**
 * Run 100-point scoring algorithm across all pending or unscored leads.
 */
Artisan::command('leadpilot:score-leads {--force : Re-score all leads even if already scored}', function (LeadScoringService $scoringService) {
    $force = $this->option('force');
    $query = Lead::query();

    if (!$force) {
        $query->whereNull('scored_at');
    }

    $leads = $query->get();
    $this->info("Evaluating " . $leads->count() . " leads using 100-point explainable scoring engine...");

    $bar = $this->output->createProgressBar($leads->count());
    $bar->start();

    $scoredCount = 0;
    foreach ($leads as $lead) {
        $scoringService->evaluateLead($lead);
        $scoredCount++;
        $bar->advance();
    }

    $bar->finish();
    $this->newLine();
    $this->info("Scoring complete. {$scoredCount} leads evaluated.");
    return 0;
})->purpose('Evaluate leads against the 100-point transparent scoring algorithm');

/**
 * Execute free-first automated lead discovery across configured adapters.
 */
Artisan::command('leadpilot:discover {source? : Adapter slug (google_places, uk_contracts_finder, ats_watchlist, apollo_orgs, public_job_demand) or blank for all configured}', function (\App\Services\Discovery\DiscoveryManager $discoveryManager) {
    $sourceSlug = $this->argument('source');

    if ($sourceSlug) {
        $this->info("Running lead discovery for source: {$sourceSlug}...");
        $result = $discoveryManager->runDiscovery($sourceSlug);

        $this->table(
            ['Source', 'Found', 'New Companies', 'New Leads', 'New Signals', 'Duplicates', 'Success?'],
            [[
                $result->sourceName,
                $result->totalFound,
                $result->newCompaniesCount,
                $result->newLeadsCount,
                $result->newSignalsCount,
                $result->duplicatesCount,
                $result->isSuccess() ? 'YES' : 'NO'
            ]]
        );

        if (!empty($result->errors)) {
            foreach ($result->errors as $err) {
                $this->error("  [ERROR] {$err}");
            }
        }
    } else {
        $this->info("Running automated free-first discovery across all registered active sources...");
        $results = $discoveryManager->runAllActive();

        $rows = [];
        foreach ($results as $res) {
            $rows[] = [
                $res->sourceName,
                $res->totalFound,
                $res->newCompaniesCount,
                $res->newLeadsCount,
                $res->newSignalsCount,
                $res->duplicatesCount,
                $res->isSuccess() ? 'YES' : 'NO'
            ];
        }

        $this->table(
            ['Source', 'Found', 'New Companies', 'New Leads', 'New Signals', 'Duplicates', 'Success?'],
            $rows
        );
    }

    return 0;
})->purpose('Discover new B2B prospective leads and buying signals via free-first adapters');

/**
 * Run autonomous cycle: discovery -> scoring -> eligibility check -> campaign evaluation.
 */
Artisan::command('leadpilot:autonomous-cycle', function (
    \App\Services\Discovery\DiscoveryManager $discoveryManager,
    \App\Services\LeadScoringService $scoringService,
    \App\Services\CampaignDeliveryService $deliveryService,
    \App\Services\EligibilityGateService $eligibilityGate
) {
    $settings = \App\Models\OutreachSetting::current();
    $this->info("=================================================");
    $this->info("   LeadPilot AI — Autonomous Outreach Cycle");
    $this->info("   Mode: " . strtoupper(str_replace('_', ' ', $settings->mode)));
    $this->info("   Emergency Stop: " . ($settings->isEmergencyStopped() ? 'ENGAGED' : 'OFF'));
    $this->info("=================================================");

    // Step 1: Run Discovery
    $this->line("[1/4] Running free-first lead discovery...");
    $discoveryResults = $discoveryManager->runAllActive();
    $totalNewLeads = 0;
    foreach ($discoveryResults as $res) {
        $totalNewLeads += $res->newLeadsCount;
    }
    $this->info("      Discovered {$totalNewLeads} new prospective leads.");

    // Step 2: Score Leads
    $this->line("[2/4] Scoring unscored leads with 100-point transparent engine...");
    $unscoredLeads = \App\Models\Lead::whereNull('scored_at')->get();
    foreach ($unscoredLeads as $lead) {
        $scoringService->evaluateLead($lead);
    }
    $this->info("      Scored {$unscoredLeads->count()} leads.");

    // Step 3: Check Active Campaigns & Draft Recipients
    $this->line("[3/4] Evaluating running campaigns for eligible outreach prospects...");
    $runningCampaigns = \App\Models\Campaign::where('status', 'running')->where('is_autopilot', false)
        ->where('emergency_stopped', false)
        ->get();

    $totalDrafted = 0;
    foreach ($runningCampaigns as $cmp) {
        $drafted = $deliveryService->buildCampaignDrafts($cmp);
        $totalDrafted += $drafted;
    }
    $this->info("      Created {$totalDrafted} new campaign recipient drafts.");

    // Step 4: Dispatch Cycle with 10-Point Eligibility Gate
    $this->line("[4/4] Evaluating and dispatching approved outreach recipients...");

    if ($settings->isEmergencyStopped()) {
        $this->warn("      EMERGENCY STOP is active. Dispatches halted.");
        return 0;
    }

    if ($settings->isDryRun()) {
        $this->comment("      DRY-RUN MODE is active. No emails dispatched (safe simulation).");
        return 0;
    }

    if (!$settings->isWithinSendingWindow()) {
        $this->comment("      Outside configured sending window ({$settings->sending_window_start} - {$settings->sending_window_end} {$settings->sending_timezone}). Postponing send.");
        return 0;
    }

    $dispatchedCount = 0;
    foreach ($runningCampaigns as $campaign) {
        if (!$campaign->canSendToday()) {
            continue;
        }

        $recipients = $campaign->recipients()
            ->where('status', 'approved')
            ->where(function ($q) {
                $q->whereNull('scheduled_at')->orWhere('scheduled_at', '<=', now());
            })
            ->limit($campaign->daily_limit - $campaign->sent_today)
            ->get();

        foreach ($recipients as $recipient) {
            $dispatched = $deliveryService->dispatchRecipient($recipient, true);
            if ($dispatched) {
                $dispatchedCount++;
                $this->info("      [SENT] Outreach email dispatched to: {$recipient->lead->email}");
            } else {
                $this->line("      [HELD] Recipient #{$recipient->id} ({$recipient->status}): {$recipient->error_message}");
            }

            if (!$campaign->canSendToday()) {
                break;
            }
        }
    }

    $this->info("Autonomous cycle complete. Total sent: {$dispatchedCount}");
    return 0;
})->purpose('Execute full automated cycle: discovery, scoring, eligibility checks, and outreach');

/*
|--------------------------------------------------------------------------
| Scheduled Background Tasks
|--------------------------------------------------------------------------
*/
// v4: superseded by AutopilotCycleJob (policy, approvals, budgets, incidents). Command kept for manual use.
// Schedule::command('leadpilot:autonomous-cycle')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('leadpilot:dispatch-campaigns')->everyTenMinutes()->withoutOverlapping();
Schedule::command('leadpilot:score-leads')->hourly()->withoutOverlapping();
// v4: discovery is allocated by the autopilot (SearchAllocator); manual: php artisan leadpilot:discover
// Schedule::command('leadpilot:discover')->twiceDaily(9, 15)->withoutOverlapping();


/*
|--------------------------------------------------------------------------
| Lead Hunting Engine v3 commands
|--------------------------------------------------------------------------
*/
Artisan::command('leadpilot:hunt {icp? : ICP profile id (blank = all active)} {--no-audit : Skip website audits}', function (\App\Services\Hunting\LeadHuntService $hunter) {
    $q = \App\Models\IcpProfile::where('is_active', true);
    if ($id = $this->argument('icp')) {
        $q = \App\Models\IcpProfile::whereKey($id);
    }
    foreach ($q->get() as $icp) {
        $this->info("Hunting: {$icp->name}");
        foreach ($hunter->hunt($icp, null, !$this->option('no-audit')) as $run) {
            $this->line(sprintf('  %-22s %-9s found=%d new_leads=%d excluded=%d est=$%.2f %s', $run->source_slug, $run->status, $run->total_found, $run->new_leads, $run->excluded, $run->estimated_cost_cents / 100, implode(' | ', (array) $run->errors)));
        }
    }
})->purpose('Run lead hunts for Ideal Customer Profiles');

Artisan::command('leadpilot:audit {company : Company id}', function (\App\Services\Audit\WebsiteAuditService $audits, \App\Services\LeadScoringService $scoring) {
    $company = \App\Models\Company::findOrFail($this->argument('company'));
    $audit = $audits->audit($company);
    foreach ($company->leads as $lead) {
        $scoring->evaluateLead($lead->fresh());
    }
    $this->info("Audit #{$audit->id} {$audit->status} — opportunity {$audit->opportunity_score}/100");
    $this->line($audit->summary);
})->purpose('Run an evidence-based website audit for one company');

Artisan::command('leadpilot:places-purge {--days= : Override retention days}', function () {
    // Google Maps Platform terms: only place_id may be stored indefinitely. Remove Places-sourced
    // field values older than the retention window unless a different source has since confirmed them.
    $days = (int) ($this->option('days') ?: config('leadpilot.sources.google_places.retention_days', 30));
    $cutoff = now()->subDays($days);
    $purged = 0;
    $rows = \App\Models\DataProvenance::where('source', 'google_places')->where('collected_at', '<', $cutoff)
        ->where('subject_type', (new \App\Models\Company())->getMorphClass())->get()->groupBy('subject_id');
    foreach ($rows as $companyId => $fields) {
        $company = \App\Models\Company::find($companyId);
        if (!$company) {
            continue;
        }
        $clear = [];
        foreach ($fields as $p) {
            $confirmedElsewhere = \App\Models\DataProvenance::where('subject_type', $p->subject_type)->where('subject_id', $companyId)
                ->where('field', $p->field)->where('source', '!=', 'google_places')->exists();
            if (!$confirmedElsewhere && in_array($p->field, ['public_phone', 'public_address', 'google_rating', 'google_review_count', 'business_status', 'city', 'industry', 'website', 'domain'], true)
                && (string) $company->{$p->field} === (string) $p->value) {
                $clear[$p->field] = null;
            }
        }
        if ($clear) {
            $company->update($clear);
            $purged += count($clear);
        }
        \App\Models\DataProvenance::whereIn('id', $fields->pluck('id'))->delete();
    }
    // Lead phone numbers copied from Places
    $leadRows = \App\Models\DataProvenance::where('source', 'google_places')->where('collected_at', '<', $cutoff)
        ->where('subject_type', (new \App\Models\Lead())->getMorphClass())->where('field', 'phone')->get();
    foreach ($leadRows as $p) {
        $lead = \App\Models\Lead::find($p->subject_id);
        if ($lead && (string) $lead->phone === (string) $p->value) {
            $lead->update(['phone' => null]);
            $purged++;
        }
        $p->delete();
    }
    $this->info("Purged {$purged} Google Places field value(s) older than {$days} days (place_id kept).");
})->purpose('Enforce Google Places content retention');

Schedule::command('leadpilot:places-purge')->dailyAt('03:10')->timezone(config('leadpilot.timezone', 'Asia/Karachi'));

Artisan::command('leadpilot:go-autonomous {--live : Also switch outreach mode to live (otherwise it stays as it is)}', function (\App\Services\Hunting\AutoMarketService $market, \App\Services\Autopilot\AutonomyPolicyService $policy) {
    // One switch for "find the market, qualify, and send on its own", with the guardrails kept on.
    $settings = \App\Models\OutreachSetting::current();
    $countries = array_keys((array) config('leadpilot.auto_market.countries', []));
    $settings->update([
        'allowed_countries' => $countries,
        'require_verified_email' => true,
        'allow_mx_only' => false,
        'allow_shared_inbox' => true, // local businesses publish one inbox (info@); tender contacts stay excluded
        'max_daily_sends' => max(1, (int) config('leadpilot.autopilot.max_first_touch_per_day', 25)),
        'emergency_stop' => false,
    ] + ($this->option('live') ? ['mode' => 'live', 'activated_at' => now()] : []));
    foreach (['discovery.free', 'enrichment.audit', 'enrichment.verify_email', 'scoring.run', 'drafting.ai', 'outreach.first_touch', 'outreach.follow_up'] as $action) {
        $policy->set($action, 'automatic');
    }
    \App\Models\Campaign::where('is_autopilot', true)->update(['require_approval' => false]);
    $sync = $market->sync();
    \Illuminate\Support\Facades\Cache::put('auto_market_synced', now()->toIso8601String(), now()->addDay());
    $settings->refresh();

    $this->table(['Setting', 'Value'], [
        ['Target countries', implode(', ', $countries)],
        ['Auto Market profiles', "{$sync['active']} active ({$sync['created']} new)"],
        ['First email', 'automatic (Win Ladder rung A, sendable email only, suppression always honoured)'],
        ['First emails per day', $settings->firstTouchCap() . ' now, ' . (int) config('leadpilot.autopilot.max_first_touch_per_day', 25) . ' after the warm-up'],
        ['Outreach mode', $settings->mode],
    ]);
    if ($settings->mode !== 'live') {
        $this->warn('Mode is still ' . $settings->mode . ': leads are found, verified, scored and drafted, but nothing is sent. Run again with --live when ready.');
    }

    return 0;
})->purpose('Fully autonomous mode: auto-build targets, verify, score and send first emails within the guardrails');

Artisan::command('leadpilot:status', function (\App\Services\Outreach\SequenceEngine $sequence) {
    // One-screen answer to "is everything working right now?": each stage of the pipeline with PASS / WARN / FAIL.
    $rows = [];
    $bad = 0;
    $add = function (string $status, string $stage, string $detail) use (&$rows, &$bad) {
        $bad += $status === 'FAIL' ? 1 : 0;
        $rows[] = [$status === 'PASS' ? '<info>PASS</info>' : ($status === 'FAIL' ? '<error>FAIL</error>' : "<comment>{$status}</comment>"), $stage, $detail];
    };
    $beat = fn (string $c) => \App\Models\SystemHeartbeat::where('component', $c)->first();
    $ago = fn ($t) => $t ? $t->diffForHumans() : 'never';
    $health = fn (string $p) => \App\Models\ProviderHealth::where('provider', $p)->first();
    $settings = \App\Models\OutreachSetting::current();
    $tz = config('leadpilot.timezone');

    foreach (['scheduler' => [5, 'Cron (scheduler)'], 'queue_worker' => [15, 'Queue worker'], 'autopilot' => [35, 'Autopilot cycle']] as $c => [$max, $label]) {
        $hb = $beat($c);
        $fresh = $hb && $hb->beat_at->gt(now()->subMinutes($max));
        $stepsOk = $c !== 'autopilot' || ($hb->meta['ok'] ?? false);
        $detail = 'last run ' . $ago($hb?->beat_at) . ($c === 'autopilot' && $hb && !$stepsOk ? ' — a step failed: php artisan leadpilot:autopilot' : '');
        $started = $c === 'autopilot' ? $beat('autopilot_started') : null;
        if ($started && (!$hb || $started->beat_at->gt($hb->beat_at))) {
            $detail .= ' · a cycle started ' . $ago($started->beat_at) . ' has not finished'
                . ($started->beat_at->lt(now()->subMinutes(20)) ? ' (killed by the host?) — php artisan leadpilot:autopilot --unlock' : '');
        }
        $add($fresh && $stepsOk ? 'PASS' : 'FAIL', $label, $detail);
    }

    $new24 = \App\Models\Lead::where('created_at', '>=', now()->subDay())->count();
    $add($new24 > 0 ? 'PASS' : 'WARN', 'Discovery', "{$new24} new lead(s) in 24 h · " . \App\Models\Lead::count() . ' total · ' . \App\Models\IcpProfile::where('is_active', true)->count() . ' active target profile(s)');

    $checked24 = \App\Models\Lead::where('last_verified_at', '>=', now()->subDay())->count();
    $byStatus = \App\Models\Lead::whereNotNull('email')->selectRaw('email_verification_status s, count(*) n')->groupBy('s')->pluck('n', 's');
    $add($checked24 > 0 || ($byStatus[\App\Support\EmailStatus::UNVERIFIED] ?? 0) === 0 ? 'PASS' : 'WARN', 'Email check',
        "{$checked24} checked in 24 h · " . $byStatus->map(fn ($n, $st) => \App\Support\EmailStatus::label($st) . " {$n}")->implode(', '));

    $rungA = \App\Models\Lead::where('win_tier', 'A')->whereIn('email_verification_status', \App\Support\EmailStatus::sendableStatuses($settings))->count();
    $add($rungA > 0 ? 'PASS' : 'WARN', 'Ready to email (rung A)', "{$rungA} lead(s)" . ($rungA ? '' : ' — normal in the first hours; leads need audit + verified email + score'));

    $modeOk = $settings->mode === 'live' && !$settings->isEmergencyStopped();
    $add($modeOk ? 'PASS' : 'WARN', 'Outreach mode', $settings->mode . ($settings->isEmergencyStopped() ? ' · EMERGENCY STOP ON' : '') . ' · first emails today cap ' . $settings->firstTouchCap());

    $sentToday = \App\Models\CampaignRecipient::where('sent_at', '>=', now($tz)->startOfDay()->utc())->count();
    $failedToday = \App\Models\CampaignRecipient::where('status', 'failed')->where('updated_at', '>=', now($tz)->startOfDay()->utc())->count();
    // each e-mail leaves inside its prospect's own working hours (their country's time zone and working days)
    $queued = \App\Models\CampaignRecipient::with('lead.company')->where('status', 'approved')->whereHas('campaign', fn ($q) => $q->where('is_autopilot', true))->limit(200)->get();
    $openNow = $queued->filter(fn ($r) => $sequence->inSendingWindow(null, $r->lead))->count();
    $window = $openNow > 0;
    $sentRecent = \App\Models\CampaignRecipient::where('sent_at', '>=', now()->subHours(2))->exists();
    $overdue = $queued->filter(fn ($r) => (!$r->scheduled_at || $r->scheduled_at->lt(now()->subHour())) && $sequence->inSendingWindow(null, $r->lead))->isNotEmpty();
    $stalled = $modeOk && $overdue && !$sentRecent && $sentToday < $settings->firstTouchCap();
    $add($failedToday ? 'FAIL' : ($stalled ? 'WARN' : 'PASS'), 'Sending',
        "{$sentToday} sent today" . ($failedToday ? " · {$failedToday} FAILED" : '') . " · {$queued->count()} queued: {$openNow} inside the prospect's working hours now, "
        . ($queued->count() - $openNow) . ' waiting for theirs');

    $sent7 = \App\Models\CampaignRecipient::where('sent_at', '>=', now()->subDays(7))->count();
    $bounced7 = \App\Models\CampaignRecipient::where('status', 'bounced')->where('updated_at', '>=', now()->subDays(7))->count();
    $rate = $sent7 ? round(100 * $bounced7 / $sent7, 1) : 0;
    $add($rate > 5 ? 'FAIL' : ($rate > 2 ? 'WARN' : 'PASS'), 'Bounce rate (7 d)', "{$rate}% ({$bounced7} of {$sent7})" . ($rate > 5 ? ' — press Emergency Stop and check the leads' : ''));

    $smtp = $health('smtp');
    $add($smtp && $smtp->consecutive_failures === 0 ? 'PASS' : ($smtp ? 'FAIL' : 'WARN'), 'SMTP (sending)', $smtp ? 'last OK ' . $ago($smtp->last_success_at) . ($smtp->consecutive_failures ? " · {$smtp->consecutive_failures} failure(s): " . mb_substr((string) $smtp->last_error, 0, 80) : '') : 'never tested: php artisan leadpilot:mail-test <admin email>');

    if (config('leadpilot.imap.enabled')) {
        $imap = $health('imap');
        $fresh = $imap && $imap->last_success_at && $imap->last_success_at->gt(now()->subHour()) && $imap->consecutive_failures === 0;
        $add($fresh ? 'PASS' : 'FAIL', 'Reply sync (IMAP)', 'last OK ' . $ago($imap?->last_success_at) . ' · ' . \App\Models\InboundReply::where('created_at', '>=', now()->subDays(7))->count() . ' repl(ies) in 7 d');
    } else {
        $add('WARN', 'Reply sync (IMAP)', 'off — replies are not read, follow-ups will not stop on a reply');
    }

    $open = \App\Models\Incident::where('status', 'open')->latest()->get();
    $add($open->isEmpty() ? 'PASS' : 'WARN', 'Open incidents', $open->isEmpty() ? 'none' : $open->count() . ': ' . mb_substr($open->pluck('title')->implode('; '), 0, 100));

    $this->table(['Status', 'Stage', 'Detail'], $rows);
    $bad ? $this->error("{$bad} stage(s) FAILING — fix those first.") : $this->info('No failures. WARN lines are expected while leads are still being built up or outside the sending window.');

    return $bad ? 1 : 0;
})->purpose('One-screen PASS/WARN/FAIL status of every pipeline stage');

Artisan::command('leadpilot:reverify-emails {--status=mx_only : Comma-separated statuses to re-check} {--limit=200}', function (\App\Services\EmailVerificationService $verifier) {
    // P0-3 follow-up: re-runs the verification waterfall (cache bypassed) for leads whose mailbox was never proven.
    $statuses = array_values(array_intersect(array_map('trim', explode(',', (string) $this->option('status'))), \App\Support\EmailStatus::ALL));
    if ($statuses === []) {
        $this->error('No valid status given. Use: ' . implode(', ', \App\Support\EmailStatus::ALL));

        return 1;
    }
    $leads = \App\Models\Lead::whereIn('email_verification_status', $statuses)->whereNotNull('email')->where('email', '!=', '')
        ->orderBy('last_verified_at')->limit(max(1, (int) $this->option('limit')))->get();
    $tally = [];
    foreach ($leads as $lead) {
        $status = $verifier->verifyEmail($lead->email, $lead, null, fresh: true)['status'];
        $tally[$status] = ($tally[$status] ?? 0) + 1;
    }
    $this->info('Re-verified ' . $leads->count() . ' lead(s): ' . ($tally ? collect($tally)->map(fn ($n, $s) => \App\Support\EmailStatus::label($s) . " {$n}")->implode(', ') : 'nothing to do') . '.');

    return 0;
})->purpose('Re-run e-mail verification for leads whose mailbox was never proven (default: MX-only)');
// v4: replaced by the autopilot discovery step (learns which ICP x source to search).
// Schedule::command('leadpilot:hunt')->weeklyOn(1, '06:00')->withoutOverlapping();

/*
|--------------------------------------------------------------------------
| v4 Autopilot commands
|--------------------------------------------------------------------------
*/
Artisan::command('leadpilot:autopilot {--dry-run : Plan only; no searches, sends or writes} {--unlock : Clear a cycle lock left behind by a killed run, then run}', function (\App\Services\Autopilot\AutopilotRunner $runner) {
    if ($this->option('unlock')) {
        \Illuminate\Support\Facades\Cache::lock(\App\Services\Autopilot\AutopilotRunner::LOCK)->forceRelease();
        $this->warn('Cycle lock cleared.');
    }
    $res = $runner->run((bool) $this->option('dry-run'));
    $this->line(json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
})->purpose('Run one autopilot cycle (discover, enrich, score, outreach, follow-ups, replies, monitoring)');

Artisan::command('leadpilot:doctor', function (\App\Services\Autopilot\HealthService $health) {
    $rows = array_map(fn ($c) => [strtoupper($c['status']), $c['label'], mb_substr($c['detail'], 0, 90), mb_substr((string) $c['fix'], 0, 70)], $health->checks());
    $this->table(['Status', 'Check', 'Detail', 'Fix'], $rows);
})->purpose('Setup & health report: environment, queue, scheduler, mail, providers');

Artisan::command('leadpilot:sync-replies', function (\App\Services\Mail\ReplySyncService $svc) {
    $this->line(json_encode($svc->sync(), JSON_PRETTY_PRINT));
})->purpose('Fetch prospect replies and bounces over IMAP');

Artisan::command('leadpilot:heartbeat', function (\App\Services\Autopilot\HealthService $health) {
    $health->beat('scheduler');
    \App\Jobs\QueueHeartbeatJob::dispatch();
})->purpose('Scheduler + queue worker liveness heartbeat');

Artisan::command('leadpilot:approvals-escalate', function (\App\Services\Autopilot\ApprovalService $svc) {
    $this->line(json_encode($svc->processOverdue()));
})->purpose('Escalate overdue approvals and re-notify');

Artisan::command('leadpilot:approval {id} {action : approve|reject|request_info|escalate} {--as= : Email of the deciding user} {--note=}', function (\App\Services\Autopilot\ApprovalService $svc) {
    $user = \App\Models\User::where('email', $this->option('as'))->where('is_active', true)->first();
    if (!$user) {
        $this->error('--as must be the email of an active user (the human who is deciding).');

        return 1;
    }
    $req = $svc->decide(\App\Models\ApprovalRequest::findOrFail($this->argument('id')), $user, $this->argument('action'), ['note' => $this->option('note')]);
    $this->info("Approval #{$req->id}: {$req->status}" . ($req->execution_result ? " — {$req->execution_result}" : ''));
})->purpose('Decide an approval from the command line (as a named human user)');

Artisan::command('leadpilot:validate-live {--icp= : ICP id (default: first active)} {--source= : only this source slug} {--limit=5} {--audits=5} {--pilot-to= : send ONE real test email to this ADMIN address}', function (\App\Services\Autopilot\LiveValidationRunner $runner) {
    $icp = $this->option('icp') ? \App\Models\IcpProfile::find($this->option('icp')) : \App\Models\IcpProfile::where('is_active', true)->orderBy('id')->first();
    $res = $runner->run($icp, ['source' => $this->option('source'), 'limit' => (int) $this->option('limit'), 'audits' => (int) $this->option('audits'), 'pilot_to' => $this->option('pilot-to')], fn ($l) => $this->line($l));
    $icon = ['pass' => '<info>PASS      </info>', 'warn' => '<comment>WARN      </comment>', 'fail' => '<error>FAIL      </error>', 'not_tested' => '<comment>NOT TESTED</comment>'];
    foreach ($res['sections'] as $s) {
        $this->line(($icon[$s['verdict'] ?? 'pass'] ?? $s['verdict']) . '  ' . $s['title'] . " ({$s['ms']} ms)");
    }
    $this->line('Verdicts: ' . json_encode($res['verdicts'] ?? []));
    $this->info('Costs: actual $' . $res['costs']['actual_usd'] . ' · estimated $' . $res['costs']['estimated_usd']);
    $this->info('Report: ' . implode(' and ', $res['report_files'] ?? []));
})->purpose('Run the real-data end-to-end validation and write a report');

// ---- v4 schedule (business timezone)
Schedule::command('leadpilot:heartbeat')->everyMinute()->timezone(config('leadpilot.timezone', 'Asia/Karachi'));
Schedule::job(new \App\Jobs\AutopilotCycleJob())->everyFifteenMinutes()->timezone(config('leadpilot.timezone', 'Asia/Karachi'))->withoutOverlapping();
Schedule::command('leadpilot:approvals-escalate')->everyThirtyMinutes()->timezone(config('leadpilot.timezone', 'Asia/Karachi'));

Artisan::command('leadpilot:setup', function (\App\Services\Autopilot\HealthService $health) {
    foreach ($health->applyDefaults() as $line) {
        $this->info('• ' . $line);
    }
    $this->call('leadpilot:doctor');
})->purpose('Apply safe recommended defaults and print the setup/health checklist');

Artisan::command('leadpilot:mail-test {to : Your own email address (must be an active admin user)}', function () {
    $to = strtolower(trim($this->argument('to')));
    if (!\App\Models\User::where('email', $to)->where('role', 'admin')->where('is_active', true)->exists()) {
        $this->error("Refused: {$to} is not an active admin user. Test emails only go to your own team.");

        return 1;
    }
    $cfg = config('mail.mailers.smtp');
    $this->line('Mailer: ' . config('mail.default') . " · host {$cfg['host']}:{$cfg['port']} · user " . ($cfg['username'] ?? '—') . ' · password ' . (empty($cfg['password']) ? 'MISSING' : 'set (' . strlen($cfg['password']) . ' chars' . (str_contains($cfg['password'], ' ') ? ', CONTAINS SPACES — remove them' : '') . ')') . ' · from ' . config('mail.from.address'));
    $t = microtime(true);
    try {
        $id = app(\App\Services\Outreach\OutreachMailer::class)->send($to, 'LeadPilot', '[LeadPilot] SMTP test ' . now(config('leadpilot.timezone'))->format('D H:i'), "This is a LeadPilot SMTP test.\n\nIf you can read this, outbound email works. Reply to it to test reply sync (php artisan leadpilot:sync-replies).");
        $status = in_array(config('mail.default'), ['log', 'array'], true) ? 'LOGGED ONLY (MAIL_MAILER=' . config('mail.default') . ', nothing left the server)' : 'ACCEPTED by the SMTP server';
        app(\App\Services\Integrations\ProviderHealthService::class)->success('smtp', true);
        $this->info("{$status} in " . (int) ((microtime(true) - $t) * 1000) . " ms. Message-ID: {$id}");
        $this->line('Accepted is not the same as delivered: check the inbox (and Spam) of ' . $to . '.');
    } catch (\Throwable $e) {
        app(\App\Services\Integrations\ProviderHealthService::class)->failure('smtp', $e->getMessage());
        $this->error('FAILED: ' . \App\Services\Integrations\ProviderHealthService::redact(mb_substr($e->getMessage(), 0, 300)));
        $this->warn('Diagnosis: ' . app(\App\Services\Autopilot\LiveValidationRunner::class)->smtpHint($e->getMessage()));

        return 1;
    }
})->purpose('Send ONE SMTP test email to your own admin address and report the real provider result');
