<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScoringSetting extends Model
{
    protected $fillable = ['weights', 'thresholds', 'updated_by'];

    /** Per-process cache so batch rescoring doesn't hit the DB for every lead. Cleared on save. */
    protected static ?array $cache = null;

    protected static function booted(): void
    {
        static::saved(fn () => static::$cache = null);
        static::deleted(fn () => static::$cache = null);
    }

    public static function flushCache(): void
    {
        static::$cache = null;
    }

    protected function casts(): array
    {
        return ['weights' => 'array', 'thresholds' => 'array'];
    }

    /** Effective weights: DB override merged onto config defaults (unknown keys dropped). */
    public static function effective(): array
    {
        return static::$cache ??= static::loadEffective();
    }

    protected static function loadEffective(): array
    {
        $defaults = config('leadpilot.scoring');
        $row = static::query()->latest('id')->first();

        $weights = $defaults['weights'];
        $thresholds = $defaults['thresholds'];
        if ($row) {
            foreach ((array) $row->weights as $k => $v) {
                if (array_key_exists($k, $weights)) {
                    $weights[$k] = max(0, (int) $v);
                }
            }
            foreach ((array) $row->thresholds as $k => $v) {
                if (array_key_exists($k, $thresholds)) {
                    $thresholds[$k] = max(0, min(100, (int) $v));
                }
            }
        }

        return ['weights' => $weights, 'thresholds' => $thresholds];
    }
}
