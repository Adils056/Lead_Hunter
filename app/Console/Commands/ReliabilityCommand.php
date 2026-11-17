<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * One read-only health check of the live system for the weekly review: errors in the log, failed jobs, open incidents,
 * work waiting for a person (approvals, overdue tasks, replies nobody answered), the sending pipeline (e-mail and
 * WhatsApp by day, refusals), and research quality (audit failures, verification). Counts and short labels only:
 * no e-mail addresses, and message text cut to 70 characters, so the output can be pasted anywhere.
 */
class ReliabilityCommand extends Command
{
    protected $signature = 'leadpilot:reliability {--days=8 : Look back this many days}';

    protected $description = 'Read-only reliability check: errors, stuck work, unanswered replies, sending and research health (JSON)';

    public function handle(): int
    {
        $out = [];
        $since = now()->subDays(max(1, (int) $this->option('days')));
        $out = [];

        // 1. Application errors in the log in the period, grouped
        $errors = [];
        foreach (glob(storage_path('logs/*.log')) as $f) {
            $h = fopen($f, 'r');
            $size = filesize($f);
            fseek($h, max(0, $size - 20 * 1024 * 1024));
            while (($line = fgets($h)) !== false) {
                if (preg_match('/^\[(\d{4}-\d{2}-\d{2}) [\d:]+\] \w+\.(ERROR|CRITICAL|ALERT|EMERGENCY): (.{0,90})/', $line, $m) && $m[1] >= $since->toDateString()) {
                    $key = preg_replace('/\d+/', '#', $m[3]);
                    $errors[$key] = ($errors[$key] ?? 0) + 1;
                }
            }
            fclose($h);
        }
        arsort($errors);
        $out['log_errors_period_total'] = array_sum($errors);
        $out['log_errors_top'] = array_slice($errors, 0, 10, true);

        // 2. Failed background jobs and incidents
        $out['failed_jobs_period'] = \Illuminate\Support\Facades\DB::table('failed_jobs')->where('failed_at', '>=', $since)->count();
        $out['failed_jobs_top'] = \Illuminate\Support\Facades\DB::table('failed_jobs')->where('failed_at', '>=', $since)->get(['exception'])
            ->map(fn ($j) => mb_substr(strtok((string) $j->exception, "\n"), 0, 90))->countBy()->sortDesc()->take(5)->all();
        $out['incidents_open'] = \App\Models\Incident::where('status', '<>', 'resolved')->get(['title', 'severity', 'created_at'])
            ->map(fn ($i) => $i->severity . ' | ' . mb_substr($i->title, 0, 70) . ' | ' . $i->created_at->diffForHumans())->all();
        $out['incidents_period'] = \App\Models\Incident::where('created_at', '>=', $since)->count();

        // 3. Work waiting for a person
        $open = \App\Models\ApprovalRequest::open()->get(['type', 'created_at']);
        $out['approvals_open_by_type'] = $open->countBy('type')->all();
        $out['approvals_oldest_hours'] = $open->min('created_at') ? (int) $open->min('created_at')->diffInHours(now()) : 0;
        $tasks = \App\Models\Task::where('status', 'pending')->where('due_date', '<', now()->toDateString())->with('lead.company')->get();
        $out['tasks_overdue'] = $tasks->count();
        $out['tasks_overdue_sample'] = $tasks->sortBy('due_date')->take(8)->map(fn ($t) => mb_substr($t->title, 0, 45) . ' | ' . ($t->lead?->company?->name ?? '-') . ' | due ' . $t->due_date)->values()->all();

        // 4. Replies from people and whether anyone answered
        $hot = \App\Models\InboundReply::whereIn('category', ['interested', 'request_info', 'referral', 'uncertain', 'objection'])->where('received_at', '>=', now()->subDays(30))->with('lead.company')->get();
        $out['email_replies_30d'] = $hot->map(function ($r) {
            $answered = \App\Models\CampaignRecipient::where('lead_id', $r->lead_id)->where('sent_at', '>', $r->received_at)->exists()
                || \App\Models\EmailLog::where('lead_id', $r->lead_id)->where('direction', 'outbound')->where('occurred_at', '>', $r->received_at)->exists();
            return $r->category . ' | ' . ($r->lead?->company?->name ?? '-') . ' | ' . $r->received_at?->diffForHumans() . ' | reviewed ' . ($r->is_reviewed ? 'yes' : 'no') . ' | answered by e-mail ' . ($answered ? 'yes' : 'no');
        })->values()->all();
        $out['whatsapp_real_replies_30d'] = \App\Models\WhatsappInboundMessage::where('action', 'reply')->where('created_at', '>=', now()->subDays(30))->with('lead.company')->get()
            ->map(fn ($m) => ($m->lead?->company?->name ?? '-') . ' | ' . $m->created_at->diffForHumans() . ' | ' . $m->lead?->lifecycle_stage)->values()->all();
        $out['leads_by_stage'] = \App\Models\Lead::selectRaw('lifecycle_stage s, count(*) n')->whereIn('lifecycle_stage', ['replied', 'interested', 'meeting_booked', 'proposal_sent', 'negotiation', 'won', 'lost'])->groupBy('s')->pluck('n', 's')->all();
        $out['meetings'] = \App\Models\Meeting::count();
        $out['deals'] = \App\Models\Deal::selectRaw('stage, count(*) n')->groupBy('stage')->pluck('n', 'stage')->all();

        // 5. Sending pipeline health
        $out['email_recipients_by_status'] = \App\Models\CampaignRecipient::selectRaw('status, count(*) n')->groupBy('status')->pluck('n', 'status')->all();
        $out['emails_sent_by_day_period'] = \App\Models\CampaignRecipient::where('sent_at', '>=', $since)->get(['sent_at'])->countBy(fn ($r) => $r->sent_at->format('m-d'))->sortKeys()->all();
        $out['email_failed_period_top'] = \App\Models\CampaignRecipient::where('status', 'failed')->where('updated_at', '>=', $since)->get(['error_message'])->map(fn ($r) => mb_substr((string) $r->error_message, 0, 80))->countBy()->sortDesc()->take(5)->all();
        $out['whatsapp_by_day_period'] = \App\Models\WhatsappMessage::where('created_at', '>=', $since)->get(['created_at', 'status'])->groupBy(fn ($m) => $m->created_at->format('m-d'))->map(fn ($g) => $g->countBy('status')->all())->sortKeys()->all();
        $out['whatsapp_refused_reasons'] = \App\Models\WhatsappMessage::where('status', 'failed')->get(['provider_response'])->map(fn ($m) => mb_substr((string) $m->provider_response, 0, 80))->countBy()->sortDesc()->take(5)->all();
        $out['whatsapp_inbound_by_action'] = \App\Models\WhatsappInboundMessage::selectRaw('action, count(*) n')->groupBy('action')->pluck('n', 'action')->all();

        // 6. Research quality
        $out['audits_period'] = \App\Models\WebsiteAudit::where('created_at', '>=', $since)->selectRaw('status, count(*) n')->groupBy('status')->pluck('n', 'status')->all();
        $out['audit_fail_reasons_period'] = \App\Models\WebsiteAudit::where('created_at', '>=', $since)->where('status', 'failed')->get(['summary'])->map(fn ($a) => mb_substr(preg_replace('/\d+/', '#', (string) $a->summary), 0, 70))->countBy()->sortDesc()->take(6)->all();
        $out['email_verification'] = \App\Models\Lead::whereNotNull('email')->selectRaw('email_verification_status s, count(*) n')->groupBy('s')->pluck('n', 's')->all();
        $out['discovery_runs_period'] = \App\Models\DiscoveryRun::where('created_at', '>=', $since)->selectRaw('status, count(*) n')->groupBy('status')->pluck('n', 'status')->all();
        $out['provider_health'] = \App\Models\ProviderHealth::get()->map(fn ($p) => $p->provider . ': ' . ($p->status ?? '?') . ' | fails in a row ' . (int) $p->consecutive_failures . ($p->last_error ? ' | ' . mb_substr((string) $p->last_error, 0, 70) : ''))->all();

        $this->line(json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return 0;
    }
}
