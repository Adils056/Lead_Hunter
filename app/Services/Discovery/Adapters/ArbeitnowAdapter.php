<?php

namespace App\Services\Discovery\Adapters;

use App\Services\Discovery\DiscoveryResult;

/** Arbeitnow free job-board API (keyless; Europe, mostly Germany). Link back to the job on Arbeitnow is kept. */
class ArbeitnowAdapter extends JobFeedAdapter
{
    protected string $sourceSlug = 'arbeitnow';
    protected string $sourceName = 'Arbeitnow';
    protected string $description = 'European companies hiring developers on Arbeitnow (free API, job link kept on every signal).';
    protected string $boardHost = 'arbeitnow.com';

    protected function endpoint(): string
    {
        return 'https://www.arbeitnow.com/api/job-board-api';
    }

    protected function jobs(array $options, DiscoveryResult $result): array
    {
        $out = [];
        foreach ([1, 2, 3] as $page) {
            $res = $this->resilientHttp(30)->get($this->endpoint(), ['page' => $page]);
            if (!$res->successful()) {
                $result->addError('Arbeitnow HTTP ' . $res->status());
                break;
            }
            foreach ((array) $res->json('data', []) as $j) {
                if (empty($j['company_name'])) {
                    continue;
                }
                $out[] = [
                    'id' => (string) ($j['slug'] ?? md5($j['company_name'] . ($j['title'] ?? ''))), 'company' => (string) $j['company_name'], 'title' => (string) ($j['title'] ?? ''),
                    'tags' => array_merge((array) ($j['tags'] ?? []), (array) ($j['job_types'] ?? [])), 'text' => (string) ($j['description'] ?? ''),
                    'location' => (string) ($j['location'] ?? '') . (!empty($j['remote']) ? ' (remote)' : ''), 'apply_url' => null,
                    'url' => (string) ($j['url'] ?? 'https://www.arbeitnow.com'), 'date' => $this->date($j['created_at'] ?? null),
                ];
            }
        }

        return $out;
    }
}
