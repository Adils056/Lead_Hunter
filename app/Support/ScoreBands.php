<?php

namespace App\Support;

use App\Models\ScoringSetting;

/**
 * The ONE place score thresholds come from (Settings -> Lead Scoring; defaults in config/leadpilot.php).
 * Views, dashboards, analytics, outreach eligibility and the scoring engine all read these values.
 */
class ScoreBands
{
    public static function thresholds(): array
    {
        return ScoringSetting::effective()['thresholds']; // ['high'=>80,'qualified'=>65,'nurture'=>45]
    }

    public static function high(): int
    {
        return (int) self::thresholds()['high'];
    }

    public static function qualified(): int
    {
        return (int) self::thresholds()['qualified'];
    }

    public static function nurture(): int
    {
        return (int) self::thresholds()['nurture'];
    }

    public static function band(?int $score): string
    {
        $s = (int) $score;

        return match (true) {
            $s >= self::high() => 'high',
            $s >= self::qualified() => 'qualified',
            $s >= self::nurture() => 'nurture',
            default => 'low',
        };
    }

    public static function badgeClass(?int $score): string
    {
        return match (self::band($score)) {
            'high', 'qualified' => 'badge-score-high',
            'nurture' => 'badge-score-medium',
            default => 'badge-score-low',
        };
    }

    public static function textClass(?int $score): string
    {
        return match (self::band($score)) {
            'high', 'qualified' => 'text-success',
            'nurture' => 'text-warning',
            default => 'text-secondary',
        };
    }
}
