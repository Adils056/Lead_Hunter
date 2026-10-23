<?php

namespace App\Services\Autopilot;

use App\Models\IcpProfile;
use App\Models\SearchArm;
use App\Services\Hunting\IcpSearchConfigBuilder;

/**
 * Learns where to search next. Each (ICP x source) is an "arm".
 * Thompson sampling on Beta(successes+1, failures+1) where success = qualified lead (and, once outcomes
 * exist, positive replies count triple). Cost-aware: paid arms are penalised by cost per qualified lead.
 * Arms that found nothing new are rested (exhausted_until). Optimises for qualified leads/outcomes, not volume.
 */
class SearchAllocator
{
    public function pick(int $n = 2): array
    {
        $arms = [];
        foreach (IcpProfile::where('is_active', true)->get() as $icp) {
            foreach (array_keys(app(IcpSearchConfigBuilder::class)->build($icp)) as $slug) {
                // A source without its key (or disabled by policy) can only produce a "skipped" run: leave it out
                // so the cycle's slots go to sources that can actually search.
                $adapter = app(\App\Services\Discovery\DiscoveryManager::class)->getAdapter($slug);
                if (!$adapter || !$adapter->isConfigured()) {
                    continue;
                }
                $arm = SearchArm::firstOrCreate(['icp_profile_id' => $icp->id, 'source_slug' => $slug]);
                if ($arm->exhausted_until && $arm->exhausted_until->isFuture()) {
                    continue;
                }
                $succ = $arm->qualified + 3 * $arm->positive;
                $fail = max(0, $arm->leads - $arm->qualified);
                $sample = $this->beta($succ + 1, $fail + 1);
                $costPenalty = $arm->cost_cents > 0 ? 1 / (1 + ($arm->cost_cents / max(1, $arm->qualified)) / 100) : 1;
                $recency = $arm->last_run_at ? min(1, $arm->last_run_at->diffInHours(now()) / 24) : 1; // don't hammer one arm
                $arms[] = ['icp' => $icp, 'slug' => $slug, 'score' => $sample * $costPenalty * (0.5 + 0.5 * $recency), 'arm' => $arm];
            }
        }
        usort($arms, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($arms, 0, $n);
    }

    /** Beta sample via two Gamma draws (Marsaglia–Tsang). */
    public function beta(float $a, float $b): float
    {
        $x = $this->gamma($a);
        $y = $this->gamma($b);

        return $x + $y > 0 ? $x / ($x + $y) : 0.5;
    }

    protected function gamma(float $k): float
    {
        if ($k < 1) {
            return $this->gamma($k + 1) * pow(mt_rand() / mt_getrandmax(), 1 / $k);
        }
        $d = $k - 1 / 3;
        $c = 1 / sqrt(9 * $d);
        while (true) {
            do {
                $u1 = mt_rand(1, mt_getrandmax()) / mt_getrandmax();
                $u2 = mt_rand(1, mt_getrandmax()) / mt_getrandmax();
                $x = sqrt(-2 * log($u1)) * cos(2 * M_PI * $u2);
                $v = (1 + $c * $x) ** 3;
            } while ($v <= 0);
            $u = mt_rand(1, mt_getrandmax()) / mt_getrandmax();
            if (log($u) < 0.5 * $x * $x + $d - $d * $v + $d * log($v)) {
                return $d * $v;
            }
        }
    }

    /** Feed real outcomes back: called when a reply/meeting/win is recorded. */
    public function recordPositive(\App\Models\Lead $lead): void
    {
        if (!$lead->icp_profile_id) {
            return;
        }
        $slug = optional($lead->company)->source_provider;
        if ($slug) {
            SearchArm::where('icp_profile_id', $lead->icp_profile_id)->where('source_slug', $slug)->increment('positive');
        }
    }
}
