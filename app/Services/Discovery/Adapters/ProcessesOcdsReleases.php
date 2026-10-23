<?php

namespace App\Services\Discovery\Adapters;

use App\Services\Discovery\DiscoveryResult;
use App\Services\Discovery\ProspectIngestor;

/**
 * Shared OCDS (Open Contracting Data Standard) release handling for UK Contracts Finder and Find a Tender.
 * Keeps only OPEN IT tenders (CPV 72xxxxxx / 48xxxxxx or keyword match on word boundaries); never invents a person.
 */
trait ProcessesOcdsReleases
{
    abstract protected function noticeUrl(array $rel): string;

    /** @return bool true when the release was ingested */
    protected function ingestOcdsRelease(array $rel, array $keywords, ProspectIngestor $ingestor, DiscoveryResult $result, string $country = 'United Kingdom'): bool
    {
        $tender = $rel['tender'] ?? [];
        $title = trim((string) ($tender['title'] ?? ''));
        $desc = trim((string) ($tender['description'] ?? ''));
        $cpv = (string) ($tender['classification']['id'] ?? '');
        $hay = strtolower($title . ' ' . $desc);

        $isIt = str_starts_with($cpv, '72') || str_starts_with($cpv, '48');
        $kwHit = false;
        foreach ($keywords as $kw) {
            if ($kw !== '' && preg_match('/\b' . preg_quote($kw, '/') . '\b/u', $hay)) {
                $kwHit = true;
                break;
            }
        }
        if (!$isIt && !$kwHit) {
            return false;
        }
        $endDate = $tender['tenderPeriod']['endDate'] ?? null;
        if ($endDate && strtotime($endDate) < time()) {
            return false; // closed
        }

        $result->totalFound++;
        $buyerName = $rel['buyer']['name'] ?? null;
        $party = collect($rel['parties'] ?? [])->first(fn ($p) => in_array('buyer', (array) ($p['roles'] ?? [])) || ($p['name'] ?? null) === $buyerName) ?? [];
        $buyerName = $buyerName ?: ($party['name'] ?? null);
        if (!$buyerName) {
            return false;
        }
        $cp = $party['contactPoint'] ?? [];
        $noticeUrl = $this->noticeUrl($rel);

        $up = $ingestor->upsertCompany([
            'name' => $buyerName,
            'website' => $cp['url'] ?? null,
            'public_email' => $cp['email'] ?? null,
            'public_phone' => $cp['telephone'] ?? null,
            'city' => $party['address']['locality'] ?? null,
            'country' => $country,
            'industry' => 'Public Sector',
            'source_url' => $noticeUrl,
            'confidence' => 95,
        ], $result);
        if (!$up['company']) {
            return false;
        }
        $company = $up['company'];
        $services = $this->inferServices($title . ' ' . $desc);
        $value = $tender['value']['amount'] ?? null;

        $ingestor->addSignal($company, [
            'signal_type' => 'procurement_rfp',
            'title' => 'Tender: ' . mb_substr($title, 0, 220),
            'source_url' => $noticeUrl,
            'evidence_text' => mb_substr($desc, 0, 1500) . ($value ? " | Published value: {$value} " . ($tender['value']['currency'] ?? '') : '') . ($endDate ? " | Closes: {$endDate}" : ''),
            'observed_date' => isset($rel['date']) ? substr($rel['date'], 0, 10) : now()->toDateString(),
            'confidence' => 95,
            'relevant_service' => $services[0],
            'expiry_date' => $endDate ? substr($endDate, 0, 10) : now()->addDays(30)->toDateString(),
            'metadata' => ['ocid' => $rel['ocid'] ?? null, 'cpv' => $cpv, 'value' => $value, 'closes_at' => $endDate, 'source' => $this->sourceSlug . '_ocds', 'action' => 'respond_to_tender'],
        ], $result);

        // Only treat the contact as a person when it looks like a personal name (not "Procurement Team").
        $cpName = trim((string) ($cp['name'] ?? ''));
        $isPerson = (bool) preg_match('/^[\p{Lu}][\p{L}\'-]+(\s+[\p{Lu}][\p{L}\'-]+){1,2}$/u', $cpName)
            && !preg_match('/\b(team|procurement|commercial|contracts?|department|dept|office|unit|services|tenders?|purchasing|council|enquiries|admin)\b/i', $cpName);
        $names = $isPerson ? preg_split('/\s+/', $cpName, 2) : [null, null];
        $ingestor->ensureLead($company, array_filter([
            'first_name' => $names[0] ?? null,
            'last_name' => $names[1] ?? null,
            'email' => $cp['email'] ?? null,
            'phone' => $cp['telephone'] ?? null,
            'job_title' => 'Tender contact (published)',
            'is_tender_contact' => true,
            'confidence' => 95,
        ]), $result, $services, $noticeUrl, $rel['ocid'] ?? null);

        $result->recordItem(['company' => $buyerName, 'tender' => $title, 'closes' => $endDate, 'url' => $noticeUrl]);

        return true;
    }

    protected function nextCursor(?string $next): ?string
    {
        $q = $next ? parse_url($next, PHP_URL_QUERY) : null;
        if (!$q) {
            return null;
        }
        parse_str($q, $o);

        return $o['cursor'] ?? null;
    }
}
