<?php

namespace App\Services\Discovery\Adapters;

use App\Services\Discovery\DiscoveryResult;
use App\Services\Discovery\ProspectIngestor;
use Illuminate\Http\Client\ConnectionException;

/**
 * Live public-procurement tenders from UK Contracts Finder (official, keyless OCDS API).
 * GET https://www.contractsfinder.service.gov.uk/Published/Notices/OCDS/Search
 *   params: publishedFrom, publishedTo (ISO 8601), stages (tender), limit (1-100), cursor
 * The API has no keyword filter, so we filter locally on title/description and CPV 72* (IT services).
 * Rate limit: on HTTP 403 the service asks clients to wait 5 minutes.
 *
 * Contact points are published by the buyer for supplier questions about THAT tender —
 * the right action is "respond to tender", not cold outreach.
 */
class UkContractsFinderAdapter extends AbstractDiscoveryAdapter
{
    use ProcessesOcdsReleases;

    protected function noticeUrl(array $rel): string
    {
        return 'https://www.contractsfinder.service.gov.uk/Notice/' . preg_replace('/^ocds-[a-z0-9]+-/', '', (string) ($rel['id'] ?? $rel['ocid'] ?? ''));
    }

    protected string $sourceSlug = 'uk_contracts_finder';
    protected string $sourceName = 'UK Contracts Finder (Public Tenders)';
    protected string $providerType = 'public_procurement';
    protected string $description = 'Open UK public-sector tenders for web, software, ERP and IT services (official OCDS API).';

    public const ENDPOINT = 'https://www.contractsfinder.service.gov.uk/Published/Notices/OCDS/Search';

    public function isConfigured(): bool
    {
        return true;
    }

    public function testConnection(): array
    {
        try {
            $res = $this->resilientHttp(15)->get(self::ENDPOINT, ['stages' => 'tender', 'limit' => 1, 'publishedFrom' => now()->subDays(3)->toIso8601String()]);

            return ['success' => $res->successful(), 'message' => 'Contracts Finder HTTP ' . $res->status(), 'details' => []];
        } catch (ConnectionException $e) {
            return ['success' => false, 'message' => $this->connectionError('Contracts Finder', $e), 'details' => []];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage(), 'details' => []];
        }
    }

    protected function fetchAndIngest(array $options, DiscoveryResult $result, ProspectIngestor $ingestor): void
    {
        $limit = (int) ($options['limit'] ?? 20);
        $days = (int) ($options['days'] ?? 14);
        $keywords = array_map('strtolower', $options['keywords'] ?? ['website', 'software', 'web application', 'digital', 'portal', 'crm', 'erp', 'app development', 'laravel', 'php']);

        $cursor = null;
        $pages = 0;
        $matched = 0;
        do {
            $params = ['stages' => 'tender', 'limit' => 100, 'publishedFrom' => now()->subDays($days)->startOfDay()->toIso8601String()];
            if ($cursor) {
                $params['cursor'] = $cursor;
            }
            try {
                $res = $this->resilientHttp(30)->acceptJson()->get(self::ENDPOINT, $params);
            } catch (ConnectionException $e) {
                $result->addError($this->connectionError('Contracts Finder', $e));
                break;
            }
            $pages++;
            if ($res->status() === 403) {
                $result->addError('Contracts Finder rate limit hit (HTTP 403). Retry after 5 minutes.');
                break;
            }
            if (!$res->successful()) {
                $result->addError('Contracts Finder HTTP ' . $res->status());
                break;
            }

            foreach ((array) $res->json('releases', []) as $rel) {
                if ($this->ingestOcdsRelease($rel, $keywords, $ingestor, $result) && ++$matched >= $limit) {
                    break 2;
                }
            }
            $next = $res->json('links.next');
            $cursor = $this->nextCursor($next);
        } while ($cursor && $pages < 5);
    }
}
