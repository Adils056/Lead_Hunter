<?php

namespace App\Services\Integrations;

use App\Models\SpendBudget;

/**
 * Free-first spend control. A paid call is allowed only when every applicable budget
 * (global daily/monthly, provider, campaign, ICP) still has room. Default global budget = 0 => no paid calls.
 */
class BudgetGuard
{
    public function __construct(protected CostLedger $ledger)
    {
    }

    public function limits(): array
    {
        $out = [
            ['global', null, 'daily', (int) config('leadpilot.budgets.global_daily', 0)],
            ['global', null, 'monthly', (int) config('leadpilot.budgets.global_monthly', 0)],
        ];
        foreach (SpendBudget::where('is_active', true)->get() as $b) {
            $ref = $b->scope === 'global' ? null : $b->scope_ref;
            // DB rows override config for the same scope/period
            $out = array_values(array_filter($out, fn ($r) => !($r[0] === $b->scope && $r[1] === $ref && $r[2] === $b->period)));
            $out[] = [$b->scope, $ref, $b->period, (int) $b->limit_cents];
        }

        return $out;
    }

    /** @return array{allowed: bool, reason: ?string} */
    public function check(string $provider, int $costCents, ?int $campaignId = null, ?int $icpId = null): array
    {
        if ($costCents <= 0) {
            return ['allowed' => true, 'reason' => null];
        }
        foreach ($this->limits() as [$scope, $ref, $period, $limit]) {
            $applies = match ($scope) {
                'global' => true,
                'provider' => $ref === $provider,
                'campaign' => $campaignId && (int) $ref === $campaignId,
                'icp' => $icpId && (int) $ref === $icpId,
                default => false,
            };
            if (!$applies) {
                continue;
            }
            $spent = $this->ledger->spentCents(
                $scope === 'provider' ? $provider : null, $period,
                $scope === 'campaign' ? $campaignId : null, $scope === 'icp' ? $icpId : null
            );
            if ($spent + $costCents > $limit) {
                return ['allowed' => false, 'reason' => sprintf('%s %s budget reached (spent $%.2f of $%.2f).', ucfirst($scope) . ($ref ? " {$ref}" : ''), $period, $spent / 100, $limit / 100)];
            }
        }

        return ['allowed' => true, 'reason' => null];
    }

    public function allows(string $provider, int $costCents, ?int $campaignId = null, ?int $icpId = null): bool
    {
        return $this->check($provider, $costCents, $campaignId, $icpId)['allowed'];
    }
}
