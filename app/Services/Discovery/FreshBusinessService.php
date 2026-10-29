<?php

namespace App\Services\Discovery;

use App\Models\Company;
use App\Services\Integrations\ProviderRegistry;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Newly started businesses, while they still need a website and software:
 *  - UK companies incorporated in the last days, from the official Companies House API (free key, Open Government
 *    Licence), only for the kinds of business Eziline serves (SIC codes below);
 *  - the registration date of companies' own domains, from RDAP (the public, keyless successor of WHOIS):
 *    a domain under a year old marks a new business or a fresh start online.
 */
class FreshBusinessService
{
    /** UK SIC 2007 codes => the industry text stored on the company (it sets the industry group). */
    public const SIC = [
        '86230' => 'Dental practice', '86210' => 'General medical practice', '86220' => 'Specialist medical practice', '86900' => 'Health clinic',
        '56101' => 'Restaurant', '56102' => 'Cafe', '56103' => 'Takeaway food shop', '55100' => 'Hotel', '68310' => 'Real estate agency',
        '41202' => 'Construction (domestic buildings)', '43220' => 'Plumbing & heating', '43210' => 'Electrical installation',
        '69102' => 'Solicitors (law firm)', '69201' => 'Accounting', '69202' => 'Bookkeeping', '96020' => 'Hairdressing & beauty salon',
        '93130' => 'Fitness facilities', '47710' => 'Clothing retail', '47910' => 'Online retail', '85590' => 'Education & training', '74100' => 'Design studio',
    ];

    public function companiesHouseKey(): ?string
    {
        return config('leadpilot.keys.companies_house') ?: app(ProviderRegistry::class)->integrationKeyFor('companies_house');
    }

    /** @return array{companies: int, signals: int, seen: int, error: ?string} */
    public function importUkIncorporations(int $days = 14, int $limit = 300): array
    {
        $key = $this->companiesHouseKey();
        if (!$key) {
            return ['companies' => 0, 'signals' => 0, 'seen' => 0, 'error' => 'No Companies House API key (free at developer.company-information.service.gov.uk). Add it in Integrations.'];
        }
        $result = new DiscoveryResult('companies_house', 'Companies House');
        $ingestor = new ProspectIngestor('companies_house', 'Companies House (new UK companies)');
        $seen = $companies = 0;
        for ($start = 0; $start < $limit; $start += 100) {
            $res = Http::timeout(30)->withBasicAuth($key, '')->get('https://api.company-information.service.gov.uk/advanced-search/companies', [
                'incorporated_from' => now()->subDays($days)->toDateString(), 'incorporated_to' => now()->toDateString(),
                'company_status' => 'active', 'company_type' => 'ltd', 'sic_codes' => implode(',', array_keys(self::SIC)), 'size' => 100, 'start_index' => $start,
            ]);
            if (!$res->successful()) {
                return ['companies' => $companies, 'signals' => $result->newSignalsCount, 'seen' => $seen, 'error' => 'Companies House HTTP ' . $res->status()];
            }
            $items = (array) $res->json('items', []);
            foreach ($items as $it) {
                $seen++;
                $sic = collect((array) ($it['sic_codes'] ?? []))->first(fn ($c) => isset(self::SIC[$c]));
                $name = trim((string) ($it['company_name'] ?? ''));
                if (!$sic || $name === '' || preg_match('/\b(holdings?|investments?|nominees?|properties|property holdings|spv)\b/i', $name)) {
                    continue; // holding / investment vehicles do not buy websites
                }
                $addr = (array) ($it['registered_office_address'] ?? []);
                $up = $ingestor->upsertCompany([
                    'name' => ucwords(strtolower($name)), 'country' => 'United Kingdom', 'city' => isset($addr['locality']) ? ucwords(strtolower((string) $addr['locality'])) : null,
                    'industry' => self::SIC[$sic], 'external_id' => 'ch:' . $it['company_number'],
                    'public_address' => trim(implode(', ', array_filter([$addr['address_line_1'] ?? null, $addr['locality'] ?? null, $addr['postal_code'] ?? null])), ', ') ?: null,
                    'source_url' => 'https://find-and-update.company-information.service.gov.uk/company/' . $it['company_number'],
                ], $result);
                $company = $up['company'] ?? null;
                if (!$company) {
                    continue;
                }
                $on = Carbon::parse($it['date_of_creation'] ?? now());
                $company->forceFill(['incorporated_on' => $on->toDateString()])->saveQuietly();
                $companies += $up['created'] ? 1 : 0;
                $ingestor->ensureLead($company, [], $result, [], $company->source_url);
                $ingestor->addSignal($company, ['signal_type' => 'business_launch', 'title' => "New company: incorporated on {$on->format('j M Y')}",
                    'evidence_text' => "{$company->name} was registered at Companies House on {$on->format('j M Y')} ({$company->industry}). New businesses usually set up their website, bookings and accounts software in the first months.",
                    'source_url' => $company->source_url, 'relevant_service' => 'web_dev', 'confidence' => 90,
                    'metadata' => ['source' => 'companies_house', 'company_number' => $it['company_number'], 'sic' => $sic]], $result);
            }
            if (count($items) < 100) {
                break;
            }
        }

        return ['companies' => $companies, 'signals' => $result->newSignalsCount, 'seen' => $seen, 'error' => null];
    }

    /** Registration date of a domain from RDAP, or null when the registry has no RDAP (e.g. some ccTLDs). */
    public function domainRegisteredOn(string $domain): ?Carbon
    {
        $domain = strtolower(trim($domain));
        if (!preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $domain)) {
            return null;
        }
        $res = Http::timeout(15)->accept('application/rdap+json')->get('https://rdap.org/domain/' . $domain);
        if (!$res->successful()) {
            return null;
        }
        foreach ((array) $res->json('events', []) as $e) {
            if (($e['eventAction'] ?? null) === 'registration' && !empty($e['eventDate'])) {
                return Carbon::parse($e['eventDate']);
            }
        }

        return null;
    }

    /** Check domain ages; a domain under a year old becomes a "fresh domain" signal. @return array{checked: int, fresh: int} */
    public function checkDomainAges(int $limit = 200, int $seconds = 300): array
    {
        $deadline = microtime(true) + $seconds;
        $checked = $fresh = 0;
        $free = (array) config('leadpilot.free_mail_domains', []);
        foreach (Company::where('status', '<>', 'archived')->whereNotNull('domain')->whereNull('domain_checked_at')->orderByDesc('id')->limit($limit)->get() as $c) {
            if (microtime(true) > $deadline) {
                break;
            }
            if (in_array(strtolower($c->domain), $free, true)) {
                $c->forceFill(['domain_checked_at' => now()])->saveQuietly();
                continue;
            }
            try {
                $on = $this->domainRegisteredOn($c->domain);
            } catch (\Throwable) {
                $on = null;
            }
            $checked++;
            $c->forceFill(['domain_registered_on' => $on?->toDateString(), 'domain_checked_at' => now()])->saveQuietly();
            if ($on && $on->gt(now()->subYear())) {
                $fresh++;
                $months = max(1, (int) $on->diffInMonths(now()));
                (new ProspectIngestor('rdap', 'Domain registration (RDAP)'))->addSignal($c, ['signal_type' => 'business_launch',
                    'title' => "Fresh domain: {$c->domain} registered {$months} month(s) ago",
                    'evidence_text' => "{$c->domain} was registered on {$on->format('j M Y')}, so the business or its online presence is new.",
                    'source_url' => 'https://rdap.org/domain/' . $c->domain, 'relevant_service' => 'web_dev', 'confidence' => 85,
                    'metadata' => ['source' => 'rdap', 'registered_on' => $on->toDateString()]], new DiscoveryResult('rdap', 'RDAP'));
            }
        }

        return ['checked' => $checked, 'fresh' => $fresh];
    }
}
