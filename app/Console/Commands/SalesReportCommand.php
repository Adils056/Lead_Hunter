<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\Meeting;
use App\Models\RadarItem;
use App\Models\WebsiteAudit;
use App\Services\Outreach\WeeklyReview;
use App\Support\EmailStatus;
use App\Support\OutreachMetrics;
use Illuminate\Console\Command;

/**
 * The owner's one-screen sales report: how many leads the system found, how far they got (emailed, replied,
 * interested, meeting, won), the real win rate at each step, and the system's own predicted win chance for the
 * leads waiting. Counts only — no names or e-mail addresses — so the output can be pasted anywhere.
 */
class SalesReportCommand extends Command
{
    protected $signature = 'leadpilot:sales-report {--days=30 : Also show the last N days} {--json : Print JSON only}';

    protected $description = 'Lead funnel and win rates (found → emailed → replied → interested → meeting → won), real and predicted';

    public function handle(WeeklyReview $review): int
    {
        $r = $this->build((int) $this->option('days'), $review);
        if ($this->option('json')) {
            $this->line(json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return 0;
        }

        $this->info("LeadPilot sales report · {$r['generated_at']}");
        $this->table(['Leads found', 'All time', "Last {$r['days']} days"], collect($r['found'])->map(fn ($v, $k) => [str_replace('_', ' ', $k), $v['all'], $v['recent']])->values()->all());
        $this->table(['Funnel (e-mailed prospects)', 'All time', '% of emailed', "Last {$r['days']} days"], collect($r['funnel'])->map(fn ($v, $k) => [str_replace('_', ' ', $k), $v['all'], $v['rate'] === null ? '–' : $v['rate'] . '%', $v['recent']])->values()->all());
        $this->table(['Win rate', 'Value'], collect($r['win_rates'])->map(fn ($v, $k) => [str_replace('_', ' ', $k), $v === null ? 'not enough data' : (str_contains($k, '(count)') ? $v : $v . '%')])->values()->all());
        $this->table(['Win Ladder rung', 'Leads', 'Avg reply %', 'Avg interested %', 'Avg deal %'], collect($r['ladder'])->map(fn ($v, $k) => [$k . ' · ' . $v['label'], $v['leads'], $v['reply'], $v['interested'], $v['deal']])->values()->all());
        $this->line("Predicted deals from leads now on rungs A+B: {$r['predicted']['deals_from_A_B']} (from {$r['predicted']['leads_A_B']} leads; system estimate, not a promise)");
        $this->table(['Evidence segment', 'Emailed', 'Replied', 'Interested', 'Your reply %', 'Benchmark reply %', 'Verdict'],
            array_map(fn ($s) => [$s['label'], $s['sent'], $s['replied'], $s['interested'], $s['reply_rate'] ?? '–', $s['benchmark_reply'], $s['verdict']], $r['segments']));
        $this->table(['Source', 'Leads', 'Emailed', 'Replied', 'Won'], array_map(fn ($s) => [$s['source'], $s['leads'], $s['emailed'], $s['replied'], $s['won']], $r['by_source']));
        $this->table(['WhatsApp', 'All time', "Last {$r['days']} days"], collect($r['whatsapp'])->map(fn ($v, $k) => [str_replace('_', ' ', $k), $v['all'] . (str_contains($k, 'rate') ? '%' : ''), $v['recent'] . (str_contains($k, 'rate') ? '%' : '')])->values()->all());
        $this->table(['Other', 'Value'], collect($r['other'])->map(fn ($v, $k) => [str_replace('_', ' ', $k), is_array($v) ? json_encode($v) : $v])->values()->all());

        return 0;
    }

    public function build(int $days, WeeklyReview $review): array
    {
        $since = now()->subDays(max(1, $days));
        $pair = fn (\Closure $q) => ['all' => $q(null), 'recent' => $q($since)];
        $leads = fn ($from) => Lead::query()->when($from, fn ($q) => $q->where('created_at', '>=', $from));
        $sendable = EmailStatus::sendableStatuses(\App\Models\OutreachSetting::current());

        $found = [
            'leads' => $pair(fn ($f) => $leads($f)->count()),
            'with_email' => $pair(fn ($f) => $leads($f)->whereNotNull('email')->count()),
            'email_proven' => $pair(fn ($f) => $leads($f)->where('email_verification_status', EmailStatus::VERIFIED)->count()),
            'sendable_email' => $pair(fn ($f) => $leads($f)->whereIn('email_verification_status', $sendable)->count()),
            'named_person' => $pair(fn ($f) => $leads($f)->where('contact_status', 'identified')->count()),
            'score_65_plus' => $pair(fn ($f) => $leads($f)->where('overall_score', '>=', 65)->count()),
            'companies' => $pair(fn ($f) => Company::query()->when($f, fn ($q) => $q->where('created_at', '>=', $f))->count()),
        ];

        $all = OutreachMetrics::summary();
        $recent = OutreachMetrics::summary($since);
        // the outreach funnel follows prospects LeadPilot e-mailed; each step counts those that reached at least that step
        // (a won lead also had a meeting and a proposal). Deals from other channels are counted separately below.
        $emailedSince = fn ($from) => \App\Models\CampaignRecipient::whereNotNull('sent_at')->when($from, fn ($q) => $q->where('sent_at', '>=', $from))->distinct()->pluck('lead_id');
        $reached = fn (array $stages) => Lead::whereIn('lifecycle_stage', $stages)->pluck('id');
        $meetingIds = Meeting::where('status', '<>', 'cancelled')->pluck('lead_id')->merge($reached(['meeting_booked', 'proposal_sent', 'negotiation', 'won']))->merge($this->wonLeadIds(null))->unique();
        $proposalIds = Deal::whereIn('stage', ['proposal_sent', 'negotiation', 'won', 'closed_won'])->whereNotNull('lead_id')->pluck('lead_id')
            ->merge($reached(['proposal_sent', 'negotiation', 'won']))->merge($this->wonLeadIds(null))->unique();
        $wonIds = $this->wonLeadIds(null);
        $lostIds = Lead::where('lifecycle_stage', 'lost')->pluck('id');
        $among = fn ($ids) => ['all' => $ids->intersect($emailedSince(null))->count(), 'recent' => $ids->intersect($emailedSince($since))->count()];
        $pct = fn ($a, $b) => $b ? round(100 * $a / $b, 1) : null;

        $f = [
            'emailed' => ['all' => $all['emailed_prospects'], 'recent' => $recent['emailed_prospects']],
            'delivered_emails' => ['all' => $all['delivered'], 'recent' => $recent['delivered']],
            'replied' => ['all' => $all['replied'], 'recent' => $recent['replied']],
            'interested' => ['all' => $all['positive'], 'recent' => $recent['positive']],
            'meeting' => $among($meetingIds),
            'proposal' => $among($proposalIds),
            'won' => $among($wonIds),
            'lost' => $among($lostIds),
        ];
        // every rate is "% of e-mailed prospects", so the steps read as one funnel and can never pass 100 %
        foreach (['replied', 'interested', 'meeting', 'proposal', 'won'] as $k) {
            $f[$k]['rate'] = $pct($f[$k]['all'], $f['emailed']['all']);
        }
        $f['emailed']['rate'] = $pct($f['emailed']['all'], $found['leads']['all']);
        $f['delivered_emails']['rate'] = $all['sent'] ? $all['delivery_rate'] : null;
        $f['lost']['rate'] = $pct($f['lost']['all'], $f['won']['all'] + $f['lost']['all']);

        $winRates = [
            'reply_rate (replied / emailed)' => $f['replied']['rate'],
            'interested_rate (interested / emailed)' => $f['interested']['rate'],
            'meeting_rate (meetings / emailed)' => $f['meeting']['rate'],
            'win_rate (won / emailed)' => $f['won']['rate'],
            'close_rate (won / won+lost)' => $pct($f['won']['all'], $f['won']['all'] + $f['lost']['all']),
            'interested_to_won (won / interested)' => $pct($f['won']['all'], $f['interested']['all']),
            'won_any_channel (count)' => $wonIds->count(),
        ];

        $labels = ['R' => 'Replied', 'A' => 'Email now', 'B' => 'One fix away', 'S' => 'In sequence', 'C' => 'Nurture', 'D' => 'Do not email'];
        $ladder = [];
        foreach ($labels as $t => $label) {
            $q = Lead::where('win_tier', $t);
            $ladder[$t] = ['label' => $label, 'leads' => (clone $q)->count(),
                // stored as percentages already
                'reply' => round((float) (clone $q)->avg('win_reply_chance'), 1),
                'interested' => round((float) (clone $q)->avg('win_interested_chance'), 1),
                'deal' => round((float) (clone $q)->avg('win_deal_chance'), 2)];
        }
        $ab = Lead::whereIn('win_tier', ['A', 'B']);

        $sources = [];
        $won = $this->wonLeadIds(null);
        $emailedIds = \App\Models\CampaignRecipient::whereNotNull('sent_at')->distinct()->pluck('lead_id');
        $repliedIds = \App\Models\InboundReply::whereNotNull('lead_id')->get(['lead_id', 'category', 'classification'])
            ->reject(fn ($x) => in_array($x->category ?? $x->classification, OutreachMetrics::NOT_A_REPLY, true))->pluck('lead_id')->unique();
        foreach (Lead::selectRaw("coalesce(source_name, 'unknown') as s, count(*) as n")->groupBy('s')->orderByDesc('n')->limit(10)->pluck('n', 's') as $s => $n) {
            $ids = Lead::where(fn ($q) => $s === 'unknown' ? $q->whereNull('source_name') : $q->where('source_name', $s))->pluck('id');
            $sources[] = ['source' => $s, 'leads' => (int) $n, 'emailed' => $ids->intersect($emailedIds)->count(),
                'replied' => $ids->intersect($repliedIds)->count(), 'won' => $ids->intersect($won)->count()];
        }

        return [
            'generated_at' => now()->toDateTimeString(), 'days' => $days,
            'found' => $found, 'funnel' => $f, 'win_rates' => $winRates, 'ladder' => $ladder,
            'predicted' => ['leads_A_B' => (clone $ab)->count(), 'deals_from_A_B' => round((float) (clone $ab)->sum('win_deal_chance') / 100, 2),
                'replies_from_A_B' => round((float) (clone $ab)->sum('win_reply_chance') / 100, 1)],
            'segments' => $review->build()['segments'],
            'by_source' => $sources,
            'whatsapp' => $this->whatsapp($since),
            'other' => [
                'emails_sent_total' => $all['sent'], 'bounce_rate_pct' => $all['bounce_rate'],
                'won_deal_value' => (float) Deal::whereIn('stage', ['won', 'closed_won'])->sum('value'),
                'open_deals' => Deal::whereNotIn('stage', ['won', 'closed_won', 'lost', 'closed_lost'])->count(),
                'radar_hot' => RadarItem::where('intent_level', 'hot')->count(), 'radar_warm' => RadarItem::where('intent_level', 'warm')->count(),
                'audits_completed' => WebsiteAudit::where('status', 'completed')->count(), 'audits_failed' => WebsiteAudit::where('status', 'failed')->count(),
                'outreach_mode' => \App\Models\OutreachSetting::current()->mode,
            ],
        ];
    }

    /** WhatsApp outreach: sent, refused, businesses whose person answered, automatic greetings, and STOPs. */
    protected function whatsapp($since): array
    {
        $out = [];
        foreach (['all' => null, 'recent' => $since] as $k => $from) {
            $msg = fn () => \App\Models\WhatsappMessage::query()->when($from, fn ($q) => $q->where('created_at', '>=', $from));
            $in = fn (string $action) => \App\Models\WhatsappInboundMessage::where('action', $action)->when($from, fn ($q) => $q->where('created_at', '>=', $from));
            $sent = $msg()->where('status', 'sent')->count();
            $replied = $in('reply')->whereNotNull('lead_id')->distinct()->count('lead_id');
            $out['sent'][$k] = $sent;
            $out['refused_by_gateway'][$k] = $msg()->where('status', 'failed')->count();
            $out['businesses_replied'][$k] = $replied;
            $out['auto_greetings'][$k] = $in('auto_reply')->count();
            $out['wrong_number'][$k] = $in('wrong_number')->count();
            $out['asked_to_stop'][$k] = $in('opt_out')->count();
            $out['reply_rate'][$k] = $sent ? round(100 * $replied / $sent, 1) : 0;
        }

        return $out;
    }

    /** Leads marked won, or with a won deal. */
    protected function wonLeadIds($from)
    {
        $byStage = Lead::where('lifecycle_stage', 'won')->when($from, fn ($q) => $q->where('updated_at', '>=', $from))->pluck('id');
        $byDeal = Deal::whereIn('stage', ['won', 'closed_won'])->whereNotNull('lead_id')->when($from, fn ($q) => $q->where('updated_at', '>=', $from))->pluck('lead_id');

        return $byStage->merge($byDeal)->unique()->values();
    }
}
