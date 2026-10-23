<?php

namespace App\Services\Discovery\Adapters;

use App\Services\Discovery\DiscoveryResult;
use App\Services\Discovery\ProspectIngestor;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Remote developer job postings from the Remotive public API, used as a HIRING SIGNAL.
 *
 * ⚠ TERMS: Remotive's API terms require attribution/link-back, limit fetch frequency (they advise
 * at most ~4 fetches/day; >2 requests/min is blocked) and state that using listings to collect
 * leads breaches their terms. This adapter is therefore DISABLED by default
 * (LEADPILOT_REMOTIVE_ENABLED=false). Prefer GreenhouseLeverWatchlistAdapter for target accounts.
 *
 * Integrity: Remotive does not return a company website or contact, so we store neither.
 * No invented domain, email, size or tech stack.
 */
class PublicJobBoardDemandAdapter extends AbstractDiscoveryAdapter
{
    protected string $sourceSlug = 'public_job_demand';
    protected string $sourceName = 'Remotive Remote Jobs (hiring signal)';
    protected string $providerType = 'job_board';
    protected string $description = 'Companies posting remote developer jobs (Remotive). Disabled by default — see terms note.';

    public function isConfigured(): bool
    {
        return (bool) config('leadpilot.sources.remotive.enabled', false);
    }

    public function testConnection(): array
    {
        try {
            $res = Http::timeout(10)->get('https://remotive.com/api/remote-jobs', ['category' => 'software-dev', 'limit' => 1]);

            return ['success' => $res->successful(), 'message' => 'Remotive HTTP ' . $res->status() . ($this->isConfigured() ? '' : ' (adapter disabled by config — terms restrict lead collection)'), 'details' => []];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage(), 'details' => []];
        }
    }

    protected function fetchAndIngest(array $options, DiscoveryResult $result, ProspectIngestor $ingestor): void
    {
        $limit = (int) ($options['limit'] ?? 15);
        $terms = (array) ($options['keywords'] ?? ['laravel', 'php']);
        $jobs = [];
        foreach (array_slice($terms, 0, 2) as $term) { // stay within Remotive's rate guidance
            $res = Http::timeout(15)->get('https://remotive.com/api/remote-jobs', ['category' => 'software-dev', 'search' => $term, 'limit' => $limit]);
            if ($res->successful()) {
                foreach ((array) $res->json('jobs', []) as $j) {
                    $jobs[$j['id'] ?? md5(($j['title'] ?? '') . ($j['company_name'] ?? ''))] = $j;
                }
            } else {
                $result->addError("Remotive HTTP {$res->status()} for \"{$term}\"");
            }
            if (count($jobs) >= $limit) {
                break;
            }
        }

        foreach (array_slice($jobs, 0, $limit) as $job) {
            $result->totalFound++;
            $title = trim((string) ($job['title'] ?? ''));
            $desc = strip_tags((string) ($job['description'] ?? ''));
            $published = isset($job['publication_date']) ? Carbon::parse($job['publication_date']) : now();

            $up = $ingestor->upsertCompany([
                'name' => $job['company_name'] ?? '',
                'description' => null,
                'source_url' => $job['url'] ?? null,
                'confidence' => 75,
            ], $result);
            if (!$up['company']) {
                continue;
            }
            $services = $this->inferServices($title . ' ' . mb_substr($desc, 0, 500));
            $ingestor->addSignal($up['company'], [
                'signal_type' => 'developer_hiring',
                'title' => "Hiring Demand: {$title}",
                'source_url' => $job['url'] ?? null,
                'evidence_text' => "Public job post \"{$title}\" (" . ($job['candidate_required_location'] ?? 'location not stated') . ') published ' . $published->toDateString() . ' on Remotive. Excerpt: ' . mb_substr($desc, 0, 600),
                'observed_date' => $published->toDateString(),
                'confidence' => 85,
                'relevant_service' => in_array('laravel_php', $services) ? 'laravel_php' : $services[0],
                'expiry_date' => $published->copy()->addDays(60)->toDateString(),
                'metadata' => ['job_id' => $job['id'] ?? null, 'source' => 'remotive_public_api', 'attribution' => 'Remotive'],
            ], $result);
            $ingestor->ensureLead($up['company'], [], $result, $services, $job['url'] ?? null);
            $result->recordItem(['company' => $up['company']->name, 'job' => $title, 'url' => $job['url'] ?? null]);
        }
    }
}
