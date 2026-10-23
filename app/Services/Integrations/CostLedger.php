<?php

namespace App\Services\Integrations;

use App\Models\UsageLog;

/** Records every billable/potentially-billable call. Actual vs estimated is explicit. */
class CostLedger
{
    public function record(string $provider, string $capability, int $costCents, bool $isEstimate = true, array $context = []): UsageLog
    {
        return UsageLog::create([
            'provider' => $provider,
            'service_type' => $context['service_type'] ?? $capability,
            'capability' => $capability,
            'units_consumed' => $context['units'] ?? 1,
            'tokens_used' => $context['tokens'] ?? 0,
            'cost_estimated' => $costCents / 100,
            'cost_cents' => $costCents,
            'is_estimate' => $isEstimate,
            'icp_profile_id' => $context['icp_profile_id'] ?? null,
            'campaign_id' => $context['campaign_id'] ?? null,
            'user_id' => $context['user_id'] ?? null,
            'metadata' => array_diff_key($context, array_flip(['units', 'tokens', 'icp_profile_id', 'campaign_id', 'user_id', 'service_type'])),
            'occurred_at' => now(),
        ]);
    }

    public function spentCents(?string $provider = null, string $period = 'daily', ?int $campaignId = null, ?int $icpId = null): int
    {
        $from = $period === 'monthly' ? now(config('leadpilot.timezone'))->startOfMonth()->utc() : now(config('leadpilot.timezone'))->startOfDay()->utc();
        $q = UsageLog::where('occurred_at', '>=', $from);
        if ($provider) {
            $q->where('provider', $provider);
        }
        if ($campaignId) {
            $q->where('campaign_id', $campaignId);
        }
        if ($icpId) {
            $q->where('icp_profile_id', $icpId);
        }

        return (int) $q->sum('cost_cents');
    }
}
