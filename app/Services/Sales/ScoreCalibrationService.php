<?php

namespace App\Services\Sales;

use App\Models\InboundReply;
use App\Models\Lead;
use App\Models\LeadScore;

/**
 * Checks whether the heuristic lead score actually predicts outcomes.
 *
 * Outcome definition (per lead that was contacted):
 *   positive = reached interested / meeting_booked / proposal_sent / negotiation / won,
 *              or any reply classified meeting_request|positive|interested
 *   negative = lost / not_a_fit, or contacted >= 21 days ago with no positive outcome
 * Score used = the latest score recorded BEFORE first contact (avoids leakage), else current score.
 *
 * Metrics: positive rate per score band, ROC AUC (Mann-Whitney), Brier score of score/100.
 * Verdict is "insufficient data" until config('leadpilot.scoring.calibration_min_outcomes')
 * resolved leads and at least 5 positives and 5 negatives exist.
 */
class ScoreCalibrationService
{
    public const POSITIVE_STAGES = ['interested', 'meeting_booked', 'proposal_sent', 'negotiation', 'won'];
    public const BANDS = [[80, 100, 'High (80-100)'], [65, 79, 'Qualified (65-79)'], [45, 64, 'Nurture (45-64)'], [0, 44, 'Low (0-44)']];

    public function report(): array
    {
        $min = (int) config('leadpilot.scoring.calibration_min_outcomes', 30);
        $rows = [];

        $leads = Lead::whereNotNull('last_contacted_at')->get(['id', 'lifecycle_stage', 'overall_score', 'last_contacted_at', 'created_at']);
        $positiveReplies = InboundReply::whereIn('classification', ['meeting_request', 'positive', 'interested'])->pluck('lead_id')->flip();

        foreach ($leads as $l) {
            $pos = in_array($l->lifecycle_stage, self::POSITIVE_STAGES, true) || isset($positiveReplies[$l->id]);
            $neg = !$pos && (in_array($l->lifecycle_stage, ['lost', 'not_a_fit'], true) || $l->last_contacted_at->lt(now()->subDays(21)));
            if (!$pos && !$neg) {
                continue; // unresolved
            }
            $pre = LeadScore::where('lead_id', $l->id)->where('evaluated_at', '<=', $l->last_contacted_at)->latest('evaluated_at')->value('overall_score');
            $rows[] = ['score' => (int) ($pre ?? $l->overall_score), 'y' => $pos ? 1 : 0];
        }

        $n = count($rows);
        $p = array_sum(array_column($rows, 'y'));
        $bands = [];
        foreach (self::BANDS as [$lo, $hi, $label]) {
            $in = array_filter($rows, fn ($r) => $r['score'] >= $lo && $r['score'] <= $hi);
            $k = count($in);
            $bands[] = ['band' => $label, 'n' => $k, 'positives' => array_sum(array_column($in, 'y')), 'rate' => $k ? round(100 * array_sum(array_column($in, 'y')) / $k, 1) : null];
        }

        $sufficient = $n >= $min && $p >= 5 && ($n - $p) >= 5;
        $auc = $sufficient ? $this->auc($rows) : null;
        $brier = $n ? round(array_sum(array_map(fn ($r) => (($r['score'] / 100) - $r['y']) ** 2, $rows)) / $n, 3) : null;

        $rates = array_values(array_filter(array_column($bands, 'rate'), fn ($r) => $r !== null));
        $monotonic = count($rates) >= 2 && $rates === array_values(array_reverse(array_values(collect($rates)->sort()->all())));

        $verdict = match (true) {
            !$sufficient => "Insufficient data: {$n} resolved leads ({$p} positive). Need ≥{$min} with ≥5 positive and ≥5 negative. Treat the score as a heuristic ranking only.",
            $auc >= 0.7 && $monotonic => "Score is predictive (AUC {$auc}; higher bands convert more). Still not a probability — Brier {$brier}.",
            $auc >= 0.6 => "Score is weakly predictive (AUC {$auc}). Review weights.",
            default => "Score does NOT predict outcomes (AUC {$auc}). Re-weight factors using the band table.",
        };

        return compact('n', 'p', 'bands', 'auc', 'brier', 'sufficient', 'monotonic', 'verdict', 'min');
    }

    /** ROC AUC via Mann-Whitney U with tie handling. */
    public function auc(array $rows): ?float
    {
        $pos = array_column(array_filter($rows, fn ($r) => $r['y'] === 1), 'score');
        $neg = array_column(array_filter($rows, fn ($r) => $r['y'] === 0), 'score');
        if (!$pos || !$neg) {
            return null;
        }
        $wins = 0.0;
        foreach ($pos as $a) {
            foreach ($neg as $b) {
                $wins += $a > $b ? 1 : ($a == $b ? 0.5 : 0);
            }
        }

        return round($wins / (count($pos) * count($neg)), 3);
    }
}
