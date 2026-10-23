<?php

namespace App\Services\Autopilot;

use App\Models\AutonomyPolicy;

/** action => automatic | approval | prohibited. DB overrides config defaults. Unknown actions default to approval. */
class AutonomyPolicyService
{
    public const MODES = ['automatic', 'approval', 'prohibited'];

    protected static ?array $cache = null;

    public function all(): array
    {
        if (static::$cache === null) {
            $modes = config('leadpilot.autonomy_defaults', []);
            foreach (AutonomyPolicy::all() as $p) {
                if (in_array($p->mode, self::MODES, true)) {
                    $modes[$p->action] = $p->mode;
                }
            }
            static::$cache = $modes;
        }

        return static::$cache;
    }

    public function mode(string $action): string
    {
        return $this->all()[$action] ?? 'approval';
    }

    public function isAutomatic(string $action): bool
    {
        return $this->mode($action) === 'automatic';
    }

    public function isProhibited(string $action): bool
    {
        return $this->mode($action) === 'prohibited';
    }

    public function set(string $action, string $mode, ?int $userId = null): void
    {
        abort_unless(in_array($mode, self::MODES, true), 422);
        AutonomyPolicy::updateOrCreate(['action' => $action], ['mode' => $mode, 'updated_by' => $userId]);
        static::$cache = null;
    }

    public static function flush(): void
    {
        static::$cache = null;
    }
}
