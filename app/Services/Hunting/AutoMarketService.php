<?php

namespace App\Services\Hunting;

use App\Models\IcpProfile;
use App\Models\OutreachSetting;

/**
 * Auto Market: builds the Ideal Customer Profiles itself (one per country x segment from config/leadpilot.php
 * `auto_market`), so the autopilot can hunt without anyone typing targets. The SearchAllocator then learns which
 * profile/source pairs produce qualified leads and rests the ones that do not.
 *
 * Only creates what is missing and never edits a profile the owner has since changed; profiles for countries
 * that were removed from the list (or are no longer allowed for outreach) are switched off, not deleted.
 */
class AutoMarketService
{
    /** @return array{created: int, deactivated: int, active: int} */
    public function sync(): array
    {
        $cfg = (array) config('leadpilot.auto_market', []);
        $countries = (array) ($cfg['countries'] ?? []);
        $allowed = (array) (OutreachSetting::current()->allowed_countries ?: array_keys($countries));
        $wanted = [];
        $created = 0;

        foreach ($countries as $country => $cities) {
            if (!in_array($country, $allowed, true)) {
                continue;
            }
            foreach ((array) ($cfg['segments'] ?? []) as $key => $seg) {
                if (isset($seg['countries']) && !in_array($country, (array) $seg['countries'], true)) {
                    continue;
                }
                $autoKey = self::key($country, $key);
                $wanted[] = $autoKey;
                $icp = IcpProfile::withTrashed()->firstOrNew(['auto_key' => $autoKey]);
                if ($icp->exists) {
                    continue;
                }
                $local = ($seg['type'] ?? 'local_business') === 'local_business';
                $icp->fill([
                    'name' => "Auto · {$seg['label']} · {$country}",
                    'description' => 'Created by Auto Market. Edit or switch off freely; it will not be re-created or overwritten.',
                    'is_active' => true,
                    'countries' => [$country],
                    'cities' => $local ? array_values((array) $cities) : [],
                    'niches' => (array) ($seg['niches'] ?? []),
                    'business_types' => [$seg['type'] ?? 'local_business'],
                    'target_services' => (array) ($seg['services'] ?? []),
                    'website_requirement' => 'any',
                    'channels' => ['email'],
                    'min_lead_score' => 50,
                    'max_results_per_run' => 25,
                    'exclude_existing_customers' => true,
                    'objective' => 'auto_market',
                ])->save();
                $created++;
            }
        }

        $deactivated = IcpProfile::whereNotNull('auto_key')->whereNotIn('auto_key', $wanted)->where('is_active', true)->update(['is_active' => false]);

        return [
            'created' => $created,
            'deactivated' => $deactivated,
            'active' => IcpProfile::whereNotNull('auto_key')->where('is_active', true)->count(),
        ];
    }

    public static function key(string $country, string $segment): string
    {
        return strtolower(preg_replace('/[^A-Za-z]+/', '-', $country)) . ':' . $segment;
    }
}
