<?php

namespace App\Services\Discovery\Adapters;

use App\Services\Discovery\DiscoveryResult;
use App\Services\Discovery\ProspectIngestor;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * EU Tenders Electronic Daily — TED API v3 anonymous notice search (no key for search).
 * POST https://api.ted.europa.eu/v3/notices/search  {query, fields[], limit, page, paginationMode}
 *
 * LIVE-VERIFIED 2026-10-23 from a networked machine (HTTP 200, 3 263 notices for the CPV query). Real shapes:
 *   notices[]                         top level, plus totalNoticeCount, iterationNextToken, timedOut
 *   publication-number                "696544-2026"
 *   notice-title                      {eng: "...", deu: "...", ...}   (3-letter lower-case language keys; 'eng' present)
 *   title-proc                        {slk: "..."}                     (original-language procedure title)
 *   buyer-name                        {slk: ["Org A", "Org B"]}       (map of language -> list of buyer names)
 *   buyer-email / buyer-country / buyer-internet-address / organisation-tel-buyer   plain lists, one entry per buyer
 *   deadline-receipt-tender-date-lot  ["2026-11-10+02:00", ...]       (one entry PER LOT, date + UTC offset)
 *   publication-date                  "2026-10-09+02:00"
 *   form-type                         "competition" | "result" | ...   (results = award notices, not open tenders)
 *   notice-type                       "cn-standard" | "can-standard" | ...
 *   links.html.ENG                    https://ted.europa.eu/en/notice/-/detail/{publication-number}
 * `fields` must not be empty; an unknown field name returns HTTP 400 listing the supported names.
 */
class EuTedAdapter extends AbstractDiscoveryAdapter
{
    protected string $sourceSlug = 'eu_ted';
    protected string $sourceName = 'EU TED (European Public Tenders)';
    protected string $providerType = 'public_procurement';
    protected string $description = 'Open EU public tenders for IT services (CPV 72/48) via TED API v3.';

    public const ENDPOINT = 'https://api.ted.europa.eu/v3/notices/search';
    public const FIELDS = ['publication-number', 'notice-title', 'title-proc', 'buyer-name', 'buyer-email', 'buyer-country', 'buyer-internet-address',
        'organisation-tel-buyer', 'classification-cpv', 'deadline-receipt-tender-date-lot', 'publication-date', 'form-type', 'notice-type'];

    public function isConfigured(): bool
    {
        return true;
    }

    public function testConnection(): array
    {
        try {
            $res = $this->http(20)->post(self::ENDPOINT, ['query' => $this->query(3), 'fields' => ['publication-number'], 'limit' => 1, 'page' => 1, 'paginationMode' => 'PAGE_NUMBER']);

            return ['success' => $res->successful(), 'message' => 'TED HTTP ' . $res->status() . ($res->successful() ? '' : ' ' . mb_substr($res->body(), 0, 200)), 'details' => []];
        } catch (ConnectionException $e) {
            return ['success' => false, 'message' => 'TED unreachable (DNS/network): ' . mb_substr($e->getMessage(), 0, 160), 'details' => []];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage(), 'details' => []];
        }
    }

    protected function http(int $timeout = 40)
    {
        return Http::connectTimeout(10)->timeout($timeout)->acceptJson();
    }

    /** Expert-search query: IT CPV codes, recent, and only calls for competition (not award results). */
    public function query(int $days, array $countries = []): string
    {
        $q = 'classification-cpv IN (72000000 72200000 72210000 72212000 72220000 72400000 72413000 48000000) AND publication-date>=' . now()->subDays($days)->format('Ymd')
            . ' AND form-type=competition';
        if ($countries) {
            $q .= ' AND buyer-country IN (' . implode(' ', array_map('strtoupper', $countries)) . ')';
        }

        return $q;
    }

    /** First usable scalar from a TED value: scalar, list, or {lang: value|list} map (English preferred). */
    public static function val(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        if (is_scalar($v)) {
            $s = trim((string) $v);

            return $s === '' ? null : $s;
        }
        if (is_array($v)) {
            foreach (['eng', 'ENG', 'en'] as $lang) {
                if (isset($v[$lang])) {
                    return self::val($v[$lang]);
                }
            }
            $first = reset($v);

            return $first === false ? null : self::val($first);
        }

        return null;
    }

    /**
     * Latest lot deadline as Y-m-d (lots share or differ; the latest still-open one is what matters), null if none parseable.
     * TED sends "2026-11-10+02:00" (a calendar date plus the buyer's UTC offset); the date is kept literally —
     * converting through strtotime() would shift it to the previous day in UTC.
     */
    public static function deadline(mixed $v): ?string
    {
        $dates = [];
        foreach (is_array($v) ? $v : [$v] as $d) {
            if (is_array($d)) {
                $d = self::val($d);
            }
            if ($d && preg_match('/^(\d{4}-\d{2}-\d{2})/', (string) $d, $m)) {
                $dates[] = $m[1];
            } elseif ($d && ($t = strtotime((string) $d)) !== false) {
                $dates[] = date('Y-m-d', $t);
            }
        }

        return $dates ? max($dates) : null;
    }

    protected function fetchAndIngest(array $options, DiscoveryResult $result, ProspectIngestor $ingestor): void
    {
        $limit = min(100, (int) ($options['limit'] ?? 20));
        try {
            $res = $this->http(40)->post(self::ENDPOINT, [
                'query' => $this->query((int) ($options['days'] ?? 14), (array) ($options['country_codes'] ?? [])),
                'fields' => self::FIELDS,
                'limit' => $limit,
                'page' => 1,
                'paginationMode' => 'PAGE_NUMBER',
            ]);
        } catch (ConnectionException $e) {
            $result->addError('TED unreachable (DNS/network): ' . mb_substr($e->getMessage(), 0, 160) . ' — check DNS (try 8.8.8.8 / 1.1.1.1), firewall/proxy and the curl CA bundle.');

            return;
        }
        if (!$res->successful()) {
            $result->addError('TED HTTP ' . $res->status() . ': ' . mb_substr($res->body(), 0, 300));

            return;
        }
        foreach ((array) $res->json('notices', []) as $n) {
            $buyer = self::val($n['buyer-name'] ?? null);
            $pub = self::val($n['publication-number'] ?? null);
            if (!$buyer || !$pub) {
                continue;
            }
            if (($n['form-type'] ?? 'competition') !== 'competition') {
                continue; // award result / change notice: nobody is buying any more
            }
            $deadline = self::deadline($n['deadline-receipt-tender-date-lot'] ?? null);
            if ($deadline && strtotime($deadline . ' 23:59:59') < time()) {
                continue;
            }
            $result->totalFound++;
            $title = self::val($n['notice-title'] ?? null) ?? self::val($n['title-proc'] ?? null) ?? 'EU tender';
            $url = 'https://ted.europa.eu/en/notice/-/detail/' . rawurlencode($pub);
            $email = self::val($n['buyer-email'] ?? null);
            $up = $ingestor->upsertCompany([
                'name' => $buyer,
                'website' => self::val($n['buyer-internet-address'] ?? null),
                'public_email' => $email,
                'public_phone' => self::val($n['organisation-tel-buyer'] ?? null),
                'country' => self::val($n['buyer-country'] ?? null),
                'industry' => 'Public Sector',
                'source_url' => $url,
                'confidence' => 90,
            ], $result);
            if (!$up['company']) {
                continue;
            }
            $services = $this->inferServices($title);
            $ingestor->addSignal($up['company'], [
                'signal_type' => 'procurement_rfp',
                'title' => 'EU tender: ' . mb_substr($title, 0, 220),
                'source_url' => $url,
                'evidence_text' => "TED notice {$pub}: {$title}" . ($deadline ? " | Deadline: {$deadline}" : ''),
                'confidence' => 90,
                'relevant_service' => $services[0],
                'expiry_date' => $deadline ?: now()->addDays(30)->toDateString(),
                'metadata' => ['publication_number' => $pub, 'cpv' => $n['classification-cpv'] ?? null, 'notice_type' => $n['notice-type'] ?? null,
                    'form_type' => $n['form-type'] ?? null, 'source' => 'eu_ted', 'action' => 'respond_to_tender'],
            ], $result);
            $ingestor->ensureLead($up['company'], array_filter(['email' => $email, 'job_title' => 'Tender contact (published)', 'is_tender_contact' => true]), $result, $services, $url, $pub);
            $result->recordItem(['company' => $buyer, 'tender' => $title, 'url' => $url, 'deadline' => $deadline]);
        }
    }
}
