<?php

namespace App\Services\Hunting;

use App\Models\IcpProfile;
use App\Models\Lead;
use App\Models\OutreachSetting;
use App\Models\SearchArm;
use Illuminate\Support\Str;

/**
 * "More like this": when a prospect answers with interest, hunt for businesses of the same kind in the same country,
 * starting with its own city. One profile per (kind of business, country), grown city by city as more of them reply,
 * and its search arms start with the win already counted so the allocator tries them early.
 */
class LookalikeService
{
    public function fromLead(Lead $lead): ?IcpProfile
    {
        $lead->loadMissing(['company', 'icpProfile']);
        $company = $lead->company;
        $origin = $lead->icpProfile;
        $country = $lead->country ?: $company?->country;
        $allowed = (array) (OutreachSetting::current()->allowed_countries ?: []);
        $niche = strtolower(trim((string) ($company?->industry ?: ($origin ? ($origin->list('niches')[0] ?? '') : ''))));
        if (!$company || !$country || $niche === '' || ($allowed && !in_array($country, $allowed, true))) {
            return null; // no kind of business or no approved country to look in
        }

        $types = $origin ? ($origin->list('business_types') ?: ['local_business']) : ['local_business'];
        $local = in_array('local_business', $types, true);
        $cities = $local ? array_values(array_unique(array_filter(array_merge(
            [$company->city],
            (array) (config("leadpilot.auto_market.countries.{$country}") ?? []),
        )))) : [];

        $key = 'lookalike:' . Str::slug($niche) . ':' . Str::slug($country);
        $icp = IcpProfile::withTrashed()->firstOrNew(['auto_key' => $key]);
        if ($icp->exists) {
            // another win of the same kind: put its city first and make sure the profile is searching
            $icp->fill(['is_active' => true, 'cities' => $local ? array_values(array_unique(array_filter(array_merge([$company->city], (array) $icp->cities)))) : (array) $icp->cities]);
            if ($icp->trashed()) {
                $icp->restore();
            }
            $icp->save();
        } else {
            $icp->fill([
                'name' => 'Lookalike · ' . ucwords($niche) . " · {$country}",
                'description' => "Created after {$company->name} replied with interest: same kind of business, same country, starting with its city.",
                'is_active' => true, 'countries' => [$country], 'cities' => array_slice($cities, 0, 6), 'niches' => [$niche],
                'business_types' => $types, 'target_services' => $origin ? $origin->list('target_services') : [],
                'sources' => $origin ? $origin->list('sources') : [], 'website_requirement' => 'any', 'channels' => ['email'],
                'min_lead_score' => 50, 'max_results_per_run' => 25, 'exclude_existing_customers' => true, 'objective' => 'lookalike',
            ])->save();
        }

        foreach (array_keys(app(IcpSearchConfigBuilder::class)->build($icp)) as $slug) {
            SearchArm::firstOrCreate(['icp_profile_id' => $icp->id, 'source_slug' => $slug])->increment('positive');
        }

        return $icp;
    }
}
