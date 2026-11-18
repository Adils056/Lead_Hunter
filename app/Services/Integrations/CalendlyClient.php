<?php

namespace App\Services\Integrations;

use Illuminate\Support\Facades\Http;

/** Calendly API v2, read-only: who the token belongs to, their scheduled events and each event's invitees. */
class CalendlyClient
{
    public function isConfigured(): bool
    {
        return trim((string) config('leadpilot.calendly.token')) !== '';
    }

    protected function get(string $urlOrPath, array $query = []): array
    {
        $url = str_starts_with($urlOrPath, 'http') ? $urlOrPath : rtrim((string) config('leadpilot.calendly.api'), '/') . $urlOrPath;
        $res = Http::withToken((string) config('leadpilot.calendly.token'))->acceptJson()->timeout(15)->retry(2, 500, throw: false)->get($url, $query);
        if (!$res->successful()) {
            throw new \RuntimeException('Calendly answered HTTP ' . $res->status() . ': ' . mb_substr((string) $res->body(), 0, 200));
        }

        return (array) $res->json();
    }

    /** The Calendly user the token belongs to: ['uri' => ..., 'name' => ..., 'timezone' => ...]. */
    public function me(): array
    {
        return (array) ($this->get('/users/me')['resource'] ?? []);
    }

    /** Scheduled events of a user starting after $minStart, every page. $status: active | canceled. */
    public function events(string $userUri, \DateTimeInterface $minStart, string $status = 'active'): array
    {
        $out = [];
        $query = ['user' => $userUri, 'min_start_time' => \Carbon\Carbon::instance($minStart)->utc()->format('Y-m-d\TH:i:s\Z'), 'status' => $status, 'count' => 100, 'sort' => 'start_time:asc'];
        for ($page = 0; $page < 10; $page++) {
            $body = $this->get('/scheduled_events', $query);
            $out = array_merge($out, (array) ($body['collection'] ?? []));
            $next = $body['pagination']['next_page_token'] ?? null;
            if (!$next) {
                break;
            }
            $query['page_token'] = $next;
        }

        return $out;
    }

    public function invitees(string $eventUri): array
    {
        return (array) ($this->get(rtrim($eventUri, '/') . '/invitees', ['count' => 100])['collection'] ?? []);
    }
}
