<?php

namespace App\Services\Discovery\Adapters;

use App\Services\Discovery\DiscoveryResult;
use App\Services\Discovery\ProspectIngestor;
use Illuminate\Http\Client\ConnectionException;

/**
 * Hiring-signal monitoring for a WATCHLIST of target accounts via official public job-board APIs:
 *   Greenhouse: GET https://boards-api.greenhouse.io/v1/boards/{token}/jobs   (no auth)
 *   Lever:      GET https://api.lever.co/v0/postings/{site}?mode=json          (no auth)
 * You must know each company's board token/site (visible in their careers-page URL).
 * Watchlist format (ICP keywords field or options['watchlist']):
 *   "greenhouse:acme", "lever:globex|globex.com"   (optional |domain to link the company website)
 */
class GreenhouseLeverWatchlistAdapter extends AbstractDiscoveryAdapter
{
    protected string $sourceSlug = 'ats_watchlist';
    protected string $sourceName = 'Greenhouse / Lever Job Boards (watchlist)';
    protected string $providerType = 'job_board';
    protected string $description = 'Detects developer/tech hiring at named target accounts using public Greenhouse and Lever job-board APIs.';

    public const TECH_ROLE = '/(developer|engineer|software|full[- ]?stack|back[- ]?end|front[- ]?end|devops|laravel|php|web|mobile|data|it manager|cto|product manager)/i';

    public function isConfigured(): bool
    {
        return true;
    }

    public function testConnection(): array
    {
        return ['success' => null, 'message' => 'Not testable without a watchlist entry (keyless public APIs are called per company during hunts).', 'details' => []];
    }

    protected function fetchAndIngest(array $options, DiscoveryResult $result, ProspectIngestor $ingestor): void
    {
        $watchlist = array_filter((array) ($options['watchlist'] ?? []), fn ($w) => preg_match('/^(greenhouse|lever):/i', (string) $w));
        if (!$watchlist) {
            $result->addError('Watchlist empty. Add entries like "greenhouse:companytoken" or "lever:company|company.com".');

            return;
        }

        foreach ($watchlist as $entry) {
            [$provider, $rest] = explode(':', strtolower(trim($entry)), 2);
            [$token, $domain] = array_pad(explode('|', $rest, 2), 2, null);

            try {
                $res = $provider === 'greenhouse'
                    ? $this->resilientHttp(15)->get("https://boards-api.greenhouse.io/v1/boards/{$token}/jobs", ['content' => 'false'])
                    : $this->resilientHttp(15)->get("https://api.lever.co/v0/postings/{$token}", ['mode' => 'json']);
            } catch (ConnectionException $e) {
                $result->addError($this->connectionError(ucfirst($provider) . " board {$token}", $e));
                continue;
            }
            if ($provider === 'greenhouse') {
                $jobs = collect($res->json('jobs', []))->map(fn ($j) => [
                    'title' => $j['title'] ?? '', 'url' => $j['absolute_url'] ?? null,
                    'location' => $j['location']['name'] ?? null, 'date' => $j['updated_at'] ?? null,
                ]);
                $boardUrl = "https://boards.greenhouse.io/{$token}";
            } else {
                $jobs = collect($res->successful() ? $res->json() : [])->map(fn ($j) => [
                    'title' => $j['text'] ?? '', 'url' => $j['hostedUrl'] ?? null,
                    'location' => $j['categories']['location'] ?? null,
                    'date' => isset($j['createdAt']) ? date('c', (int) ($j['createdAt'] / 1000)) : null,
                ]);
                $boardUrl = "https://jobs.lever.co/{$token}";
            }

            if (!$res->successful()) {
                $result->addError("{$provider}:{$token} HTTP {$res->status()}");
                continue;
            }

            $tech = $jobs->filter(fn ($j) => preg_match(self::TECH_ROLE, $j['title']));
            $result->totalFound += $tech->count();
            if ($tech->isEmpty()) {
                continue;
            }

            $companyName = $options['names'][$entry] ?? ucwords(str_replace(['-', '_'], ' ', $token));
            $up = $ingestor->upsertCompany([
                'name' => $companyName,
                'domain' => $domain ?: null,
                'website' => $domain ? "https://{$domain}" : null,
                'source_url' => $boardUrl,
                'confidence' => 90,
            ], $result);
            if (!$up['company']) {
                continue;
            }

            $titles = $tech->pluck('title')->take(8)->implode('; ');
            $services = $this->inferServices($titles);
            $ingestor->addSignal($up['company'], [
                'signal_type' => 'developer_hiring',
                'title' => "Hiring {$tech->count()} tech role(s) on " . ucfirst($provider),
                'source_url' => $boardUrl,
                'evidence_text' => "Open roles on public {$provider} board: {$titles}.",
                'confidence' => 90,
                'relevant_service' => $services[0],
                'expiry_date' => now()->addDays(45)->toDateString(),
                'metadata' => ['provider' => $provider, 'token' => $token, 'count' => $tech->count(), 'source' => 'ats_public_api'],
            ], $result);
            $ingestor->ensureLead($up['company'], [], $result, $services, $boardUrl);
            $result->recordItem(['company' => $companyName, 'open_tech_roles' => $tech->count(), 'board' => $boardUrl]);
        }
    }
}
