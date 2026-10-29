<?php

namespace App\Services\Discovery\Adapters;

use App\Services\Discovery\DiscoveryResult;

/** Himalayas public jobs API (keyless, 20 per page). The job link on Himalayas is kept as the source. */
class HimalayasAdapter extends JobFeedAdapter
{
    protected string $sourceSlug = 'himalayas';
    protected string $sourceName = 'Himalayas';
    protected string $description = 'Remote companies hiring developers on Himalayas (public API, job link kept on every signal).';
    protected string $boardHost = 'himalayas.app';

    protected function endpoint(): string
    {
        return 'https://himalayas.app/jobs/api';
    }

    protected function jobs(array $options, DiscoveryResult $result): array
    {
        $out = [];
        foreach ([0, 20, 40, 60, 80] as $offset) {
            $res = $this->resilientHttp(30)->get($this->endpoint(), ['limit' => 20, 'offset' => $offset]);
            if (!$res->successful()) {
                $result->addError('Himalayas HTTP ' . $res->status());
                break;
            }
            foreach ((array) $res->json('jobs', []) as $j) {
                if (empty($j['companyName'])) {
                    continue;
                }
                $out[] = [
                    'id' => (string) ($j['guid'] ?? md5(($j['companyName'] ?? '') . ($j['title'] ?? ''))), 'company' => (string) $j['companyName'], 'title' => (string) ($j['title'] ?? ''),
                    'tags' => array_merge((array) ($j['categories'] ?? []), (array) ($j['parentCategories'] ?? [])),
                    'text' => (string) ($j['excerpt'] ?? '') . ' ' . (string) ($j['description'] ?? ''),
                    'location' => implode(', ', (array) ($j['locationRestrictions'] ?? [])), 'apply_url' => $j['applicationLink'] ?? null,
                    'url' => (string) ($j['guid'] ?? $j['applicationLink'] ?? 'https://himalayas.app/jobs'), 'date' => $this->date($j['pubDate'] ?? null),
                ];
            }
            if (count((array) $res->json('jobs', [])) < 20) {
                break;
            }
        }

        return $out;
    }
}
