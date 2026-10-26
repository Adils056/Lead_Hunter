<?php

namespace App\Services\Discovery\Adapters;

use App\Models\Company;
use App\Services\Discovery\DiscoveryResult;
use App\Services\Discovery\ProspectIngestor;
use Illuminate\Http\Client\ConnectionException;

/**
 * Hiring signals from official, keyless public job-board APIs:
 *   Greenhouse: GET https://boards-api.greenhouse.io/v1/boards/{token}/jobs
 *   Lever:      GET https://api.lever.co/v0/postings/{site}?mode=json
 *   Ashby:      GET https://api.ashbyhq.com/posting-api/job-board/{name}
 *   Workable:   GET https://apply.workable.com/api/v1/widget/accounts/{account}
 * Boards come from two places:
 *   - a manual watchlist ("greenhouse:acme", "lever:globex|globex.com"; ICP keywords or options['watchlist'])
 *   - auto-discovery (options['auto']): boards the website audit found linked from a company's own site
 *     (companies.ats_board), re-checked at most weekly.
 */
class GreenhouseLeverWatchlistAdapter extends AbstractDiscoveryAdapter
{
    protected string $sourceSlug = 'ats_watchlist';
    protected string $sourceName = 'Company job boards (Greenhouse / Lever / Ashby / Workable)';
    protected string $providerType = 'job_board';
    protected string $description = 'Detects developer/tech hiring using public Greenhouse, Lever, Ashby and Workable job-board APIs.';

    public const PROVIDERS = ['greenhouse', 'lever', 'ashby', 'workable'];

    public const TECH_ROLE = '/(developer|engineer|software|full[- ]?stack|back[- ]?end|front[- ]?end|devops|laravel|php|web|mobile|data|it manager|cto|product manager)/i';

    public function isConfigured(): bool
    {
        return true;
    }

    public function testConnection(): array
    {
        return ['success' => null, 'message' => 'Not testable without a board (keyless public APIs are called per company during hunts).', 'details' => []];
    }

    /** "provider:token" for a job-board link, or null. Used by the website audit to auto-discover boards. */
    public static function boardFromUrl(string $url): ?string
    {
        $patterns = [
            'greenhouse' => '#(?:boards|job-boards)\.greenhouse\.io/(?:embed/job_board\?for=)?([a-z0-9_-]+)#i',
            'lever' => '#jobs\.lever\.co/([a-z0-9_-]+)#i',
            'ashby' => '#jobs\.ashbyhq\.com/([a-z0-9._-]+)#i',
            'workable' => '#apply\.workable\.com/([a-z0-9_-]+)#i',
        ];
        foreach ($patterns as $provider => $re) {
            if (preg_match($re, $url, $m) && !in_array(strtolower($m[1]), ['embed', 'api', 'j', 'jobs'], true)) {
                return $provider . ':' . strtolower($m[1]);
            }
        }

        return null;
    }

    protected function fetchAndIngest(array $options, DiscoveryResult $result, ProspectIngestor $ingestor): void
    {
        $re = '/^(' . implode('|', self::PROVIDERS) . '):/i';
        $watchlist = array_values(array_filter((array) ($options['watchlist'] ?? []), fn ($w) => preg_match($re, (string) $w)));
        $names = (array) ($options['names'] ?? []);
        $auto = collect();
        if (!empty($options['auto'])) {
            $auto = Company::whereNotNull('ats_board')
                ->where(fn ($q) => $q->whereNull('ats_checked_at')->orWhere('ats_checked_at', '<', now()->subDays(7)))
                ->orderBy('ats_checked_at')->limit(max(1, (int) ($options['limit'] ?? 25)))->get()->keyBy('id');
            foreach ($auto as $c) {
                $entry = $c->ats_board . ($c->domain ? '|' . $c->domain : '');
                $watchlist[] = $entry;
                $names[$entry] = $c->name;
            }
        }
        if (!$watchlist) {
            $result->addError('No job boards to check yet: boards are found automatically when website audits see a Greenhouse/Lever/Ashby/Workable careers link, or add "greenhouse:token" to an ICP.');

            return;
        }

        foreach ($watchlist as $entry) {
            [$provider, $rest] = explode(':', strtolower(trim($entry)), 2);
            [$token, $domain] = array_pad(explode('|', $rest, 2), 2, null);
            $known = $auto->first(fn ($c) => $c->ats_board === "{$provider}:{$token}");
            $known?->update(['ats_checked_at' => now()]);

            try {
                [$res, $jobs, $boardUrl] = $this->fetchBoard($provider, $token);
            } catch (ConnectionException $e) {
                $result->addError($this->connectionError(ucfirst($provider) . " board {$token}", $e));
                continue;
            }
            if (!$res->successful()) {
                $result->addError("{$provider}:{$token} HTTP {$res->status()}");
                continue;
            }

            $tech = $jobs->filter(fn ($j) => preg_match(self::TECH_ROLE, (string) $j['title']));
            $result->totalFound += $tech->count();
            if ($tech->isEmpty()) {
                continue;
            }

            if ($known) {
                $company = $known;
            } else {
                $up = $ingestor->upsertCompany([
                    'name' => $names[$entry] ?? ucwords(str_replace(['-', '_', '.'], ' ', $token)),
                    'domain' => $domain ?: null,
                    'website' => $domain ? "https://{$domain}" : null,
                    'source_url' => $boardUrl,
                    'confidence' => 90,
                ], $result);
                $company = $up['company'];
            }
            if (!$company) {
                continue;
            }

            $titles = $tech->pluck('title')->take(8)->implode('; ');
            $services = $this->inferServices($titles);
            $ingestor->addSignal($company, [
                'signal_type' => 'developer_hiring',
                'title' => "Hiring {$tech->count()} tech role(s) on " . ucfirst($provider),
                'source_url' => $boardUrl,
                'evidence_text' => "Open roles on the company's public {$provider} job board: {$titles}.",
                'confidence' => 90,
                'relevant_service' => $services[0],
                'expiry_date' => now()->addDays(45)->toDateString(),
                'metadata' => ['provider' => $provider, 'token' => $token, 'count' => $tech->count(), 'source' => 'ats_public_api'],
            ], $result);
            $ingestor->ensureLead($company, [], $result, $services, $boardUrl);
            $result->recordItem(['company' => $company->name, 'open_tech_roles' => $tech->count(), 'board' => $boardUrl]);
        }
    }

    /** @return array{0: \Illuminate\Http\Client\Response, 1: \Illuminate\Support\Collection, 2: string} */
    protected function fetchBoard(string $provider, string $token): array
    {
        $http = $this->resilientHttp(15);

        return match ($provider) {
            'greenhouse' => [$res = $http->get("https://boards-api.greenhouse.io/v1/boards/{$token}/jobs", ['content' => 'false']),
                collect($res->json('jobs', []))->map(fn ($j) => ['title' => $j['title'] ?? '', 'url' => $j['absolute_url'] ?? null]),
                "https://boards.greenhouse.io/{$token}"],
            'lever' => [$res = $http->get("https://api.lever.co/v0/postings/{$token}", ['mode' => 'json']),
                collect($res->successful() ? (array) $res->json() : [])->map(fn ($j) => ['title' => $j['text'] ?? '', 'url' => $j['hostedUrl'] ?? null]),
                "https://jobs.lever.co/{$token}"],
            'ashby' => [$res = $http->get("https://api.ashbyhq.com/posting-api/job-board/{$token}"),
                collect($res->json('jobs', []))->map(fn ($j) => ['title' => $j['title'] ?? '', 'url' => $j['jobUrl'] ?? null]),
                "https://jobs.ashbyhq.com/{$token}"],
            default => [$res = $http->get("https://apply.workable.com/api/v1/widget/accounts/{$token}"),
                collect($res->json('jobs', []))->map(fn ($j) => ['title' => $j['title'] ?? '', 'url' => $j['url'] ?? null]),
                "https://apply.workable.com/{$token}"],
        };
    }
}
