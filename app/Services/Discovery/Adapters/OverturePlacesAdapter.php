<?php

namespace App\Services\Discovery\Adapters;

use App\Models\Company;
use App\Services\Discovery\DiscoveryResult;
use App\Services\Discovery\ProspectIngestor;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/**
 * Free local-business discovery from Overture Maps Foundation "places" (Meta, Microsoft, Foursquare,
 * BrightQuery, AllThePlaces ... ~88 M records). No API key and no paid call: the monthly GeoParquet release
 * is read straight from Overture's public S3 bucket with the bundled DuckDB CLI (tools/duckdb/duckdb.exe).
 * One city = one ~45 s extract per release (cached under storage/app/overture/<release>/), after which
 * every niche search runs locally in well under a second.
 *
 * Licence: CDLA-Permissive-2.0 (Meta, Microsoft, BrightQuery, ...) or Apache-2.0 (Foursquare) per record
 * (sources[].license). Storage is allowed. Attribution is kept with every record (recordItem) and in docs.
 * Integrity: `confidence` is Overture's EXISTENCE score (0-1, not calibrated across providers); a missing
 * website is NOT stored as "has no website" — the website audit decides that from the live site.
 *
 * Live finding (23 Oct 2026, release 2026-10-21.0): Islamabad + Rawalpindi box = 31,234 places in 45 s,
 * 18,839 with an email, 15,928 with a website (OSM: a few hundred, almost none with email).
 */
class OverturePlacesAdapter extends AbstractDiscoveryAdapter
{
    protected string $sourceSlug = 'overture_places';
    protected string $sourceName = 'Overture Maps Places';
    protected string $providerType = 'local_business_directory';
    protected string $description = 'Free local-business discovery from Overture Maps places (Meta, Microsoft, Foursquare data; CDLA-Permissive-2.0 / Apache-2.0). No key: reads the public release with the bundled DuckDB CLI.';

    public const ATTRIBUTION = 'Overture Maps Foundation places (CDLA-Permissive-2.0 / Apache-2.0 per source)';
    public const STAC_CATALOG = 'https://stac.overturemaps.org/catalog.json';
    public const ID_PREFIX = 'overture:';

    /**
     * niche word (regex, whole word, case-insensitive) -> Overture taxonomy patterns (SQL LIKE) matched against
     * taxonomy.primary and every level of taxonomy.hierarchy. Values seen in the 2026-10-21.0 release.
     */
    public const NICHE_CATEGORIES = [
        'dent\w*' => ['dental_clinic', 'dentist%', '%dentistry'],
        'physio\w*' => ['physical_therapy', 'physical_medicine%'],
        'skin' => ['dermatology', 'skin_care%'],
        'derma\w*' => ['dermatology'],
        'eye(s)?' => ['vision_or_eye_care_clinic', 'optometrist', 'ophthalmologist'],
        'clinic\w*' => ['%clinic', 'health_care', 'doctors_office', 'outpatient_care_facility', 'medical_service%'],
        'doctor\w*' => ['doctors_office', '%physician%', 'health_care', '%clinic'],
        'hospital\w*' => ['hospital%'],
        'pharma\w*' => ['pharmacy', 'drugstore'],
        'lab(s|oratory|oratories)?' => ['%laboratory%', 'diagnostics%'],
        'gym\w*' => ['gym', 'sport_or_fitness_facility', 'fitness%'],
        'salon\w*' => ['beauty_salon', 'hair_salon', 'nail_salon'],
        'beaut\w*' => ['beauty_salon', 'skin_care_and_makeup', 'beauty%'],
        'restaurant\w*' => ['%restaurant'],
        'caf[eé]\w*' => ['cafe', 'coffee_shop'],
        'hotel\w*' => ['hotel', 'guest_house', 'bed_and_breakfast'],
        'school\w*' => ['%school', 'preschool'],
        'academ\w*' => ['%school', 'tutoring%', 'education%'],
        'college\w*' => ['college_university', '%college'],
        'universit\w*' => ['college_university'],
        'real estate' => ['real_estate%', 'commercial_real_estate', 'property_management'],
        'propert\w*' => ['real_estate%', 'property_management'],
        'law(s|yers?)?' => ['attorney_or_law_firm', 'immigration_law', 'law_firm%', '%_law'],
        'attorney\w*' => ['attorney_or_law_firm'],
        'account\w*' => ['accountant', 'accounting%', 'tax%'],
        'architect\w*' => ['architect%'],
        'travel\w*' => ['travel%'],
        'car(s)?' => ['car_dealer', 'automotive%', 'auto_repair%'],
        'furniture' => ['furniture_store'],
        'cloth\w*' => ['clothing_store', 'fashion%'],
        'jewel\w*' => ['jewelry%'],
        'electronic\w*' => ['electronics%', 'mobile_phone%', 'computer_store'],
        'baker\w*' => ['bakery'],
        'software\w*' => ['software_development', 'software_company', 'web_designer', 'it_service%', 'information_technology%'],
        'it (company|companies|services?|firms?)' => ['it_service%', 'software_development', 'information_technology%', 'computer_store'],
        'web' => ['web_designer', 'web_development%', 'internet_marketing_service'],
        'marketing' => ['marketing_agency', '%advertising%', 'internet_marketing_service'],
        'construct\w*' => ['building_or_construction_service', 'contractor', 'construction%'],
        'logistic\w*' => ['logistics%', 'freight%', 'courier%'],
        'insurance' => ['insurance%'],
        'bank\w*' => ['bank_or_credit_union'],
        'ngo(s)?' => ['social_or_community_service', 'non_profit%', 'charity%'],
    ];

    /** ISO 3166-1 alpha-2 (Overture addresses[].country) -> the country name used across LeadPilot. */
    public const COUNTRIES = [
        'PK' => 'Pakistan', 'GB' => 'United Kingdom', 'US' => 'United States', 'AE' => 'United Arab Emirates', 'SA' => 'Saudi Arabia',
        'QA' => 'Qatar', 'OM' => 'Oman', 'KW' => 'Kuwait', 'BH' => 'Bahrain', 'DE' => 'Germany', 'FR' => 'France', 'NL' => 'Netherlands',
        'IE' => 'Ireland', 'ES' => 'Spain', 'IT' => 'Italy', 'BE' => 'Belgium', 'SE' => 'Sweden', 'DK' => 'Denmark', 'PL' => 'Poland',
        'AT' => 'Austria', 'FI' => 'Finland', 'PT' => 'Portugal', 'NO' => 'Norway', 'CH' => 'Switzerland', 'CA' => 'Canada',
        'AU' => 'Australia', 'NZ' => 'New Zealand', 'IN' => 'India', 'BD' => 'Bangladesh', 'TR' => 'Turkey', 'MY' => 'Malaysia',
        'SG' => 'Singapore', 'ZA' => 'South Africa', 'NG' => 'Nigeria', 'EG' => 'Egypt',
    ];

    public function binary(): string
    {
        return (string) (config('leadpilot.sources.overture_places.duckdb_bin') ?: base_path('tools/duckdb/duckdb.exe'));
    }

    /** Configured = the DuckDB CLI exists. No key, no account, no cost. */
    public function isConfigured(): bool
    {
        $bin = $this->binary();

        return $bin !== '' && is_file($bin);
    }

    public function testConnection(): array
    {
        if (!$this->isConfigured()) {
            return ['success' => false, 'message' => 'DuckDB CLI not found (expected tools/duckdb/duckdb.exe, or set OVERTURE_DUCKDB_BIN).', 'details' => []];
        }
        try {
            $rows = $this->runSql('SELECT version() AS v;', 30);
            $release = $this->release();

            return [
                'success' => true,
                'message' => 'DuckDB ' . ($rows[0]['v'] ?? '?') . " ready; Overture release {$release}. First city extract takes about a minute, searches after that are local.",
                'details' => ['release' => $release, 'duckdb' => $rows[0]['v'] ?? null],
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'DuckDB failed: ' . mb_substr($e->getMessage(), 0, 160), 'details' => []];
        }
    }

    /** Release id: OVERTURE_RELEASE if pinned, else the STAC catalog's "latest" (cached 24 h), else the known fallback. */
    public function release(): string
    {
        $pinned = trim((string) config('leadpilot.sources.overture_places.release', ''));
        if ($pinned !== '') {
            return $pinned;
        }

        return Cache::remember('overture_release', now()->addHours(24), function () {
            try {
                $r = $this->resilientHttp(20)->get(self::STAC_CATALOG);
                $latest = (string) ($r->successful() ? $r->json('latest') : '');
                if (preg_match('/^\d{4}-\d{2}-\d{2}\.\d+$/', $latest)) {
                    return $latest;
                }
            } catch (\Throwable) {
            }

            return (string) config('leadpilot.sources.overture_places.fallback_release');
        });
    }

    /**
     * Download one city's places for the current release ahead of any search (no-op when already there).
     *
     * @return array{release: string, path: string, seconds: float, bytes: int}
     */
    public function warm(string $place): array
    {
        $box = $this->resolveBox($place);
        if (!$box) {
            throw new \RuntimeException("Could not resolve a bounding box for \"{$place}\" via Nominatim.");
        }
        $release = $this->release();
        $t = microtime(true);
        $path = $this->ensureExtract($box, $release);

        return ['release' => $release, 'path' => $path, 'seconds' => round(microtime(true) - $t, 1), 'bytes' => is_file($path) ? (int) filesize($path) : 0];
    }

    /** City -> bounding box [south, west, north, east] via the shared (cached, rate-limited) Nominatim lookup. */
    protected function resolveBox(string $place): ?array
    {
        $area = app(OsmOverpassAdapter::class)->resolveArea($place);

        return is_array($area) && count($area) === 4 ? $area : null;
    }

    public function extractPath(array $box, string $release): string
    {
        $key = str_replace(['-', '.'], ['m', 'p'], implode('_', array_map(fn ($v) => sprintf('%.3f', (float) $v), $box)));

        return storage_path("app/overture/{$release}/places_{$key}.parquet");
    }

    /**
     * One-off per city and release: copy the places inside the (padded) bounding box from S3 into a small local
     * parquet. Nested columns are flattened to scalars so later queries are plain SQL. Written to a temp name and
     * renamed, so a half-finished download is never mistaken for a complete extract.
     */
    protected function ensureExtract(array $box, string $release): string
    {
        $path = $this->extractPath($box, $release);
        if (is_file($path) && filesize($path) > 0) {
            return $path;
        }
        File::ensureDirectoryExists(dirname($path));
        $pad = (float) config('leadpilot.sources.overture_places.bbox_padding', 0.02);
        [$s, $w, $n, $e] = array_map('floatval', $box);
        $src = rtrim((string) config('leadpilot.sources.overture_places.s3_prefix'), '/') . "/{$release}/theme=places/type=place/*";
        $tmp = $path . '.' . Str::random(6) . '.tmp';
        $sql = "INSTALL httpfs; LOAD httpfs; SET s3_region='us-west-2'; SET enable_progress_bar=false;\n"
            . "COPY (SELECT id, names.primary AS name, taxonomy.primary AS category, array_to_string(taxonomy.hierarchy, ',') AS hierarchy, "
            . 'confidence, websites[1] AS website, emails[1] AS email, phones[1] AS phone, socials[1] AS social, '
            . 'addresses[1].freeform AS address, addresses[1].locality AS locality, addresses[1].region AS region, addresses[1].country AS country, '
            . 'addresses[1].postcode AS postcode, operating_status, bbox.xmin AS lon, bbox.ymin AS lat, sources[1].dataset AS dataset, sources[1].license AS license '
            . "FROM read_parquet('{$src}', hive_partitioning=1) "
            . sprintf('WHERE bbox.xmin BETWEEN %.5f AND %.5f AND bbox.ymin BETWEEN %.5f AND %.5f', $w - $pad, $e + $pad, $s - $pad, $n + $pad)
            . ") TO '" . $this->sqlPath($tmp) . "' (FORMAT PARQUET);";
        try {
            $this->runSql($sql, (int) config('leadpilot.sources.overture_places.extract_timeout', 600));
            if (!is_file($tmp)) {
                throw new \RuntimeException('DuckDB finished but wrote no extract file.');
            }
            rename($tmp, $path);
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }

        return $path;
    }

    /** SQL fragment matching the ICP niches against Overture categories (fallback: the niche word in the name). */
    public function categoryMatch(array $niches): string
    {
        $parts = [];
        foreach ($niches as $niche) {
            $niche = strtolower(trim((string) $niche));
            if ($niche === '') {
                continue;
            }
            $hit = false;
            foreach (self::NICHE_CATEGORIES as $re => $patterns) {
                if (preg_match('/\b(?:' . $re . ')\b/iu', $niche)) {
                    $hit = true;
                    foreach ($patterns as $p) {
                        $parts[] = 'category LIKE ' . $this->q($p);
                        $parts[] = "(',' || coalesce(hierarchy, '') || ',') LIKE " . $this->q('%,' . $p . ',%');
                    }
                }
            }
            if (!$hit) {
                $parts[] = 'name ILIKE ' . $this->q('%' . $niche . '%');
                $parts[] = 'category LIKE ' . $this->q('%' . str_replace(' ', '_', $niche) . '%');
            }
        }

        return $parts ? '(' . implode(' OR ', array_unique($parts)) . ')' : '(1=0)';
    }

    /** @return array<int, array<string, mixed>> */
    protected function queryExtract(string $path, array $niches, array $options, int $limit, int $offset): array
    {
        $min = (float) config('leadpilot.sources.overture_places.min_confidence', 0.6);
        $req = $options['website_requirement'] ?? 'any';
        $web = match ($req) {
            'has_website', 'weak_website' => ' AND website IS NOT NULL',
            'no_website' => ' AND website IS NULL',
            'contactable' => ' AND (website IS NOT NULL OR email IS NOT NULL)',
            default => '',
        };
        $sql = 'SELECT id, name, category, hierarchy, round(confidence * 100) AS confidence, website, email, phone, social, address, locality, region, country, postcode, operating_status, lon, lat, dataset, license '
            . "FROM read_parquet('" . $this->sqlPath($path) . "') "
            . sprintf('WHERE name IS NOT NULL AND coalesce(confidence, 0) >= %.2f ', $min)
            . "AND coalesce(operating_status, '') <> 'permanently_closed' AND " . $this->categoryMatch($niches) . $web . ' '
            // contactable AND auditable first (email + website), then contactable, then auditable, then Overture's existence score
            . 'ORDER BY (email IS NOT NULL AND website IS NOT NULL) DESC, (email IS NOT NULL) DESC, (website IS NOT NULL) DESC, confidence DESC, id '
            . sprintf('LIMIT %d OFFSET %d;', max(1, $limit), max(0, $offset));

        return $this->runSql($sql, 120);
    }

    /**
     * Options (same shape as osm_overpass): places (["Islamabad, Pakistan", ...]), niches, country, limit,
     * website_requirement (any|has_website|no_website|weak_website), industry, icp.
     */
    protected function fetchAndIngest(array $options, DiscoveryResult $result, ProspectIngestor $ingestor): void
    {
        $limit = max(1, (int) ($options['limit'] ?? 25));
        $places = array_values(array_filter((array) ($options['places'] ?? [])));
        $niches = array_values(array_filter((array) ($options['niches'] ?? [])));
        if (!$places || !$niches) {
            $result->addError('Overture search needs at least one city and one niche in the ICP.');

            return;
        }
        $release = $this->release();
        $processed = 0;

        foreach ($places as $place) {
            if ($processed >= $limit) {
                break;
            }
            $box = $this->resolveBox($place);
            if (!$box) {
                $result->addError("Could not resolve a bounding box for \"{$place}\" via Nominatim.");
                continue;
            }
            try {
                if (!config('leadpilot.sources.overture_places.extract_during_search', true) && !is_file($this->extractPath($box, $release))) {
                    // shared hosts: the minutes-long download runs in leadpilot:overture-warm, never inside a cycle
                    $result->addError("Overture data for \"{$place}\" (release {$release}) is not downloaded yet; leadpilot:overture-warm fetches it in the background.");
                    continue;
                }
                $path = $this->ensureExtract($box, $release);
            } catch (\Throwable $e) {
                $result->addError("Overture extract for \"{$place}\" (release {$release}) failed: " . mb_substr($e->getMessage(), 0, 200));
                continue;
            }
            [$city] = array_map('trim', explode(',', $place));
            $page = max(50, $limit * 2);
            $offset = 0;
            for ($pages = 0; $pages < 40 && $processed < $limit; $pages++) {
                $rows = $this->queryExtract($path, $niches, $options, $page, $offset);
                if (!$rows) {
                    break;
                }
                $offset += count($rows);
                // Rows already in the CRM (any state, incl. retired) are skipped quietly: the next page brings new ones.
                $known = Company::withTrashed()->whereIn('external_id', array_map(fn ($r) => self::ID_PREFIX . $r['id'], $rows))->pluck('external_id')->flip()->all();

                foreach ($rows as $r) {
                    if ($processed >= $limit) {
                        break;
                    }
                    $externalId = self::ID_PREFIX . $r['id'];
                    if (isset($known[$externalId])) {
                        $result->duplicatesCount++;
                        continue;
                    }
                    $result->totalFound++;
                    $website = $this->normalizeUrl($r['website'] ?? null);
                    $social = $r['social'] ?? null;
                    if ($website && $this->isSocialUrl($website)) {
                        // Meta-sourced records often carry an Instagram/YouTube/LinkedIn page as the "website": that is a social profile,
                        // not a site that can be audited or redesigned.
                        $social = $social ?: $website;
                        $website = null;
                    }
                    $email = strtolower(trim((string) ($r['email'] ?? '')));
                    $email = filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
                    $country = self::COUNTRIES[strtoupper((string) ($r['country'] ?? ''))] ?? ($options['country'] ?? ($r['country'] ?: null));
                    $locality = trim((string) ($r['locality'] ?? ''));
                    // Overture localities are often in the local script ("زون 1", "تحصیل راولپنڈی شہر"); keep the ICP city then.
                    $cityName = $locality !== '' && preg_match('/^[\x20-\x7E]+$/', $locality) && strcasecmp($locality, (string) $country) !== 0 ? $locality : $city;
                    $address = trim(implode(', ', array_filter([$r['address'] ?? null, $cityName, $r['postcode'] ?? null])), ', ');
                    $mapUrl = sprintf('https://explore.overturemaps.org/#16/%.5f/%.5f', (float) $r['lat'], (float) $r['lon']);
                    $category = (string) ($r['category'] ?? '');

                    $up = $ingestor->upsertCompany([
                        'name' => $r['name'],
                        'website' => $website,
                        'public_email' => $email,
                        'public_phone' => $r['phone'] ?? null,
                        'public_address' => $address ?: null,
                        'city' => $cityName,
                        'country' => $country,
                        'industry' => $category !== '' ? ucwords(str_replace('_', ' ', $category)) : ($options['industry'] ?? null),
                        'external_id' => $externalId,
                        'source_url' => $mapUrl,
                        'business_status' => $r['operating_status'] ?? null,
                        'confidence' => (int) ($r['confidence'] ?? 0), // Overture existence score, 0-100
                    ], $result);
                    if (!$up['company']) {
                        continue; // excluded by the ICP's rules (domain / name exclusions) — counted by the ingestor
                    }
                    $processed++;
                    $lead = $ingestor->ensureLead($up['company'], array_filter(['email' => $email]), $result,
                        $website ? ['web_redesign'] : ['web_dev'], $mapUrl, $externalId);
                    $result->recordItem([
                        'company' => $r['name'], 'category' => $category, 'website' => $website, 'email' => $email, 'phone' => $r['phone'] ?? null,
                        'social' => $social, 'confidence' => (int) ($r['confidence'] ?? 0), 'dataset' => $r['dataset'] ?? null,
                        'map' => $mapUrl, 'lead_id' => $lead?->id, 'attribution' => self::ATTRIBUTION,
                    ]);
                }
            }
        }
    }

    public const SOCIAL_HOSTS = ['facebook.com', 'fb.com', 'instagram.com', 'youtube.com', 'youtu.be', 'linkedin.com', 'twitter.com', 'x.com', 'tiktok.com', 'wa.me', 'whatsapp.com', 'pinterest.com', 'threads.net'];

    public function isSocialUrl(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $host = preg_replace('/^(www|m|web)\./', '', $host);
        foreach (self::SOCIAL_HOSTS as $h) {
            if ($host === $h || str_ends_with($host, '.' . $h)) {
                return true;
            }
        }

        return false;
    }

    protected function normalizeUrl(?string $url): ?string
    {
        $url = trim((string) $url);
        if ($url === '') {
            return null;
        }
        if (!preg_match('#^https?://#i', $url)) {
            $url = 'https://' . ltrim($url, '/');
        }

        return $url;
    }

    protected function q(string $v): string
    {
        return "'" . str_replace("'", "''", $v) . "'";
    }

    protected function sqlPath(string $path): string
    {
        return str_replace(["\\", "'"], ['/', "''"], $path);
    }

    /** Thread / memory caps prepended to every DuckDB script (shared hosts limit threads per account). */
    public function limits(): string
    {
        $threads = (int) config('leadpilot.sources.overture_places.threads', 2);
        $memory = preg_replace('/[^0-9A-Za-z.]/', '', (string) config('leadpilot.sources.overture_places.memory_limit', ''));

        return ($threads > 0 ? "SET threads={$threads};\n" : '') . ($memory !== '' ? "SET memory_limit='{$memory}';\n" : '');
    }

    /**
     * Run SQL through the DuckDB CLI (-json). The statements go in a temp .sql file (no shell quoting), the LAST
     * result set is returned as rows. A non-zero exit raises with DuckDB's own message.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function runSql(string $sql, int $timeout = 120): array
    {
        $dir = storage_path('app/overture');
        File::ensureDirectoryExists($dir);
        $file = $dir . '/q_' . Str::random(10) . '.sql';
        file_put_contents($file, $this->limits() . $sql);
        try {
            $p = new Process([$this->binary(), '-json', '-f', $file]);
            $p->setTimeout($timeout);
            $p->run();
            if (!$p->isSuccessful()) {
                $err = trim($p->getErrorOutput() ?: $p->getOutput());

                throw new \RuntimeException($err !== '' ? preg_replace('/\s+/', ' ', mb_substr($err, 0, 400)) : 'DuckDB exited with code ' . $p->getExitCode());
            }
            $out = trim($p->getOutput());
            if ($out === '') {
                return [];
            }
            $rows = json_decode($out, true);
            if (!is_array($rows) && ($pos = strrpos($out, "\n[")) !== false) {
                $rows = json_decode(substr($out, $pos + 1), true); // several result sets: keep the last
            }

            return is_array($rows) ? $rows : [];
        } finally {
            @unlink($file);
        }
    }
}
