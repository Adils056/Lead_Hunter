<?php

namespace App\Services\Discovery\Adapters;

use App\Services\Discovery\DiscoveryResult;
use App\Services\Discovery\ProspectIngestor;
use Illuminate\Support\Facades\Cache;

/**
 * Free, keyless B2B company discovery from Wikidata (the free alternative to Apollo.io organisation search).
 * Query: organisations with an official website (P856) in a country (P17) whose industry (P452) label contains
 * one of the ICP keywords. Optional facts taken only when Wikidata has them: headquarters city (P159),
 * employees (P1128), inception (P571), published e-mail (P968), phone (P1329). Dissolved organisations (P576) are
 * skipped. Nothing is inferred: no size, no tech stack, no contact unless the record carries one.
 *
 * Coverage is "notable" companies (the ones with a Wikipedia/Wikidata presence): good for established buyers
 * (exporters, logistics, pharma, banks, universities), not for corner shops — those come from Overture / OSM.
 * Licence: Wikidata is CC0. Usage policy: descriptive User-Agent, modest query rate (one SPARQL call per run).
 * Live-verified 23 Oct 2026: Pakistan textile/logistics/pharma = 8 companies with websites in 8 s.
 */
class WikidataOrgsAdapter extends AbstractDiscoveryAdapter
{
    protected string $sourceSlug = 'wikidata_orgs';
    protected string $sourceName = 'Wikidata organisations';
    protected string $providerType = 'b2b_directory';
    protected string $description = 'Free, keyless B2B company discovery from Wikidata (CC0): organisations by industry keyword and country with an official website.';

    public const SPARQL = 'https://query.wikidata.org/sparql';
    public const SEARCH = 'https://www.wikidata.org/w/api.php';
    public const ATTRIBUTION = 'Wikidata (CC0)';

    /** Common country names -> Wikidata item ids (others are resolved live through the search API and cached). */
    public const COUNTRY_QIDS = [
        'pakistan' => 'Q843', 'united kingdom' => 'Q145', 'uk' => 'Q145', 'united states' => 'Q30', 'usa' => 'Q30', 'united arab emirates' => 'Q878', 'uae' => 'Q878',
        'saudi arabia' => 'Q851', 'qatar' => 'Q846', 'oman' => 'Q842', 'kuwait' => 'Q817', 'bahrain' => 'Q398', 'germany' => 'Q183', 'france' => 'Q142',
        'netherlands' => 'Q55', 'ireland' => 'Q27', 'spain' => 'Q29', 'italy' => 'Q38', 'belgium' => 'Q31', 'sweden' => 'Q34', 'denmark' => 'Q35', 'poland' => 'Q36',
        'austria' => 'Q40', 'finland' => 'Q33', 'portugal' => 'Q45', 'norway' => 'Q20', 'switzerland' => 'Q39', 'canada' => 'Q16', 'australia' => 'Q408',
        'new zealand' => 'Q664', 'india' => 'Q668', 'bangladesh' => 'Q902', 'turkey' => 'Q43', 'malaysia' => 'Q833', 'singapore' => 'Q334', 'south africa' => 'Q258',
        'nigeria' => 'Q1033', 'egypt' => 'Q79',
    ];

    public function isConfigured(): bool
    {
        return true;
    }

    public function testConnection(): array
    {
        try {
            $rows = $this->sparql('SELECT ?l WHERE { wd:Q843 rdfs:label ?l FILTER(LANG(?l)="en") } LIMIT 1', 20);

            return ['success' => $rows !== null, 'message' => $rows !== null ? 'Wikidata SPARQL reachable.' : 'Wikidata SPARQL gave no answer.', 'details' => []];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $this->connectionError('Wikidata', $e), 'details' => []];
        }
    }

    protected function http(int $timeout = 60)
    {
        return $this->resilientHttp($timeout)
            ->withUserAgent('LeadPilotAI/4.2 (+' . config('app.url') . '; ' . config('leadpilot.osm.contact_email') . ')')
            ->acceptJson();
    }

    /** @return array<int, array<string, string>>|null rows as column => value */
    protected function sparql(string $query, int $timeout = 60): ?array
    {
        $r = $this->http($timeout)->withHeaders(['Accept' => 'application/sparql-results+json'])->get(self::SPARQL, ['query' => $query]);
        if (!$r->successful()) {
            throw new \RuntimeException('Wikidata SPARQL HTTP ' . $r->status() . ' ' . mb_substr(strip_tags((string) $r->body()), 0, 160), $r->status());
        }
        $out = [];
        foreach ((array) $r->json('results.bindings', []) as $b) {
            $row = [];
            foreach ($b as $k => $v) {
                $row[$k] = (string) ($v['value'] ?? '');
            }
            $out[] = $row;
        }

        return $out;
    }

    /** Country name -> QID (static map, then the live search API, cached for 30 days). */
    public function countryQid(string $country): ?string
    {
        $key = strtolower(trim($country));
        if ($key === '') {
            return null;
        }
        if (isset(self::COUNTRY_QIDS[$key])) {
            return self::COUNTRY_QIDS[$key];
        }

        return Cache::remember('wikidata_country:' . md5($key), now()->addDays(30), function () use ($country) {
            $r = $this->http(20)->get(self::SEARCH, ['action' => 'wbsearchentities', 'search' => $country, 'language' => 'en', 'type' => 'item', 'limit' => 1, 'format' => 'json']);
            $id = (string) ($r->successful() ? $r->json('search.0.id') : '');

            return preg_match('/^Q\d+$/', $id) ? $id : null;
        });
    }

    public function buildQuery(string $countryQid, array $keywords, int $limit): string
    {
        $filters = [];
        foreach ($keywords as $kw) {
            $kw = strtolower(trim(preg_replace('/[^\p{L}\p{N} &\-]/u', '', (string) $kw)));
            if ($kw !== '') {
                $filters[] = 'CONTAINS(LCASE(?il), "' . $kw . '")';
            }
        }
        $filter = $filters ? 'FILTER(' . implode(' || ', $filters) . ')' : '';

        return 'SELECT ?item ?itemLabel (SAMPLE(?website) AS ?site) (SAMPLE(?hqLabel) AS ?hq) (SAMPLE(?employees) AS ?emp) (SAMPLE(?inception) AS ?since) '
            . '(SAMPLE(?email) AS ?mail) (SAMPLE(?phone) AS ?tel) (GROUP_CONCAT(DISTINCT ?il; separator="; ") AS ?industries) WHERE { '
            . "?item wdt:P17 wd:{$countryQid}; wdt:P452 ?industry; wdt:P856 ?website. "
            . '?industry rdfs:label ?il FILTER(LANG(?il)="en"). ' . $filter . ' '
            . 'FILTER NOT EXISTS { ?item wdt:P576 ?dissolved } '
            . 'OPTIONAL { ?item wdt:P159 ?hqItem. ?hqItem rdfs:label ?hqLabel FILTER(LANG(?hqLabel)="en") } '
            . 'OPTIONAL { ?item wdt:P1128 ?employees } OPTIONAL { ?item wdt:P571 ?inception } OPTIONAL { ?item wdt:P968 ?email } OPTIONAL { ?item wdt:P1329 ?phone } '
            . 'SERVICE wikibase:label { bd:serviceParam wikibase:language "en". } '
            . '} GROUP BY ?item ?itemLabel LIMIT ' . max(1, min(200, $limit));
    }

    /** Options: keywords (industry words), countries (names), limit, services, icp. */
    protected function fetchAndIngest(array $options, DiscoveryResult $result, ProspectIngestor $ingestor): void
    {
        $keywords = array_values(array_filter(array_map('trim', (array) ($options['keywords'] ?? []))));
        $countries = array_values(array_filter((array) ($options['countries'] ?? [])));
        $limit = max(1, (int) ($options['limit'] ?? 25));
        if (!$keywords || !$countries) {
            $result->addError('Wikidata search needs at least one industry keyword and one country in the ICP.');

            return;
        }
        $processed = 0;
        foreach (array_slice($countries, 0, 4) as $country) {
            if ($processed >= $limit) {
                break;
            }
            $qid = $this->countryQid($country);
            if (!$qid) {
                $result->addError("Wikidata has no country item for \"{$country}\".");
                continue;
            }
            try {
                $rows = $this->sparql($this->buildQuery($qid, $keywords, ($limit - $processed) * 2)) ?? [];
            } catch (\Throwable $e) {
                $result->addError("Wikidata query for {$country} failed: " . mb_substr($e->getMessage(), 0, 160));
                continue;
            }
            foreach ($rows as $r) {
                if ($processed >= $limit) {
                    break;
                }
                $name = trim($r['itemLabel'] ?? '');
                $qItem = basename((string) ($r['item'] ?? ''));
                if ($name === '' || preg_match('/^Q\d+$/', $name) || !preg_match('/^Q\d+$/', $qItem)) {
                    continue; // unlabeled item: nothing usable to store
                }
                $result->totalFound++;
                $email = strtolower(trim(preg_replace('/^mailto:/i', '', (string) ($r['mail'] ?? ''))));
                $email = filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
                $employees = ($r['emp'] ?? '') !== '' ? (int) $r['emp'] : null;
                $industry = trim(explode(';', (string) ($r['industries'] ?? ''))[0]);
                $itemUrl = "https://www.wikidata.org/wiki/{$qItem}";

                $up = $ingestor->upsertCompany([
                    'name' => $name,
                    'website' => $r['site'] ?? null,
                    'industry' => $industry !== '' ? ucfirst($industry) : null,
                    'country' => $country,
                    'city' => ($r['hq'] ?? '') !== '' ? $r['hq'] : null,
                    'size_range' => ApolloOrgDiscoveryAdapter::sizeRange($employees),
                    'public_email' => $email,
                    'public_phone' => ($r['tel'] ?? '') !== '' ? $r['tel'] : null,
                    'external_id' => "wikidata:{$qItem}",
                    'source_url' => $itemUrl,
                    'confidence' => 70, // community-maintained; the website audit and DNS check confirm the rest
                ], $result);
                if (!$up['company']) {
                    continue;
                }
                $processed++;
                $lead = $ingestor->ensureLead($up['company'], array_filter(['email' => $email]), $result, (array) ($options['services'] ?? []), $itemUrl, "wikidata:{$qItem}");
                $result->recordItem(['company' => $name, 'website' => $r['site'] ?? null, 'city' => $r['hq'] ?? null, 'industry' => $industry, 'employees' => $employees,
                    'email' => $email, 'since' => substr((string) ($r['since'] ?? ''), 0, 10) ?: null, 'wikidata' => $itemUrl, 'lead_id' => $lead?->id, 'attribution' => self::ATTRIBUTION]);
            }
        }
    }
}
