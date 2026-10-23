<?php

namespace App\Services\Discovery\Adapters;

use App\Models\Integration;
use App\Services\Discovery\DiscoveryResult;
use App\Services\Discovery\ProspectIngestor;
use Illuminate\Http\Client\ConnectionException;

/**
 * B2B company discovery via Apollo.io Organization Search (requires your own Apollo API key).
 * Stores only fields Apollo returns. No placeholder contacts (no invented info@ addresses),
 * no invented tech stack, no default country/headcount, and no synthetic "growth" signal.
 */
class ApolloOrgDiscoveryAdapter extends AbstractDiscoveryAdapter
{
    protected string $sourceSlug = 'apollo_orgs';
    protected string $sourceName = 'Apollo.io Organization Search';
    protected string $providerType = 'apollo';
    protected string $description = 'B2B firmographic company search via the Apollo.io API (your key, your credits).';

    protected function key(): ?string
    {
        $integ = Integration::where('provider', 'apollo')->first();
        if (!$integ || !$integ->is_active) {
            return null;
        }

        return $integ->getDecryptedCredentials()['api_key'] ?? null;
    }

    public function isConfigured(): bool
    {
        return !empty($this->key());
    }

    public function testConnection(): array
    {
        if (!$this->isConfigured()) {
            return ['success' => false, 'message' => 'Apollo.io API key not configured (Settings > Integrations).', 'details' => ['configured' => false]];
        }
        try {
            $res = $this->resilientHttp(10)->withHeaders(['X-Api-Key' => $this->key()])->get('https://api.apollo.io/v1/auth/health');

            return ['success' => $res->successful(), 'message' => 'Apollo health HTTP ' . $res->status(), 'details' => ['health' => $res->json()]];
        } catch (ConnectionException $e) {
            return ['success' => false, 'message' => $this->connectionError('Apollo.io', $e), 'details' => []];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Apollo connection error: ' . $e->getMessage(), 'details' => []];
        }
    }

    public static function sizeRange(?int $employees): ?string
    {
        if (!$employees) {
            return null;
        }

        return match (true) {
            $employees > 500 => '500+',
            $employees > 200 => '201-500',
            $employees > 50 => '51-200',
            $employees > 10 => '11-50',
            default => '1-10',
        };
    }

    protected function fetchAndIngest(array $options, DiscoveryResult $result, ProspectIngestor $ingestor): void
    {
        $body = [
            'q_organization_keyword_tags' => (array) ($options['keywords'] ?? ['software']),
            'page' => 1,
            'per_page' => (int) ($options['limit'] ?? 10),
        ];
        if (!empty($options['countries'])) {
            $body['organization_locations'] = (array) $options['countries'];
        }
        if (!empty($options['employee_ranges'])) {
            $body['organization_num_employees_ranges'] = (array) $options['employee_ranges'];
        }

        try {
            $res = $this->resilientHttp(20)->withHeaders(['X-Api-Key' => $this->key(), 'Content-Type' => 'application/json'])
                ->post('https://api.apollo.io/v1/organizations/search', $body);
        } catch (ConnectionException $e) {
            $result->addError($this->connectionError('Apollo.io', $e));

            return;
        }

        if (!$res->successful()) {
            $result->addError("Apollo API HTTP {$res->status()}: " . substr($res->body(), 0, 200));

            return;
        }

        foreach ((array) $res->json('organizations', []) as $org) {
            $result->totalFound++;
            $employees = isset($org['estimated_num_employees']) ? (int) $org['estimated_num_employees'] : null;
            $domain = strtolower(trim((string) ($org['primary_domain'] ?? '')));
            $up = $ingestor->upsertCompany([
                'name' => $org['name'] ?? '',
                'domain' => $domain ?: null,
                'website' => $org['website_url'] ?? ($domain ? "https://{$domain}" : null),
                'industry' => $org['industry'] ?? null,
                'country' => $org['country'] ?? null,
                'city' => $org['city'] ?? null,
                'size_range' => self::sizeRange($employees),
                'public_phone' => $org['phone'] ?? ($org['primary_phone']['number'] ?? null),
                'external_id' => isset($org['id']) ? 'apollo:' . $org['id'] : null,
                'source_url' => $org['linkedin_url'] ?? null,
                'confidence' => 80,
            ], $result);

            if ($up['company']) {
                $lead = $ingestor->ensureLead($up['company'], [], $result, (array) ($options['services'] ?? []), $org['linkedin_url'] ?? null);
                $result->recordItem(['company' => $up['company']->name, 'domain' => $domain, 'employees' => $employees, 'lead_id' => $lead?->id]);
            }
        }
    }
}
