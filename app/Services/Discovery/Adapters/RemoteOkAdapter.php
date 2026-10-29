<?php

namespace App\Services\Discovery\Adapters;

use App\Services\Discovery\DiscoveryResult;

/** RemoteOK public API (keyless). Terms: link back to the job on Remote OK and name it as the source. */
class RemoteOkAdapter extends JobFeedAdapter
{
    protected string $sourceSlug = 'remoteok';
    protected string $sourceName = 'Remote OK';
    protected string $description = 'Companies hiring developers on Remote OK (public API, attribution link kept on every signal).';
    protected string $boardHost = 'remoteok.com';

    protected function endpoint(): string
    {
        return 'https://remoteok.com/api';
    }

    protected function jobs(array $options, DiscoveryResult $result): array
    {
        $res = $this->resilientHttp(30)->withHeaders(['User-Agent' => 'LeadPilot/1.0 (+' . config('app.url') . ')'])->get($this->endpoint());
        if (!$res->successful()) {
            $result->addError('Remote OK HTTP ' . $res->status());

            return [];
        }

        return collect((array) $res->json())->filter(fn ($j) => is_array($j) && !empty($j['id']) && !empty($j['company']))
            ->map(fn ($j) => [
                'id' => (string) $j['id'], 'company' => (string) $j['company'], 'title' => (string) ($j['position'] ?? ''), 'tags' => (array) ($j['tags'] ?? []),
                'text' => (string) ($j['description'] ?? ''), 'location' => (string) ($j['location'] ?? ''), 'apply_url' => $j['apply_url'] ?? null,
                'url' => (string) ($j['url'] ?? "https://remoteok.com/remote-jobs/{$j['id']}"), 'date' => $this->date($j['date'] ?? $j['epoch'] ?? null),
            ])->values()->all();
    }
}
