<?php

namespace App\Services\Outreach;

use App\Models\CampaignRecipient;
use App\Models\DataProvenance;
use App\Models\InboundReply;
use App\Models\Lead;
use App\Models\Meeting;
use App\Support\EmailStatus;
use App\Support\OutreachMetrics;
use Carbon\Carbon;

/**
 * The Monday review: what went out last week, what came back, which kind of lead actually answers (own data
 * against the benchmark), how much the lead pool grew, and where to focus this week. Real numbers only.
 */
class WeeklyReview
{
    public function __construct(protected WinChance $chance)
    {
    }

    public function build(?Carbon $end = null): array
    {
        $end = ($end ?? now())->copy();
        $from = $end->copy()->subDays(7);
        $inWeek = fn ($q, string $col = 'created_at') => $q->where($col, '>=', $from)->where($col, '<', $end);

        $sent = $inWeek(CampaignRecipient::whereNotNull('sent_at'), 'sent_at');
        $answers = $inWeek(InboundReply::query(), 'received_at')->get(['lead_id', 'category'])
            ->reject(fn ($r) => in_array($r->category, OutreachMetrics::NOT_A_REPLY, true));
        $activity = [
            'first_emails' => (clone $sent)->where('sequence_step', 1)->count(),
            'follow_ups' => (clone $sent)->where('sequence_step', '>', 1)->count(),
            'bounced' => (clone $sent)->where(fn ($q) => $q->whereNotNull('bounced_at')->orWhere('status', 'bounced'))->count(),
            'replies' => $answers->pluck('lead_id')->unique()->count(),
            'interested' => $answers->filter(fn ($r) => in_array($r->category, OutreachMetrics::POSITIVE, true))->pluck('lead_id')->unique()->count(),
            'meetings' => $inWeek(Meeting::query())->count(),
        ];

        $learned = $this->chance->learned();
        $segments = [];
        foreach (WinChance::SEGMENTS as $key => $b) {
            $own = $learned[$key] ?? ['sent' => 0, 'replied' => 0, 'interested' => 0];
            $enough = $own['sent'] >= WinChance::OWN_DATA_MIN;
            $reply = $own['sent'] ? round(100 * $own['replied'] / $own['sent'], 1) : null;
            $segments[] = [
                'key' => $key, 'label' => $b['label'], 'sent' => $own['sent'], 'replied' => $own['replied'], 'interested' => $own['interested'],
                'reply_rate' => $reply, 'interested_rate' => $own['sent'] ? round(100 * $own['interested'] / $own['sent'], 1) : null,
                'benchmark_reply' => round($b['reply'] * 100, 1), 'benchmark_interested' => round($b['interested'] * 100, 1),
                'verdict' => !$enough ? 'too_early' : ($reply >= $b['reply'] * 100 ? 'beats_benchmark' : 'below_benchmark'),
                'ready' => Lead::where('win_segment', $key)->whereIn('win_tier', ['A', 'B'])->count(),
            ];
        }

        $growth = [
            'new_leads' => $inWeek(Lead::query())->count(),
            'by_source' => $inWeek(Lead::query())->selectRaw('source_name, count(*) as n')->groupBy('source_name')->orderByDesc('n')->limit(5)->pluck('n', 'source_name')->all(),
            'websites_found' => $inWeek(DataProvenance::where('source', 'website_finder')->where('field', 'website'))->count(),
            'named_contacts' => $inWeek(Lead::where('contact_status', 'identified'))->count(),
            'emails_proven' => $inWeek(Lead::where('email_verification_status', EmailStatus::VERIFIED), 'last_verified_at')->count(),
        ];

        $ladder = array_fill_keys(WinLadderService::ORDER, 0);
        foreach (Lead::selectRaw('win_tier, count(*) as n')->whereNotNull('win_tier')->groupBy('win_tier')->pluck('n', 'win_tier') as $t => $n) {
            $ladder[$t] = (int) $n;
        }

        return ['from' => $from, 'end' => $end, 'activity' => $activity, 'segments' => $segments, 'growth' => $growth, 'ladder' => $ladder,
            'focus' => $this->focus($segments, $ladder)];
    }

    /** One sentence: where the next week's effort pays most. */
    protected function focus(array $segments, array $ladder): string
    {
        $proven = collect($segments)->where('verdict', 'beats_benchmark')->sortByDesc('reply_rate')->first();
        if ($proven) {
            return "\"{$proven['label']}\" replies at {$proven['reply_rate']}% on your own data (benchmark {$proven['benchmark_reply']}%): find more of these; {$proven['ready']} are in rungs A/B now.";
        }
        $best = collect($segments)->where('ready', '>', 0)->sortByDesc('benchmark_reply')->first();
        if ($best) {
            return "Too early for your own rates (fewer than " . WinChance::OWN_DATA_MIN . " sends per kind). Best bet: \"{$best['label']}\" (benchmark {$best['benchmark_reply']}% reply), {$best['ready']} leads in rungs A/B.";
        }

        return "No lead is in rungs A/B yet: {$ladder['C']} need a contact. The nightly website and decision-maker finders work on these.";
    }

    /** Plain-text summary for the notification / e-mail. */
    public function summary(array $r): string
    {
        $a = $r['activity'];
        $g = $r['growth'];

        return "Last 7 days: {$a['first_emails']} first emails, {$a['follow_ups']} follow-ups, {$a['replies']} replies ({$a['interested']} interested), {$a['meetings']} meetings, {$a['bounced']} bounced. "
            . "Lead pool: +{$g['new_leads']} leads, {$g['websites_found']} websites found, {$g['named_contacts']} named decision-makers, {$g['emails_proven']} emails proven. "
            . "Rung A (email now): {$r['ladder']['A']}. Focus: {$r['focus']}";
    }
}
