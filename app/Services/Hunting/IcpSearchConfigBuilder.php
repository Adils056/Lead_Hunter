<?php

namespace App\Services\Hunting;

use App\Models\IcpProfile;

/**
 * Turns an Ideal Customer Profile into concrete, reusable per-source search options.
 * Pure function: no I/O, so the generated config can be previewed in the UI before a paid run.
 */
class IcpSearchConfigBuilder
{
    public const SIZE_TO_APOLLO = [
        '1-10' => '1,10', '11-50' => '11,50', '51-200' => '51,200', '201-500' => '201,500', '500+' => '501,100000',
    ];

    /** @return array<string, array> slug => options */
    public function build(IcpProfile $icp): array
    {
        $sources = $icp->list('sources') ?: $this->defaultSources($icp);
        // Fallback: if a requested paid source can't run (no key / no budget), also use the best free
        // provider of the same capability. The paid source stays in the list so it can ask for approval.
        $registry = app(\App\Services\Integrations\ProviderRegistry::class);
        foreach ($sources as $src) {
            $def = $registry->definition($src);
            if ($def && $def['paid'] && !in_array($src, $registry->select($def['capability']), true)) {
                foreach ($registry->select($def['capability']) as $alt) {
                    if (!($registry->definition($alt)['paid'] ?? true) && !in_array($alt, $sources, true)) {
                        $sources[] = $alt;
                        break;
                    }
                }
            }
        }
        $limit = max(1, (int) $icp->max_results_per_run);
        $keywords = $icp->list('keywords');
        $niches = $icp->list('niches') ?: $icp->list('industries');
        $out = [];
        // A profile that only e-mails has no use for a business it cannot reach by e-mail: ask the map sources for
        // places with a website (the audit finds the inbox there) or a published e-mail, not every name on the map.
        $req = $icp->website_requirement ?: 'any';
        if ($req === 'any' && $icp->list('channels') === ['email']) {
            $req = 'contactable';
        }

        foreach ($sources as $slug) {
            $out[$slug] = match ($slug) {
                'google_places' => [
                    'queries' => $this->placesQueries($niches, $icp->list('cities'), $icp->list('regions'), $icp->list('countries')),
                    'limit' => $limit,
                    'website_requirement' => in_array($req, ['weak_website', 'contactable'], true) ? 'has_website' : $req, // Places returns no e-mail
                    'min_rating' => $icp->min_google_rating ? $icp->min_google_rating / 10 : null,
                    'min_reviews' => $icp->min_review_count,
                    'industry' => $icp->list('industries')[0] ?? null,
                ],
                'overture_places', 'osm_overpass' => [
                    'places' => $this->osmPlaces($icp->list('cities') ?: $icp->list('regions'), $icp->list('countries')),
                    'niches' => $niches ?: $icp->list('industries'),
                    'country' => $icp->list('countries')[0] ?? null,
                    'limit' => $limit,
                    'website_requirement' => match ($req) { 'has_website', 'weak_website' => 'has_website', 'contactable' => 'contactable', default => 'any' },
                    'industry' => $icp->list('industries')[0] ?? null,
                ],
                'uk_find_a_tender', 'uk_contracts_finder' => [
                    'keywords' => $keywords ?: $this->serviceKeywords($icp->list('target_services')),
                    'limit' => $limit,
                    'days' => 21,
                ],
                'eu_ted' => [
                    'limit' => $limit,
                    'days' => 21,
                    'country_codes' => array_values(array_filter(array_map(fn ($c) => self::EU_CODES[strtolower($c)] ?? null, $icp->list('countries')))),
                ],
                'wikidata_orgs' => [
                    'keywords' => $keywords ?: ($niches ?: $icp->list('industries')),
                    'countries' => $icp->list('countries'),
                    'services' => $icp->list('target_services'),
                    'limit' => min($limit, 50),
                ],
                'apollo_orgs' => [
                    'keywords' => $keywords ?: $niches,
                    'countries' => $icp->list('countries'),
                    'employee_ranges' => array_values(array_filter(array_map(fn ($s) => self::SIZE_TO_APOLLO[$s] ?? null, $icp->list('company_sizes')))),
                    'services' => $icp->list('target_services'),
                    'limit' => min($limit, 25),
                ],
                'ats_watchlist' => [
                    'watchlist' => array_values(array_filter($keywords, fn ($k) => preg_match('/^(greenhouse|lever|ashby|workable):/i', $k))),
                    'auto' => true, // also re-check boards that website audits found on companies' own sites
                    'limit' => $limit,
                ],
                'hn_hiring', 'remoteok', 'himalayas', 'arbeitnow' => [
                    'countries' => $icp->list('countries'),
                    'limit' => $limit,
                ],
                'public_job_demand' => [
                    'keywords' => $keywords ?: ['laravel', 'php'],
                    'limit' => $limit,
                ],
                default => ['keywords' => $keywords, 'limit' => $limit],
            };
        }

        return $out;
    }

    protected function defaultSources(IcpProfile $icp): array
    {
        $types = $icp->list('business_types');
        if (in_array('government', $types, true)) {
            $countries = array_map('strtolower', $icp->list('countries'));
            $eu = array_intersect($countries, array_keys(self::EU_CODES));

            return $eu && !in_array('united kingdom', $countries, true) ? ['eu_ted'] : ['uk_contracts_finder', 'uk_find_a_tender'];
        }
        if (in_array('local_business', $types, true) || $icp->list('cities')) {
            // registry picks the best configured provider: free Overture (bundled DuckDB) first, then free OSM, Google Places only when keyed + budgeted
            return array_slice(app(\App\Services\Integrations\ProviderRegistry::class)->select('local_discovery') ?: ['osm_overpass'], 0, 1);
        }

        // B2B without cities: free keyless Wikidata first; Apollo only when keyed and budgeted
        return array_slice(app(\App\Services\Integrations\ProviderRegistry::class)->select('b2b_discovery') ?: ['wikidata_orgs'], 0, 1);
    }

    public const EU_CODES = [
        'germany' => 'DEU', 'france' => 'FRA', 'netherlands' => 'NLD', 'ireland' => 'IRL', 'spain' => 'ESP', 'italy' => 'ITA',
        'belgium' => 'BEL', 'sweden' => 'SWE', 'denmark' => 'DNK', 'poland' => 'POL', 'austria' => 'AUT', 'finland' => 'FIN', 'portugal' => 'PRT',
    ];

    public function osmPlaces(array $cities, array $countries): array
    {
        $country = $countries[0] ?? null;
        $out = [];
        foreach ($cities as $c) {
            $out[] = $country && !str_contains(strtolower($c), strtolower($country)) ? "{$c}, {$country}" : $c;
        }

        return array_slice($out, 0, 6);
    }

    /** "dentist in Lahore, Pakistan" style queries: niche x location (capped to control cost). */
    public function placesQueries(array $niches, array $cities, array $regions, array $countries, int $cap = 12): array
    {
        $locations = [];
        $country = $countries[0] ?? null;
        foreach ($cities ?: $regions as $loc) {
            $locations[] = $country && !str_contains(strtolower($loc), strtolower($country)) ? "{$loc}, {$country}" : $loc;
        }
        if (!$locations && $country) {
            $locations[] = $country;
        }
        $q = [];
        foreach ($niches ?: ['business'] as $n) {
            foreach ($locations ?: [''] as $l) {
                $q[] = trim($n . ($l ? " in {$l}" : ''));
            }
        }

        return array_slice(array_values(array_unique($q)), 0, $cap);
    }

    protected function serviceKeywords(array $services): array
    {
        $map = [
            'web_redesign' => ['website', 'redesign'], 'web_dev' => ['website'], 'ecommerce' => ['e-commerce', 'online shop'],
            'custom_software' => ['software', 'application'], 'erp_pos' => ['erp', 'crm', 'case management'],
            'api_integration' => ['integration', 'api'], 'mobile_app' => ['mobile app'], 'ai_automation' => ['automation', 'artificial intelligence'],
            'seo_technical' => ['seo', 'search engine'], 'accessibility' => ['accessibility', 'wcag'],
        ];
        $kw = [];
        foreach ($services as $s) {
            $kw = array_merge($kw, $map[$s] ?? []);
        }

        return array_values(array_unique($kw ?: ['website', 'software', 'digital']));
    }
}
