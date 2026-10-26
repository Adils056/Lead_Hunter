<?php

namespace App\Services\Discovery\Adapters;

use App\Services\Discovery\DiscoveryResult;
use App\Services\Discovery\ProspectIngestor;
use Illuminate\Http\Client\ConnectionException;

/**
 * Hacker News "Ask HN: Who is hiring?" (monthly, posted by the account "whoishiring") through the official Algolia
 * HN API (keyless): companies announcing developer roles, usually with a website and a contact address they publish
 * to be contacted. Only posts that match our services, sit in an approved country and do not refuse agencies are kept.
 */
class HnWhoIsHiringAdapter extends AbstractDiscoveryAdapter
{
    protected string $sourceSlug = 'hn_hiring';
    protected string $sourceName = 'Hacker News: Who is hiring';
    protected string $providerType = 'job_board';
    protected string $description = 'Companies hiring developers in the monthly Hacker News "Who is hiring?" thread (official Algolia HN API).';

    public const API = 'https://hn.algolia.com/api/v1';

    /** Roles we can staff or replace with a project. */
    public const RELEVANT = '/\b(php|laravel|symfony|wordpress|woocommerce|shopify|magento|web developer|web dev|full[- ]?stack|front[- ]?end|back[- ]?end|react|vue|node|mobile|ios|android|flutter|contract|contractor|freelance)\b/i';

    /** Posts that say they do not want agencies or outsourcing. */
    public const REFUSES_AGENCIES = '/\bno\s+(agencies|agency|recruiters|outsourc|contractors|third[- ]part)/i';

    /** Location words -> approved-country names (anything else is skipped: we only write where outreach is allowed). */
    public const COUNTRIES = [
        'United States' => '/\b(USA|U\.S\.|US|United States|NYC|New York|San Francisco|SF|Bay Area|Seattle|Austin|Boston|Los Angeles|Chicago|Denver|Miami|Atlanta|Remote \(US\)|US[- ]remote|remote[- ]US)\b/',
        'United Kingdom' => '/\b(UK|U\.K\.|United Kingdom|London|Manchester|Edinburgh|Bristol|Cambridge|Oxford)\b/',
        'United Arab Emirates' => '/\b(UAE|Dubai|Abu Dhabi)\b/',
        'Saudi Arabia' => '/\b(Saudi|Riyadh|Jeddah)\b/',
        'Australia' => '/\b(Australia|Sydney|Melbourne|Brisbane|Perth)\b/',
        'Pakistan' => '/\b(Pakistan|Lahore|Karachi|Islamabad)\b/',
    ];

    public function isConfigured(): bool
    {
        return true;
    }

    public function testConnection(): array
    {
        try {
            $ok = $this->resilientHttp(15)->get(self::API . '/search_by_date', ['tags' => 'story,author_whoishiring', 'hitsPerPage' => 1])->successful();

            return ['success' => $ok, 'message' => $ok ? 'Algolia HN API reachable.' : 'Algolia HN API did not answer.', 'details' => []];
        } catch (ConnectionException $e) {
            return ['success' => false, 'message' => $this->connectionError('Algolia HN API', $e), 'details' => []];
        }
    }

    protected function fetchAndIngest(array $options, DiscoveryResult $result, ProspectIngestor $ingestor): void
    {
        $stories = $this->resilientHttp(20)->get(self::API . '/search_by_date', ['tags' => 'story,author_whoishiring', 'hitsPerPage' => 10]);
        $story = collect($stories->json('hits', []))->first(fn ($h) => stripos((string) ($h['title'] ?? ''), 'who is hiring') !== false);
        if (!$story) {
            $result->addError('No "Who is hiring?" thread found.');

            return;
        }
        $thread = $this->resilientHttp(60)->get(self::API . '/items/' . $story['objectID']);
        if (!$thread->successful()) {
            $result->addError('HN thread HTTP ' . $thread->status());

            return;
        }

        $allowed = array_values((array) ($options['countries'] ?? []));
        $limit = max(1, (int) ($options['limit'] ?? 25));
        $new = 0;
        foreach ((array) $thread->json('children', []) as $post) {
            if ($new >= $limit) {
                break;
            }
            $html = (string) ($post['text'] ?? '');
            if ($html === '' || !preg_match(self::RELEVANT, strip_tags($html))) {
                continue;
            }
            $result->totalFound++;
            $parsed = self::parsePost($html);
            if (preg_match(self::REFUSES_AGENCIES, $parsed['text']) || !$parsed['company']
                || !$parsed['country'] || ($allowed && !in_array($parsed['country'], $allowed, true))) {
                $result->excludedCount++;
                continue;
            }

            $postUrl = "https://news.ycombinator.com/item?id={$post['id']}";
            $up = $ingestor->upsertCompany([
                'name' => $parsed['company'],
                'website' => $parsed['website'],
                'country' => $parsed['country'],
                'external_id' => "hn:{$post['id']}",
                'source_url' => $postUrl,
                'public_email' => $parsed['email'],
                'confidence' => 80,
            ], $result);
            if (!$up['company']) {
                continue;
            }
            if ($parsed['ats_board'] && !$up['company']->ats_board) {
                $up['company']->update(['ats_board' => $parsed['ats_board']]);
            }
            $services = $this->inferServices($parsed['text']);
            $ingestor->addSignal($up['company'], [
                'signal_type' => 'developer_hiring',
                'title' => 'Hiring developers (Hacker News "' . ($story['title'] ?? 'Who is hiring?') . '")',
                'source_url' => $postUrl,
                'evidence_text' => mb_substr($parsed['text'], 0, 600),
                'confidence' => 85,
                'relevant_service' => $services[0],
                'observed_date' => isset($post['created_at']) ? substr((string) $post['created_at'], 0, 10) : now()->toDateString(),
                'expiry_date' => now()->addDays(45)->toDateString(),
                'metadata' => ['source' => 'hn_who_is_hiring', 'hn_id' => $post['id'], 'location' => $parsed['location']],
            ], $result);
            $ingestor->ensureLead($up['company'], array_filter(['email' => $parsed['email']]), $result, $services, $postUrl, "hn:{$post['id']}");
            $result->recordItem(['company' => $parsed['company'], 'country' => $parsed['country'], 'post' => $postUrl, 'email' => $parsed['email']]);
            $new++;
        }
    }

    /**
     * First line is by convention "Company | Role | Location | REMOTE/ONSITE | ...". Returns the company, its website
     * (first link that is not HN or a job board), any linked job board, a published e-mail and the approved country.
     *
     * @return array{company: ?string, website: ?string, ats_board: ?string, email: ?string, country: ?string, location: ?string, text: string}
     */
    public static function parsePost(string $html): array
    {
        $decoded = html_entity_decode($html, ENT_QUOTES | ENT_HTML5);
        $firstLine = trim(strip_tags(preg_split('#<p>#i', $decoded)[0] ?? ''));
        $parts = array_map('trim', explode('|', $firstLine));
        $company = $parts[0] !== '' && mb_strlen($parts[0]) <= 80 ? preg_replace('/\s*\(.*?\)\s*$/', '', $parts[0]) : null;

        $links = [];
        if (preg_match_all('#href="([^"]+)"#i', $decoded, $m)) {
            $links = $m[1];
        }
        if (preg_match_all('#https?://[^\s<>"]+#i', strip_tags($decoded), $m2)) {
            $links = array_merge($links, $m2[0]);
        }
        $website = null;
        $board = null;
        foreach ($links as $l) {
            $l = rtrim($l, '.,);');
            $board ??= GreenhouseLeverWatchlistAdapter::boardFromUrl($l);
            $host = strtolower((string) parse_url($l, PHP_URL_HOST));
            if ($website === null && $host !== '' && !preg_match('/(ycombinator\.com|greenhouse\.io|lever\.co|ashbyhq\.com|workable\.com|linkedin\.com|github\.com|notion\.site|google\.com|forms\.gle|wellfound\.com|angel\.co)$/', $host)) {
                $website = 'https://' . preg_replace('/^www\./', '', $host);
            }
        }

        $text = trim(preg_replace('/\s+/', ' ', strip_tags(str_ireplace(['<p>', '<br>'], ' ', $decoded))));
        $email = preg_match('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $text, $em) ? strtolower($em[0]) : null;

        $location = implode(' | ', array_slice($parts, 1));
        $country = null;
        foreach (self::COUNTRIES as $name => $re) {
            if (preg_match($re, $location)) {
                $country = $name;
                break;
            }
        }

        return ['company' => $company, 'website' => $website, 'ats_board' => $board, 'email' => $email, 'country' => $country, 'location' => $location ?: null, 'text' => $text];
    }
}
