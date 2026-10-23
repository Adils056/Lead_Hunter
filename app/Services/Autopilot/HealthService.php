<?php

namespace App\Services\Autopilot;

use App\Models\ApprovalRequest;
use App\Models\CampaignRecipient;
use App\Models\Incident;
use App\Models\NotificationDelivery;
use App\Models\OutreachSetting;
use App\Models\SystemHeartbeat;
use App\Services\Integrations\CostLedger;
use App\Services\Integrations\ProviderRegistry;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Setup + health checks ("doctor"): environment, DB, queue, scheduler, mail, providers, policy sanity.
 * Each check: [key, label, status ok|warn|fail, detail, fix]. Also raises Level-3 incidents for
 * stalled workers, bounce/complaint spikes, spend overruns and notification failures.
 */
class HealthService
{
    public function beat(string $component, array $meta = []): void
    {
        SystemHeartbeat::updateOrCreate(['component' => $component], ['beat_at' => now(), 'meta' => $meta]);
    }

    public function checks(): array
    {
        $c = [];
        $add = function ($key, $label, $status, $detail, $fix = null) use (&$c) {
            $c[] = compact('key', 'label', 'status', 'detail', 'fix');
        };

        // Environment
        $add('app_key', 'Application key', config('app.key') ? 'ok' : 'fail', config('app.key') ? 'Set.' : 'APP_KEY missing.', 'php artisan key:generate');
        $add('app_url', 'Public URL (used in links/unsubscribe)', str_contains((string) config('app.url'), 'localhost') ? 'warn' : 'ok', (string) config('app.url'),
            'Set APP_URL to the address prospects can reach, otherwise unsubscribe links in emails will not work.');
        $add('timezone', 'Business timezone', 'ok', config('leadpilot.timezone') . ' (storage in ' . config('app.timezone') . ')');

        try {
            DB::select('select 1');
            $add('database', 'Database', 'ok', config('database.default') . ' connected.');
        } catch (\Throwable $e) {
            $add('database', 'Database', 'fail', mb_substr($e->getMessage(), 0, 200), 'Check DB_* in .env and that MySQL is running.');
        }
        try {
            Cache::put('lp_health', 1, 10);
            $add('cache', 'Cache', Cache::get('lp_health') ? 'ok' : 'fail', config('cache.default'));
        } catch (\Throwable $e) {
            $add('cache', 'Cache', 'fail', $e->getMessage());
        }

        // Queue + scheduler heartbeats
        $q = config('queue.default');
        $add('queue_driver', 'Queue driver', $q === 'sync' ? 'warn' : 'ok', $q, $q === 'sync' ? 'Use QUEUE_CONNECTION=database so hunts/audits do not block web requests.' : null);
        $stale = (int) config('leadpilot.incidents.heartbeat_stale_minutes', 15);
        foreach (['scheduler' => 'Scheduler (schedule:run every minute)', 'queue_worker' => 'Queue worker (queue:work)'] as $comp => $label) {
            $hb = SystemHeartbeat::where('component', $comp)->first();
            $ok = $hb && $hb->beat_at->gt(now()->subMinutes($stale));
            $add("hb_{$comp}", $label, $ok ? 'ok' : ($hb ? 'fail' : 'warn'), $hb ? 'Last heartbeat ' . $hb->beat_at->diffForHumans() : 'No heartbeat yet.',
                $comp === 'scheduler' ? 'Windows: run deploy\\windows\\install_tasks.ps1 (Task Scheduler). Linux: cron * * * * * php artisan schedule:run' : 'Run the queue worker under Task Scheduler/NSSM (Windows) or Supervisor (Linux).');
        }
        try {
            $failed = DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count();
            $add('failed_jobs', 'Failed jobs (24h)', $failed ? 'warn' : 'ok', "{$failed} failed", $failed ? 'php artisan queue:failed — inspect, then queue:retry all' : null);
        } catch (\Throwable) {
        }

        // Mail
        $mailer = config('mail.default');
        $add('mail', 'Outbound email', in_array($mailer, ['log', 'array'], true) ? 'warn' : 'ok',
            "{$mailer}" . ($mailer === 'smtp' ? ' via ' . config('mail.mailers.smtp.host') : ''),
            in_array($mailer, ['log', 'array'], true) ? 'Set MAIL_MAILER=smtp to actually send.' : null);
        $from = (string) config('mail.from.address');
        $add('mail_from', 'Sender address', $from && !str_ends_with($from, '.test') ? 'ok' : 'fail', $from ?: 'missing');
        if (str_ends_with($from, '@gmail.com')) {
            $add('gmail_limits', 'Gmail sending limits', 'warn', 'Personal Gmail allows ~500 emails/day and has no custom-domain SPF/DKIM/DMARC alignment.',
                'For outreach, use a Google Workspace/own-domain mailbox with SPF, DKIM and DMARC.');
        }

        // Outreach settings sanity
        $s = OutreachSetting::current();
        $add('outreach_mode', 'Outreach mode', $s->isEmergencyStopped() ? 'warn' : 'ok', $s->mode . ($s->isEmergencyStopped() ? ' — EMERGENCY STOP ON' : ''));
        if (($s->reply_to_email ?? '') === 'sales@leadpilot.test') {
            $add('reply_to', 'Reply-To address', 'fail', 'Still the demo value sales@leadpilot.test — prospect replies would be lost.', 'Settings → Outreach Engine → Reply-To email.');
        }
        $icpServices = \App\Models\IcpProfile::where('is_active', true)->get()->flatMap(fn ($i) => $i->list('target_services'))->unique();
        $missing = $icpServices->diff((array) $s->approved_services)->values()->all();
        if ($missing) {
            $add('approved_services', 'Approved outreach services', 'warn', 'Active ICPs target services not approved for outreach: ' . implode(', ', $missing), 'Settings → Outreach Engine → Approved services.');
        }

        // Providers
        foreach (app(ProviderRegistry::class)->statusReport() as $slug => $p) {
            $essential = in_array($slug, ['smtp', 'imap', 'osm_overpass'], true);
            $st = match (true) {
                !$p['configured'] => $essential ? 'warn' : 'info',                 // optional providers are not problems
                in_array($p['status'], ['disabled', 'degraded'], true) => 'fail',
                (bool) $p['verified_at'] => 'ok',
                default => $essential ? 'warn' : 'info',                           // keyless sources verify on first real use
            };
            $detail = !$p['configured'] ? 'Not configured.' : ($p['verified_at'] ? 'Verified by a real call ' . $p['verified_at']->diffForHumans() : 'Configured, not yet verified by a real call.')
                . ($p['last_error'] && $p['status'] !== 'healthy' ? ' Last error: ' . mb_substr($p['last_error'], 0, 160) : '');
            $add("provider_{$slug}", $p['label'], $st, $detail, $p['configured'] ? null : $p['setup']);
        }

        return $c;
    }

    /** Level-3 monitors. Returns incidents raised this pass. */
    public function monitor(): array
    {
        $raised = [];
        $inc = app(IncidentService::class);
        $cfg = config('leadpilot.incidents');

        $recent = CampaignRecipient::whereNotNull('sent_at')->latest('sent_at')->limit($cfg['window_sends'])->get(['status', 'bounce_type']);
        if ($recent->count() >= 20) {
            $hard = $recent->where('bounce_type', 'hard')->count() / $recent->count();
            $complaints = $recent->where('bounce_type', 'complaint')->count() / $recent->count();
            if ($hard > $cfg['bounce_rate_max']) {
                $raised[] = $inc->raise('bounce_spike', 'Bounce rate too high', sprintf('%.1f%% hard bounces in the last %d sends (limit %.1f%%). Sending paused to protect your domain.', $hard * 100, $recent->count(), $cfg['bounce_rate_max'] * 100), 'outreach', ['rate' => round($hard, 4)]);
            }
            if ($complaints > $cfg['complaint_rate_max']) {
                $raised[] = $inc->raise('complaint_spike', 'Spam complaints detected', sprintf('%.2f%% complaints in the last %d sends (Gmail limit 0.3%%).', $complaints * 100, $recent->count()), 'outreach', ['rate' => round($complaints, 4)]);
            }
        }

        $stale = (int) $cfg['heartbeat_stale_minutes'];
        foreach (['scheduler' => 'scheduler_stalled', 'queue_worker' => 'queue_stalled'] as $comp => $type) {
            $hb = SystemHeartbeat::where('component', $comp)->first();
            if ($hb && $hb->beat_at->lt(now()->subMinutes($stale))) {
                $raised[] = $inc->raise($type, ucfirst(str_replace('_', ' ', $comp)) . ' is not running', "Last heartbeat {$hb->beat_at->diffForHumans()}. Background work (hunts, follow-ups, reply sync) is not happening.", null, ['last' => (string) $hb->beat_at]);
            }
        }

        $global = (int) config('leadpilot.budgets.global_daily', 0);
        $spent = app(CostLedger::class)->spentCents(null, 'daily');
        if ($global > 0 && $spent > $global) {
            $raised[] = $inc->raise('spend_exceeded', 'Daily spend over budget', sprintf('Spent $%.2f today vs budget $%.2f. Paid operations paused.', $spent / 100, $global / 100), null, ['spent_cents' => $spent]);
        }

        $failedNotes = NotificationDelivery::where('status', 'failed')->where('created_at', '>=', now()->subHour())->count();
        if ($failedNotes >= (int) $cfg['notification_failures_max']) {
            $raised[] = $inc->raise('notification_failures', 'Notifications are failing', "{$failedNotes} notification deliveries failed in the last hour. People may not be seeing approval requests.", null, ['failed' => $failedNotes]);
        }

        return $raised;
    }

    public function summary(): array
    {
        return [
            'open_incidents' => Incident::whereIn('status', ['open', 'acknowledged'])->count(),
            'pending_approvals' => ApprovalRequest::open()->count(),
            'overdue_approvals' => ApprovalRequest::open()->where('due_at', '<', now())->count(),
        ];
    }

    /**
     * Safe, reversible defaults (no credentials, no billing, no external accounts).
     * @return string[] what was changed
     */
    public function applyDefaults(): array
    {
        $done = [];
        if (\App\Models\IcpProfile::count() === 0) {
            (new \Database\Seeders\IcpProfileSeeder())->run();
            $done[] = 'Created starter Ideal Customer Profiles (local clinics in Islamabad/Rawalpindi; UK tenders).';
        }
        $s = OutreachSetting::current();
        $from = (string) config('mail.from.address');
        if (in_array($s->reply_to_email, [null, '', 'sales@leadpilot.test'], true) && $from && !str_ends_with($from, '.test')) {
            $s->update(['reply_to_email' => $from]);
            $done[] = "Reply-To set to {$from} so prospect replies reach your mailbox.";
        }
        if (in_array($s->sender_email, [null, '', 'sales@leadpilot.test'], true) && $from && !str_ends_with($from, '.test')) {
            $s->update(['sender_email' => $from]);
            $done[] = "Sender email set to {$from}.";
        }
        $wanted = \App\Models\IcpProfile::where('is_active', true)->get()->flatMap(fn ($i) => $i->list('target_services'))->unique()->values()->all();
        $missing = array_values(array_diff($wanted, (array) $s->approved_services));
        if ($missing) {
            $s->update(['approved_services' => array_values(array_unique(array_merge((array) $s->approved_services, $missing)))]);
            $done[] = 'Approved outreach services your ICPs target: ' . implode(', ', $missing) . '.';
        }
        if (!$s->sending_timezone || $s->sending_timezone === 'UTC') {
            $s->update(['sending_timezone' => config('leadpilot.timezone')]);
            $done[] = 'Sending timezone set to ' . config('leadpilot.timezone') . '.';
        }

        return $done ?: ['Nothing to change — defaults already applied.'];
    }
}
