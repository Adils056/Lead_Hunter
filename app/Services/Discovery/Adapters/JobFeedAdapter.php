<?php

namespace App\Services\Discovery\Adapters;

use App\Services\Discovery\DiscoveryResult;
use App\Services\Discovery\ProspectIngestor;
use Illuminate\Http\Client\ConnectionException;

/**
 * Free public job-board feeds (RemoteOK, Himalayas, Arbeitnow): companies advertising developer roles we can staff
 * or replace with a project -> company + developer_hiring signal + contact-less lead (the nightly team-page and
 * mailbox steps then find the decision maker). Every signal links back to the job on the board (their attribution
 * terms). The company website is taken only from the apply link or a description link that carries the company's
 * name, never guessed.
 */
abstract class JobFeedAdapter extends AbstractDiscoveryAdapter
{
    protected string $providerType = 'job_board';

    /** Board host(s) that are never the company's own site. */
    protected string $boardHost = '';

    /** @return list<array{id: string, company: string, title: string, tags: array, text: string, location: string, apply_url: ?string, url: string, date: ?string}> */
    abstract protected function jobs(array $options, DiscoveryResult $result): array;

    abstract protected function endpoint(): string;

    /** Location words -> approved-country names, beyond Hacker News' list (Arbeitnow is mostly Germany/EU). */
    public const EXTRA_COUNTRIES = [
        'Germany' => '/\b(Germany|Deutschland|Berlin|Munich|München|Hamburg|Frankfurt|Cologne|Köln|Stuttgart|Düsseldorf)\b/u',
        'Canada' => '/\b(Canada|Toronto|Vancouver|Montreal|Ottawa|Calgary)\b/',
    ];

    public function isConfigured(): bool
    {
        return (bool) config("leadpilot.sources.job_feeds.{$this->sourceSlug}", true);
    }

    public function testConnection(): array
    {
        try {
            $ok = $this->resilientHttp(20)->withHeaders(['User-Agent' => 'LeadPilot/1.0 (+' . config('app.url') . ')'])->get($this->endpoint())->successful();

            return ['success' => $ok, 'message' => $ok ? "{$this->sourceName} feed reachable." : "{$this->sourceName} feed did not answer.", 'details' => []];
        } catch (ConnectionException $e) {
            return ['success' => false, 'message' => $this->connectionError($this->sourceName, $e), 'details' => []];
        }
    }

    protected function fetchAndIngest(array $options, DiscoveryResult $result, ProspectIngestor $ingestor): void
    {
        $allowed = array_values((array) ($options['countries'] ?? []));
        $limit = max(1, (int) ($options['limit'] ?? 25));
        $new = 0;
        $seen = [];
        foreach ($this->jobs($options, $result) as $job) {
            if ($new >= $limit) {
                break;
            }
            $haystack = $job['title'] . ' ' . implode(' ', $job['tags']);
            if (!preg_match(HnWhoIsHiringAdapter::RELEVANT, $haystack)) {
                continue;
            }
            $result->totalFound++;
            $company = trim($job['company']);
            $country = $this->country($job['location'] . ' ' . $job['text']);
            if ($company === '' || isset($seen[strtolower($company)]) || preg_match(HnWhoIsHiringAdapter::REFUSES_AGENCIES, $job['text'])
                || !$country || ($allowed && !in_array($country, $allowed, true))) {
                $result->excludedCount++;
                continue;
            }
            $seen[strtolower($company)] = true;

            $up = $ingestor->upsertCompany([
                'name' => $company,
                'website' => $this->website($company, $job),
                'country' => $country,
                'source_url' => $job['url'],
                'confidence' => 75,
            ], $result);
            if (!$up['company']) {
                continue;
            }
            if (($board = $job['apply_url'] ? GreenhouseLeverWatchlistAdapter::boardFromUrl($job['apply_url']) : null) && !$up['company']->ats_board) {
                $up['company']->update(['ats_board' => $board]);
            }
            $services = $this->inferServices($haystack . ' ' . $job['text']);
            $ingestor->addSignal($up['company'], [
                'signal_type' => 'developer_hiring',
                'title' => mb_substr("Hiring: {$job['title']} ({$this->sourceName})", 0, 240),
                'source_url' => $job['url'], // link back to the job on the board (attribution)
                'evidence_text' => mb_substr(trim($job['title'] . ' — ' . preg_replace('/\s+/', ' ', strip_tags($job['text']))), 0, 600),
                'confidence' => 80,
                'relevant_service' => $services[0],
                'observed_date' => $job['date'] ?: now()->toDateString(),
                'expiry_date' => now()->addDays(45)->toDateString(),
                'metadata' => ['source' => $this->sourceSlug, 'job_id' => $job['id'], 'location' => $job['location'], 'attribution' => $this->sourceName],
            ], $result);
            $ingestor->ensureLead($up['company'], [], $result, $services, $job['url'], "{$this->sourceSlug}:{$job['id']}");
            $result->recordItem(['company' => $company, 'country' => $country, 'job' => $job['title'], 'url' => $job['url']]);
            $new++;
        }
    }

    protected function country(string $text): ?string
    {
        foreach (HnWhoIsHiringAdapter::COUNTRIES + self::EXTRA_COUNTRIES as $name => $re) {
            if (preg_match($re, $text)) {
                return $name;
            }
        }

        return null;
    }

    /** The company's own site: the apply link's host, or a description link, only when it carries the company's name. */
    protected function website(string $company, array $job): ?string
    {
        $links = array_filter([$job['apply_url']]);
        if (preg_match_all('#https?://[^\s<>"\')]+#i', html_entity_decode($job['text']), $m)) {
            $links = array_merge($links, $m[0]);
        }
        foreach ($links as $l) {
            $host = preg_replace('/^www\./', '', strtolower((string) parse_url($l, PHP_URL_HOST)));
            if ($host === '' || preg_match(HnWhoIsHiringAdapter::NOT_A_COMPANY_SITE, $host) || ($this->boardHost && str_ends_with($host, $this->boardHost))) {
                continue;
            }
            if (HnWhoIsHiringAdapter::siteMatchesName($host, $company)) {
                return 'https://' . $host;
            }
        }

        return null;
    }

    protected function date(mixed $v): ?string
    {
        if (is_numeric($v)) {
            return date('Y-m-d', (int) $v);
        }

        return is_string($v) && $v !== '' ? substr($v, 0, 10) : null;
    }
}
