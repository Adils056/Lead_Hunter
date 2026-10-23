<?php

namespace App\Services\Discovery\Adapters;

use App\Services\Discovery\DiscoveryResult;
use App\Services\Discovery\ProspectIngestor;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Free local-business discovery from OpenStreetMap (Overpass API), area resolved via Nominatim.
 *
 * Licence: OpenStreetMap data © OpenStreetMap contributors, ODbL 1.0 — attribution is shown wherever
 * OSM-sourced records are displayed and in docs. Storage is allowed (unlike Google Places content).
 * Usage policy: identifying User-Agent, no parallel requests, Nominatim ≤ 1 req/s and cached,
 * Overpass: modest volumes; community mirror first, main server as fallback.
 *
 * Integrity: absence of a website tag in OSM is NOT evidence that a business has no website,
 * so no "no website" signal is created from OSM data.
 */
class OsmOverpassAdapter extends AbstractDiscoveryAdapter
{
    protected string $sourceSlug = 'osm_overpass';
    protected string $sourceName = 'OpenStreetMap (Overpass)';
    protected string $providerType = 'local_business_directory';
    protected string $description = 'Free local-business discovery from OpenStreetMap. © OpenStreetMap contributors (ODbL).';

    public const ATTRIBUTION = '© OpenStreetMap contributors (ODbL)';

    /** niche keyword -> OSM tag filters */
    public const NICHE_TAGS = [
        'dentist' => [['amenity', 'dentist'], ['healthcare', 'dentist']],
        'dental' => [['amenity', 'dentist'], ['healthcare', 'dentist']],
        'clinic' => [['amenity', 'clinic'], ['healthcare', 'clinic']],
        'doctor' => [['amenity', 'doctors']],
        'hospital' => [['amenity', 'hospital']],
        'physio' => [['healthcare', 'physiotherapist']],
        'pharmacy' => [['amenity', 'pharmacy']],
        'skin' => [['healthcare', 'clinic'], ['shop', 'beauty']],
        'beauty' => [['shop', 'beauty']],
        'salon' => [['shop', 'hairdresser'], ['shop', 'beauty']],
        'gym' => [['leisure', 'fitness_centre']],
        'restaurant' => [['amenity', 'restaurant']],
        'cafe' => [['amenity', 'cafe']],
        'hotel' => [['tourism', 'hotel'], ['tourism', 'guest_house']],
        'school' => [['amenity', 'school']],
        'college' => [['amenity', 'college']],
        'academy' => [['amenity', 'school'], ['amenity', 'college']],
        'real estate' => [['office', 'estate_agent']],
        'property' => [['office', 'estate_agent']],
        'lawyer' => [['office', 'lawyer']],
        'law' => [['office', 'lawyer']],
        'accountant' => [['office', 'accountant']],
        'architect' => [['office', 'architect']],
        'travel' => [['shop', 'travel_agency'], ['office', 'travel_agent']],
        'car' => [['shop', 'car'], ['shop', 'car_repair']],
        'furniture' => [['shop', 'furniture']],
        'clothes' => [['shop', 'clothes']],
        'jewel' => [['shop', 'jewelry']],
        'electronics' => [['shop', 'electronics'], ['shop', 'mobile_phone']],
        'bakery' => [['shop', 'bakery']],
        'software' => [['office', 'it'], ['office', 'company']],
        'it company' => [['office', 'it']],
        'marketing' => [['office', 'advertising_agency']],
        'construction' => [['office', 'construction_company'], ['craft', 'builder']],
        'logistics' => [['office', 'logistics']],
        'insurance' => [['office', 'insurance']],
    ];

    public function isConfigured(): bool
    {
        return true;
    }

    public function testConnection(): array
    {
        foreach ($this->endpoints() as $ep) {
            try {
                $r = $this->overpass($ep, '[out:json][timeout:10];node(1);out ids;', 0, 20); // health probe: 20 s, no retries
                if ($r && $r->successful()) {
                    return ['success' => true, 'message' => "Overpass reachable ({$ep})", 'details' => []];
                }
            } catch (\Throwable) {
            }
        }

        return ['success' => false, 'message' => 'No Overpass endpoint reachable.', 'details' => []];
    }

    protected function http()
    {
        return Http::withUserAgent('LeadPilotAI/4.0 (+' . config('app.url') . '; ' . config('leadpilot.osm.contact_email') . ')')
            ->timeout((int) config('leadpilot.osm.http_timeout', 120))->acceptJson();
    }

    /**
     * Overpass endpoints in order of preference. OSM_OVERPASS_MIRRORS (comma-separated) replaces the default
     * list. Endpoints that could not be reached recently (timeout / connection refused) are skipped for
     * 20 minutes (circuit breaker), so one unreachable mirror does not cost a full timeout on every search.
     */
    protected function endpoints(bool $includeDown = false): array
    {
        $env = array_filter(array_map('trim', explode(',', (string) config('leadpilot.osm.overpass_mirrors', ''))));
        $all = array_values(array_unique(array_filter($env ?: [
            config('leadpilot.osm.overpass_url'), // overpass-api.de answers every time on this PC; private.coffee times out, so it goes last
            'https://overpass-api.de/api/interpreter',
            'https://overpass.private.coffee/api/interpreter',
        ])));
        if ($includeDown) {
            return $all;
        }
        $up = array_values(array_filter($all, fn ($ep) => !Cache::has($this->downKey($ep))));
        $list = $up ?: $all; // every endpoint marked down: try them anyway rather than give up
        // Endpoints that answered within the last 6 h go first (stable sort keeps the configured order otherwise).
        $ok = array_map(fn ($ep) => Cache::has($this->okKey($ep)) ? 0 : 1, $list);
        array_multisort($ok, SORT_ASC, array_keys($list), SORT_ASC, $list);

        return array_values($list);
    }

    protected function okKey(string $ep): string
    {
        return 'osm_overpass_ok:' . md5($ep);
    }

    protected function downKey(string $ep): string
    {
        return 'osm_overpass_down:' . md5($ep);
    }

    /**
     * POST one Overpass query. 429/502/503/504 are retried (the public server is often briefly overloaded;
     * a 504 followed by a 200 on the same query is normal) after a short pause. A connection failure or
     * timeout marks the endpoint down for 20 minutes and is re-thrown so the caller can record it.
     */
    protected function overpass(string $ep, string $query, int $retries = 2, ?int $timeout = null): ?\Illuminate\Http\Client\Response
    {
        $r = null;
        for ($attempt = 0; $attempt <= $retries; $attempt++) {
            if ($attempt > 0) {
                sleep((int) config('leadpilot.osm.retry_pause_seconds', 5) * $attempt);
            }
            try {
                $r = ($timeout ? $this->http()->timeout($timeout) : $this->http())->asForm()->post($ep, ['data' => $query]);
            } catch (\Illuminate\Http\Client\ConnectionException $e) {
                Cache::put($this->downKey($ep), mb_substr($e->getMessage(), 0, 200), now()->addMinutes(20));
                throw $e;
            }
            $remark = (string) ($r->successful() ? $r->json('remark', '') : '');
            $softTimeout = $remark !== '' && preg_match('/timed out|runtime error|out of memory/i', $remark) && empty($r->json('elements'));
            if (!in_array($r->status(), [429, 502, 503, 504], true) && !$softTimeout) {
                if ($r->successful()) {
                    Cache::put($this->okKey($ep), now()->toIso8601String(), now()->addHours(24));
                }

                return $r;
            }
        }
        if ($r && $r->successful() && empty($r->json('elements')) && $r->json('remark')) {
            throw new \RuntimeException('Overpass query gave up: ' . mb_substr((string) $r->json('remark'), 0, 160));
        }

        return $r;
    }

    /**
     * City + country -> Overpass area id (admin-boundary relation) or, when OSM has no boundary relation for
     * the place (common outside Europe, e.g. Rawalpindi is only a city node), its bounding box
     * [south, west, north, east]. Cached (Nominatim policy: cache, <= 1 req/s). Misses are retried after 6 h.
     */
    public function resolveArea(string $place): int|array|null
    {
        $key = 'osm_area2:' . md5(strtolower($place));
        $cached = Cache::get($key);
        if ($cached !== null) {
            return $cached === 'miss' ? null : $cached;
        }
        // <=1 request/second across the app
        $last = (float) Cache::get('osm_nominatim_last', 0);
        $wait = 1.05 - (microtime(true) - $last);
        if ($wait > 0) {
            usleep((int) ($wait * 1e6));
        }
        Cache::put('osm_nominatim_last', microtime(true), 60);
        $r = $this->http()->timeout(20)->get(config('leadpilot.osm.nominatim_url'), [
            'q' => $place, 'format' => 'jsonv2', 'limit' => 5, 'email' => config('leadpilot.osm.contact_email'),
        ]);
        if (!$r->successful()) {
            return null; // transient: not cached
        }
        $rows = collect($r->json());
        $rel = $rows->first(fn ($x) => ($x['osm_type'] ?? '') === 'relation');
        // Live finding (23 Oct 2026): area(...) queries time out on the public mirrors (HTTP 504 after 60 s, or HTTP 200
        // with a "Query timed out" remark and no elements) while the same search by bounding box answers in ~30 s.
        // So the bounding box is preferred whenever Nominatim gives one; the admin area is only used when it does not.
        $hit = $rel && is_array($rel['boundingbox'] ?? null) && count($rel['boundingbox']) === 4
            ? $rel
            : $rows->first(fn ($x) => is_array($x['boundingbox'] ?? null) && count($x['boundingbox']) === 4);
        if ($hit) {
            $bb = $hit['boundingbox']; // Nominatim order: south, north, west, east
            $box = [(float) $bb[0], (float) $bb[2], (float) $bb[1], (float) $bb[3]];
            Cache::forever($key, $box);

            return $box;
        }
        if ($rel) {
            $id = 3600000000 + (int) $rel['osm_id'];
            Cache::forever($key, $id);

            return $id;
        }
        Cache::put($key, 'miss', now()->addHours(6));

        return null;
    }

    /** Area id only (null when the place resolves to a bounding box). Prefer resolveArea(). */
    public function areaId(string $place): ?int
    {
        $a = $this->resolveArea($place);

        return is_int($a) ? $a : null;
    }
    public function tagFilters(array $niches): array
    {
        $filters = [];
        foreach ($niches as $n) {
            $n = strtolower(trim($n));
            $hit = false;
            foreach (self::NICHE_TAGS as $kw => $tags) {
                if (str_contains($n, $kw)) {
                    $filters = array_merge($filters, $tags);
                    $hit = true;
                }
            }
            if (!$hit && $n !== '') {
                $filters[] = ['name~', $n]; // fallback: name contains the niche word
            }
        }

        return array_values(array_unique($filters, SORT_REGULAR));
    }

    /** @param int|array $area Overpass area id, or bounding box [south, west, north, east] */
    public function buildQuery(int|array $area, array $filters, int $max): string
    {
        $where = is_array($area) ? '(' . implode(',', array_map(fn ($v) => sprintf('%.6f', (float) $v), $area)) . ')' : '(area.a)';
        $parts = [];
        foreach ($filters as [$k, $v]) {
            $v = str_replace(['"', '\\'], '', $v);
            $parts[] = $k === 'name~'
                ? "  nwr[\"name\"~\"{$v}\",i]{$where};"
                : "  nwr[\"{$k}\"=\"{$v}\"][\"name\"]{$where};";
        }
        $head = is_array($area) ? '' : "area({$area})->.a;\n";

        return "[out:json][timeout:90];\n{$head}(\n" . implode("\n", $parts) . "\n);\nout center tags " . max(1, $max) . ';';
    }
    protected function fetchAndIngest(array $options, DiscoveryResult $result, ProspectIngestor $ingestor): void
    {
        $limit = (int) ($options['limit'] ?? 25);
        $country = $options['country'] ?? null;
        $places = (array) ($options['places'] ?? []);
        $filters = $this->tagFilters((array) ($options['niches'] ?? []));
        if (!$places || !$filters) {
            $result->addError('OSM search needs at least one city and one niche in the ICP.');

            return;
        }
        $processed = 0;
        foreach ($places as $place) {
            $area = $this->resolveArea($place);
            if (!$area) {
                $result->addError("Could not resolve area for \"{$place}\" via Nominatim.");
                continue;
            }
            $json = null;
            foreach ($this->endpoints() as $ep) {
                try {
                    $r = $this->overpass($ep, $this->buildQuery($area, $filters, $limit * 3));
                    if ($r && $r->successful()) {
                        $json = $r->json();
                        break;
                    }                    $result->addError("Overpass {$ep} HTTP {$r->status()}" . ($r->status() === 429 ? ' (rate limited)' : ''));
                } catch (\Throwable $e) {
                    $result->addError("Overpass {$ep}: " . mb_substr($e->getMessage(), 0, 120));
                }
            }
            if (!$json) {
                continue;
            }
            [$city] = array_map('trim', explode(',', $place));

            foreach ((array) ($json['elements'] ?? []) as $el) {
                if ($processed >= $limit) {
                    break 2;
                }
                $t = $el['tags'] ?? [];
                if (empty($t['name'])) {
                    continue;
                }
                $result->totalFound++;
                $website = $t['website'] ?? $t['contact:website'] ?? $t['url'] ?? null;
                if ($website && !preg_match('#^https?://#i', $website)) {
                    $website = 'https://' . ltrim($website, '/');
                }
                if (($options['website_requirement'] ?? 'any') === 'has_website' && !$website) {
                    $result->excludedCount++;
                    continue;
                }
                $phone = $t['phone'] ?? $t['contact:phone'] ?? $t['contact:mobile'] ?? null;
                $email = $t['email'] ?? $t['contact:email'] ?? null;
                $osmUrl = "https://www.openstreetmap.org/{$el['type']}/{$el['id']}";
                $category = $t['amenity'] ?? $t['shop'] ?? $t['office'] ?? $t['healthcare'] ?? $t['tourism'] ?? $t['leisure'] ?? $t['craft'] ?? null;
                $addr = trim(implode(', ', array_filter([trim(($t['addr:housenumber'] ?? '') . ' ' . ($t['addr:street'] ?? '')), $t['addr:city'] ?? null, $t['addr:postcode'] ?? null])), ', ');

                $up = $ingestor->upsertCompany([
                    'name' => $t['name'],
                    'website' => $website,
                    'public_phone' => $phone ? trim(explode(';', $phone)[0]) : null,
                    'public_email' => $email ? trim(explode(';', $email)[0]) : null,
                    'public_address' => $addr ?: null,
                    'city' => $t['addr:city:en'] ?? $t['addr:city'] ?? $city, // English name when OSM has one (addr:city is often in the local script)
                    'country' => $country,
                    'industry' => $category ? ucwords(str_replace('_', ' ', $category)) : ($options['industry'] ?? null),
                    'external_id' => "osm:{$el['type']}/{$el['id']}",
                    'source_url' => $osmUrl,
                    'confidence' => 75, // community-maintained data: verify via website audit
                ], $result);
                if (!$up['company']) {
                    continue;
                }
                $processed++;
                $lead = $ingestor->ensureLead($up['company'], array_filter(['email' => $email ? trim(explode(';', $email)[0]) : null]), $result,
                    $website ? ['web_redesign'] : ['web_dev'], $osmUrl, "osm:{$el['type']}/{$el['id']}");
                $result->recordItem(['company' => $t['name'], 'website' => $website, 'phone' => $phone, 'osm' => $osmUrl, 'lead_id' => $lead?->id, 'attribution' => self::ATTRIBUTION]);
            }
        }
    }
}
