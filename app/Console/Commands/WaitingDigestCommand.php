<?php

namespace App\Console\Commands;

use App\Models\EmailLog;
use App\Models\InboundReply;
use App\Models\Task;
use App\Models\User;
use App\Services\Autopilot\Notifier;
use Illuminate\Console\Command;

/**
 * Every morning, one message to each active admin (and to the salesperson who owns a lead) listing the people
 * waiting for an answer from us: e-mail replies nobody answered, and the "answer now" / "follow up today" tasks that
 * LeadPilot created when someone replied on WhatsApp or opened their report and that are still open.
 * Seen live on 17 Nov: an interested reply had waited 11 days and 12 such tasks were overdue, while meetings stayed at 0.
 * Sent on the instant channels too (WhatsApp, SMS, webhook), so it is seen; nothing is sent when nobody is waiting.
 */
class WaitingDigestCommand extends Command
{
    protected $signature = 'leadpilot:waiting-digest {--dry-run : Print the digest without sending it}';

    protected $description = 'Morning list of prospects waiting for an answer (unanswered replies, open answer-now tasks), sent to the team';

    public const ANSWER_CATEGORIES = ['interested', 'request_info', 'referral', 'uncertain', 'objection'];

    public function handle(Notifier $notifier): int
    {
        $items = collect();

        // e-mail replies from people that nobody answered by e-mail since
        foreach (InboundReply::with('lead.company')->whereIn('category', self::ANSWER_CATEGORIES)->where('received_at', '>=', now()->subDays(30))->get() as $r) {
            if (!$r->lead || in_array($r->lead->lifecycle_stage, ['won', 'lost', 'not_a_fit', 'opted_out'], true)) {
                continue;
            }
            $answered = EmailLog::where('lead_id', $r->lead_id)->where('direction', 'outbound')->where('occurred_at', '>', $r->received_at)->exists()
                || \App\Models\CampaignRecipient::where('lead_id', $r->lead_id)->where('sent_at', '>', $r->received_at)->exists();
            if (!$answered) {
                $items->push(['lead' => $r->lead, 'what' => 'replied by e-mail (' . str_replace('_', ' ', $r->category) . ')', 'since' => $r->received_at]);
            }
        }
        // the tasks LeadPilot made when someone engaged, still open
        foreach (Task::with('lead.company')->where('status', 'pending')->whereDate('due_date', '<=', now()->toDateString())
            ->where(fn ($q) => $q->where('title', 'like', '%answer now%')->orWhere('title', 'like', '%respond today%')->orWhere('title', 'like', '%follow up today%'))->get() as $t) {
            if ($t->lead && !$items->contains(fn ($i) => $i['lead']->id === $t->lead_id)) {
                $items->push(['lead' => $t->lead, 'what' => mb_strtolower(explode(':', $t->title)[0]), 'since' => $t->created_at]);
            }
        }

        if ($items->isEmpty()) {
            $this->info('Nobody is waiting for an answer.');

            return 0;
        }
        $items = $items->sortBy('since')->values();
        $line = fn ($i) => ($i['lead']->company?->name ?? $i['lead']->full_name) . ': ' . $i['what'] . ', waiting ' . $i['since']->diffForHumans(null, true);
        $list = $items->take(10)->map($line)->implode("\n");
        $count = $items->count();
        $payload = [
            'title' => "{$count} prospect" . ($count === 1 ? ' is' : 's are') . ' waiting for your answer',
            'priority' => 'high',
            'what' => $list . ($count > 10 ? "\n…and " . ($count - 10) . ' more' : ''),
            'why' => 'These people answered us or opened their report. Every day without an answer makes a meeting less likely; none has been booked yet.',
            'recommended' => 'Answer the oldest first, then mark the task done',
            'url' => route('crm.pipeline'),
            'short' => "LeadPilot: {$count} prospect(s) waiting for your answer. Oldest: " . $line($items->first()) . '. ' . route('whatsapp.index', ['tab' => 'replies']),
        ];

        $this->line($payload['title']);
        $this->line($payload['what']);
        if ($this->option('dry-run')) {
            return 0;
        }
        $users = User::where('is_active', true)->where('role', 'admin')->get()
            ->merge(User::where('is_active', true)->whereIn('id', $items->pluck('lead.assigned_user_id')->filter()->unique())->get())->unique('id');
        foreach ($users as $u) {
            $notifier->notifyUser($u, $payload + ['urgent' => true], 'waiting-digest:' . now()->format('Ymd'));
        }
        $this->info('Sent to ' . $users->count() . ' user(s).');

        return 0;
    }
}
