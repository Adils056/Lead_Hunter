<?php

namespace App\Services\Discovery\Adapters;

use App\Services\Discovery\DiscoveryResult;
use App\Services\Discovery\ProspectIngestor;
use Illuminate\Http\Client\ConnectionException;

/**
 * UK Find a Tender Service (above-threshold UK public tenders), official keyless OCDS API.
 * GET https://www.find-tender.service.gov.uk/api/1.0/ocdsReleasePackages?updatedFrom=&stages=tender&limit=&cursor=
 * On 429/503 honour Retry-After (we stop this run and record the error).
 */
class UkFindATenderAdapter extends AbstractDiscoveryAdapter
{
    use ProcessesOcdsReleases;

    protected string $sourceSlug = 'uk_find_a_tender';
    protected string $sourceName = 'UK Find a Tender (Public Tenders)';
    protected string $providerType = 'public_procurement';
    protected string $description = 'Open above-threshold UK public tenders for IT/web/software (official OCDS API).';

    public const ENDPOINT = 'https://www.find-tender.service.gov.uk/api/1.0/ocdsReleasePackages';

    protected function noticeUrl(array $rel): string
    {
        $id = (string) ($rel['id'] ?? '');
        if (preg_match('/(\d{6}-\d{4})/', $id . ' ' . ($rel['ocid'] ?? ''), $m)) {
            return 'https://www.find-tender.service.gov.uk/Notice/' . $m[1];
        }

        return 'https://www.find-tender.service.gov.uk/Search/Results?ocid=' . urlencode((string) ($rel['ocid'] ?? $id));
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function testConnection(): array
    {
        try {
            $res = $this->resilientHttp(20)->acceptJson()->get(self::ENDPOINT, ['stages' => 'tender', 'limit' => 1, 'updatedFrom' => now()->subDays(2)->format('Y-m-d\TH:i:s')]);

            return ['success' => $res->successful(), 'message' => 'Find a Tender HTTP ' . $res->status(), 'details' => []];
        } catch (ConnectionException $e) {
            return ['success' => false, 'message' => $this->connectionError('Find a Tender', $e), 'details' => []];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage(), 'details' => []];
        }
    }

    protected function fetchAndIngest(array $options, DiscoveryResult $result, ProspectIngestor $ingestor): void
    {
        $limit = (int) ($options['limit'] ?? 20);
        $keywords = array_map('strtolower', $options['keywords'] ?? ['website', 'software', 'web application', 'digital', 'portal', 'crm', 'erp', 'app development']);
        $cursor = $options['cursor'] ?? null;
        $matched = 0;
        $pages = 0;
        do {
            $params = ['stages' => 'tender', 'limit' => 100, 'updatedFrom' => now()->subDays((int) ($options['days'] ?? 14))->format('Y-m-d\TH:i:s')];
            if ($cursor) {
                $params['cursor'] = $cursor;
            }
            try {
                $res = $this->resilientHttp(30)->acceptJson()->get(self::ENDPOINT, $params);
            } catch (ConnectionException $e) {
                $result->addError($this->connectionError('Find a Tender', $e));
                break;
            }
            $pages++;
            if (in_array($res->status(), [429, 503], true)) {
                $result->addError('Find a Tender asked us to slow down (HTTP ' . $res->status() . ', Retry-After ' . ($res->header('Retry-After') ?: '?') . 's).');
                break;
            }
            if (!$res->successful()) {
                $result->addError('Find a Tender HTTP ' . $res->status());
                break;
            }
            foreach ((array) $res->json('releases', []) as $rel) {
                if ($this->ingestOcdsRelease($rel, $keywords, $ingestor, $result) && ++$matched >= $limit) {
                    break 2;
                }
            }
            $cursor = $this->nextCursor($res->json('links.next')) ?? $res->json('cursor');
        } while ($cursor && $pages < 5);
    }
}
