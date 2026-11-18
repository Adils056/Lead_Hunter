<?php

namespace App\Services\Sales;

use App\Models\CampaignRecipient;
use App\Models\EmailLog;
use App\Models\InboundReply;
use App\Models\Task;
use Illuminate\Support\Collection;

/**
 * The prospects waiting for an answer from us: e-mail replies nobody answered by e-mail since, and the "answer now" /
 * "follow up today" tasks LeadPilot created when someone replied on WhatsApp or opened their report, still open.
 * Used by the morning list (leadpilot:waiting-digest) and the 24-hour escalation (leadpilot:escalate-waiting).
 */
class WaitingList
{
    public const ANSWER_CATEGORIES = ['interested', 'request_info', 'referral', 'uncertain', 'objection'];

    /** @return Collection<int, array{lead: \App\Models\Lead, what: string, since: \Carbon\Carbon, kind: string, key: string}> oldest first */
    public function items(): Collection
    {
        $items = collect();
        foreach (InboundReply::with('lead.company')->whereIn('category', self::ANSWER_CATEGORIES)->where('received_at', '>=', now()->subDays(30))->get() as $r) {
            if (!$r->lead || in_array($r->lead->lifecycle_stage, ['meeting_booked', 'proposal_sent', 'negotiation', 'won', 'lost', 'not_a_fit', 'opted_out'], true)) {
                continue;
            }
            $answered = EmailLog::where('lead_id', $r->lead_id)->where('direction', 'outbound')->where('occurred_at', '>', $r->received_at)->exists()
                || CampaignRecipient::where('lead_id', $r->lead_id)->where('sent_at', '>', $r->received_at)->exists();
            if (!$answered) {
                $items->push(['lead' => $r->lead, 'what' => 'replied by e-mail (' . str_replace('_', ' ', $r->category) . ')', 'since' => $r->received_at,
                    'kind' => 'reply', 'key' => "email-reply:{$r->id}"]);
            }
        }
        foreach (Task::with('lead.company')->where('status', 'pending')->whereDate('due_date', '<=', now()->toDateString())
            ->where(fn ($q) => $q->where('title', 'like', '%answer now%')->orWhere('title', 'like', '%respond today%')->orWhere('title', 'like', '%follow up today%'))->get() as $t) {
            if ($t->lead && !$items->contains(fn ($i) => $i['lead']->id === $t->lead_id)) {
                $items->push(['lead' => $t->lead, 'what' => mb_strtolower(explode(':', $t->title)[0]), 'since' => $t->created_at,
                    'kind' => str_contains(mb_strtolower($t->title), 'repl') ? 'reply' : 'report', 'key' => "task:{$t->id}"]);
            }
        }

        return $items->sortBy('since')->values();
    }

    public static function line(array $i): string
    {
        return ($i['lead']->company?->name ?? $i['lead']->full_name) . ': ' . $i['what'] . ', waiting ' . $i['since']->diffForHumans(null, true);
    }
}
