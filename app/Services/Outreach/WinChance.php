<?php

namespace App\Services\Outreach;

use App\Models\CampaignRecipient;
use App\Models\InboundReply;
use App\Support\EmailStatus;
use Illuminate\Support\Facades\DB;

/**
 * Success rate for a lead: the estimated chance that a full email sequence gets a reply, an interested reply and a
 * deal. Starts from cold-outreach benchmarks per kind of evidence, adjusts for who we reach and how sure the address
 * is, and moves toward this install's own results per evidence kind as sends accumulate (Bayesian, 30 pseudo-sends).
 * An estimate, always labelled with its basis — not a promise.
 */
class WinChance
{
    /**
     * Benchmarks per sequence (2 emails) by strongest evidence: [reply, interested]. Trigger-based, personalised
     * B2B cold email typically sees 5–10 % replies; generic cold email 1–3 %; a direct request far more.
     */
    public const SEGMENTS = [
        'request' => ['label' => 'Asked for this work', 'reply' => 0.25, 'interested' => 0.12],
        'hiring' => ['label' => 'Hiring / active software need', 'reply' => 0.08, 'interested' => 0.035],
        'growth' => ['label' => 'Growing (funding, launch, expansion)', 'reply' => 0.05, 'interested' => 0.02],
        'audit_high' => ['label' => 'Website audit: big gaps (50+)', 'reply' => 0.06, 'interested' => 0.025],
        'audit_mid' => ['label' => 'Website audit: some gaps (30–49)', 'reply' => 0.04, 'interested' => 0.015],
        'audit_low' => ['label' => 'Website audit: small gaps (<30)', 'reply' => 0.025, 'interested' => 0.008],
        'no_site' => ['label' => 'No website', 'reply' => 0.04, 'interested' => 0.015],
        'none' => ['label' => 'No specific evidence', 'reply' => 0.015, 'interested' => 0.004],
    ];

    /** Interested reply → meeting → deal, typical small-agency funnel. */
    public const MEETING_PER_INTERESTED = 0.5;
    public const DEAL_PER_MEETING = 0.25;

    public const PRIOR_STRENGTH = 30;     // pseudo-sends the benchmark is worth
    public const OWN_DATA_MIN = 20;       // sends before the basis says "your data"
    public const NO_REPLY_AFTER_DAYS = 14; // a send counts as "no reply" after this long

    protected ?array $learned = null;

    /**
     * @param array $f segment, decision_maker (bool), named (bool), shared_inbox (bool), email_status, evidence_age_days, active_business (bool)
     * @return array{segment: string, reply: float, interested: float, deal: float, basis: string}
     */
    public function estimate(array $f): array
    {
        $seg = isset(self::SEGMENTS[$f['segment'] ?? '']) ? $f['segment'] : 'none';
        [$reply, $interested, $basis] = $this->rates($seg);

        $m = 1.0;
        $m *= match (true) {
            !empty($f['decision_maker']) => 1.4,      // the owner / founder reads it
            !empty($f['shared_inbox']) => 0.5,        // info@ is filtered by someone else
            !empty($f['named']) => 1.0,
            default => 0.7,
        };
        $m *= match ($f['email_status'] ?? null) {
            EmailStatus::VERIFIED => 1.0,
            EmailStatus::CATCH_ALL, EmailStatus::RISKY => 0.7,
            EmailStatus::MX_ONLY => 0.5,
            default => 0.4,
        };
        $age = $f['evidence_age_days'] ?? null;
        $m *= match (true) {
            $age === null => 1.0,
            $age <= 14 => 1.2,
            $age <= 30 => 1.1,
            $age > 60 => 0.8,
            default => 1.0,
        };
        if (!empty($f['active_business'])) {
            $m *= 1.1;
        }

        $reply = min(0.6, $reply * $m);
        $interested = min($reply, $interested * $m);

        return [
            'segment' => $seg,
            'reply' => round($reply * 100, 1),
            'interested' => round($interested * 100, 1),
            'deal' => round($interested * self::MEETING_PER_INTERESTED * self::DEAL_PER_MEETING * 100, 1),
            'basis' => $basis,
        ];
    }

    /** [reply, interested, basis] for a segment: benchmark pulled toward this install's own results. */
    public function rates(string $segment): array
    {
        $b = self::SEGMENTS[$segment];
        $own = $this->learned()[$segment] ?? ['sent' => 0, 'replied' => 0, 'interested' => 0];
        $k = self::PRIOR_STRENGTH;
        $reply = ($k * $b['reply'] + $own['replied']) / ($k + $own['sent']);
        $interested = ($k * $b['interested'] + $own['interested']) / ($k + $own['sent']);
        $basis = $own['sent'] >= self::OWN_DATA_MIN
            ? "your data: {$own['sent']} sent, {$own['replied']} replied, {$own['interested']} interested"
            : 'benchmark' . ($own['sent'] ? " + {$own['sent']} of your sends" : '');

        return [$reply, $interested, $basis];
    }

    /** Own results per segment: leads emailed (answered, or sent ≥ 14 days ago) and how many replied / were interested. */
    public function learned(): array
    {
        if ($this->learned !== null) {
            return $this->learned;
        }
        $cutoff = now()->subDays(self::NO_REPLY_AFTER_DAYS);
        $replied = InboundReply::whereNotNull('lead_id')->where(fn ($q) => $q->whereNull('category')->orWhere('category', '<>', 'out_of_office'))
            ->pluck('category', 'lead_id');
        $rows = DB::table('campaign_recipients as r')->join('leads as l', 'l.id', '=', 'r.lead_id')
            ->whereNotNull('r.sent_at')->whereNotNull('l.win_segment')
            ->select('l.id', 'l.win_segment', DB::raw('min(r.sent_at) as first_sent'))->groupBy('l.id', 'l.win_segment')->get();

        $out = [];
        foreach ($rows as $r) {
            $answered = $replied->has($r->id);
            if (!$answered && $r->first_sent > $cutoff) {
                continue; // too early to call it "no reply"
            }
            $s = &$out[$r->win_segment];
            $s ??= ['sent' => 0, 'replied' => 0, 'interested' => 0];
            $s['sent']++;
            $s['replied'] += $answered ? 1 : 0;
            $s['interested'] += $answered && in_array($replied[$r->id], \App\Support\OutreachMetrics::POSITIVE, true) ? 1 : 0;
            unset($s);
        }

        return $this->learned = $out;
    }
}
