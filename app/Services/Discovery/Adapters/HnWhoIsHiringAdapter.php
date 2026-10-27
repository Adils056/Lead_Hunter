<?php

namespace App\Services\Discovery\Adapters;

use App\Services\Discovery\DiscoveryResult;
use App\Services\Discovery\ProspectIngestor;
use Illuminate\Http\Client\ConnectionException;

/**
 * Two monthly Hacker News threads (posted by the account "whoishiring"), read through the official keyless Algolia
 * HN API. Both carry a website and an address the poster publishes to be contacted:
 *   - "Ask HN: Who is hiring?"                  companies announcing developer roles  -> developer_hiring signal
 *   - "Ask HN: Freelancer? Seeking freelancer?" only the SEEKING FREELANCER posts     -> vendor_request (asked for this work)
 * Only posts that match our services, sit in an approved country and do not refuse agencies are kept.
 */
class HnWhoIsHiringAdapter extends AbstractDiscoveryAdapter
{
    protected string $sourceSlug = 'hn_hiring';
    protected string $sourceName = 'Hacker News: Who is hiring';
    protected string $providerType = 'job_board';
    protected string $description = 'Companies hiring developers or seeking a freelancer in the monthly Hacker News threads (official Algolia HN API).';

    /** title fragment => what a post in that thread means */
    public const THREADS = [
        'who is hiring' => ['signal' => 'developer_hiring', 'confidence' => 85, 'title' => 'Hiring developers', 'post' => null],
        'seeking freelancer' => ['signal' => 'vendor_request', 'confidence' => 90, 'title' => 'Looking for a freelancer', 'post' => '/^\s*SEEKING\s+FREELANCER/i'],
    ];

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

    /** Links in a post that are never the company's own website (job boards, social, video, news, shorteners). */
    public const NOT_A_COMPANY_SITE = '/(^|\.)(ycombinator\.com|greenhouse\.io|grnh\.se|lever\.co|ashbyhq\.com|workable\.com|gem\.com|linkedin\.com|github\.com|notion\.(site|so)|google\.com|forms\.gle|wellfound\.com|angel\.co|youtube\.com|youtu\.be|twitter\.com|x\.com|medium\.com|substack\.com|bit\.ly|tinyurl\.com|workatastartup\.com|rippling\.com|bamboohr\.com|jobvite\.com|smartrecruiters\.com|recruitee\.com|breezy\.hr|teamtailor\.com|dover\.(com|io)|typeform\.com|calendly\.com|airtable\.com|mercurynews\.com|techcrunch\.com|bloomberg\.com|forbes\.com|reuters\.com|nytimes\.com|wsj\.com)$/';

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
        $stories = collect($this->resilientHttp(20)->get(self::API . '/search_by_date', ['tags' => 'story,author_whoishiring', 'hitsPerPage' => 10])->json('hits', []));
        $limit = max(1, (int) ($options['limit'] ?? 25));
        $new = 0;
        foreach (self::THREADS as $match => $kind) {
            $story = $stories->first(fn ($h) => stripos((string) ($h['title'] ?? ''), $match) !== false);
            if (!$story) {
                $result->addError("No \"{$match}\" thread found.");
                continue;
            }
            $thread = $this->resilientHttp(60)->get(self::API . '/items/' . $story['objectID']);
            if (!$thread->successful()) {
                $result->addError('HN thread HTTP ' . $thread->status());
                continue;
            }
            $new += $this->ingestThread((array) $thread->json('children', []), $story, $kind, $options, $limit - $new, $result, $ingestor);
            if ($new >= $limit) {
                break;
            }
        }
    }

    protected function ingestThread(array $posts, array $story, array $kind, array $options, int $limit, DiscoveryResult $result, ProspectIngestor $ingestor): int
    {
        $allowed = array_values((array) ($options['countries'] ?? []));
        $new = 0;
        foreach ($posts as $post) {
            if ($new >= $limit) {
                break;
            }
            $html = (string) ($post['text'] ?? '');
            $plain = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5);
            if ($html === '' || ($kind['post'] && !preg_match($kind['post'], $plain)) || !preg_match(self::RELEVANT, $plain)) {
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
                'signal_type' => $kind['signal'],
                'title' => $kind['title'] . ' (Hacker News "' . ($story['title'] ?? 'Who is hiring?') . '")',
                'source_url' => $postUrl,
                'evidence_text' => mb_substr($parsed['text'], 0, 600),
                'confidence' => $kind['confidence'],
                'relevant_service' => $services[0],
                'observed_date' => isset($post['created_at']) ? substr((string) $post['created_at'], 0, 10) : now()->toDateString(),
                'expiry_date' => now()->addDays(45)->toDateString(),
                'metadata' => ['source' => 'hn_' . str_replace(' ', '_', $kind['signal']), 'hn_id' => $post['id'], 'location' => $parsed['location']],
            ], $result);
            $ingestor->ensureLead($up['company'], array_filter(['email' => $parsed['email']]), $result, $services, $postUrl, "hn:{$post['id']}");
            $result->recordItem(['company' => $parsed['company'], 'country' => $parsed['country'], 'post' => $postUrl, 'email' => $parsed['email'], 'kind' => $kind['signal']]);
            $new++;
        }

        return $new;
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
        $board = null;
        $candidates = [];
        foreach ($links as $l) {
            $l = rtrim($l, '.,);');
            $board ??= GreenhouseLeverWatchlistAdapter::boardFromUrl($l);
            $host = preg_replace('/^www\./', '', strtolower((string) parse_url($l, PHP_URL_HOST)));
            if ($host !== '' && !preg_match(self::NOT_A_COMPANY_SITE, $host)) {
                $candidates[] = $host;
            }
        }

        $text = trim(preg_replace('/\s+/', ' ', strip_tags(str_ireplace(['<p>', '<br>'], ' ', $decoded))));
        $email = preg_match('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $text, $em) ? strtolower($em[0]) : null;

        // The website is the poster's business e-mail domain, else a link carrying the company's name. A post that
        // only links a job board, a video or a news article gets no website: better none than someone else's.
        $free = (array) config('leadpilot.free_mail_domains', []);
        $mailHost = $email ? substr(strrchr($email, '@'), 1) : null;
        $seeking = $company !== null && preg_match('/^seeking\b/i', $company);
        $host = match (true) {
            $mailHost && !in_array($mailHost, $free, true) && !preg_match(self::NOT_A_COMPANY_SITE, $mailHost) => $mailHost,
            $seeking => $candidates[0] ?? null, // no name to compare: the poster's own link
            default => collect($candidates)->first(fn ($h) => self::siteMatchesName($h, (string) $company)),
        };
        $website = $host ? 'https://' . $host : null;
        if ($seeking) {
            // "SEEKING FREELANCER | Location | ..." names no company: use the poster's own site, never invent one
            $company = $host ? ucfirst(explode('.', $host)[0]) : null;
        }

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

    /** "Curai" ~ curaihealth.com, "Sidekick Labs" ~ sidekicklabs.com; "Paramark" !~ mercurynews.com. */
    public static function siteMatchesName(string $host, string $company): bool
    {
        $label = preg_replace('/[^a-z0-9]/', '', explode('.', preg_replace('/^www\./', '', strtolower($host)))[0]);
        $slug = preg_replace('/[^a-z0-9]/', '', strtolower($company));
        $first = preg_replace('/[^a-z0-9]/', '', strtolower(strtok(trim($company), ' ') ?: ''));
        if (strlen($label) < 3 || $slug === '') {
            return false;
        }

        return (strlen($first) >= 3 && str_contains($label, $first)) || str_contains($slug, $label);
    }
}
