<?php

namespace App\Services\Enrichment;

use App\Models\Company;
use App\Models\Lead;
use App\Services\Audit\SafeFetcher;
use App\Services\Audit\WebsiteAuditService;
use App\Support\CompanyName;
use Illuminate\Support\Str;

/**
 * The website of a company the source listed without one. Candidates come from the company's own business e-mail
 * domain, then from its name (acmedental.co.uk, acme-dental.com ...). A candidate is kept only when the page is live
 * (not parked, not a placeholder, not a bot wall) AND it proves it is this business: the name is in the title /
 * site name / main heading, or the company's phone number is on the page. An e-mail domain needs only a live page —
 * the business already uses it. Nothing is guessed: no proof, no website.
 */
class WebsiteFinder
{
    /** Words that do not identify a business ("Acme Dental Ltd" -> acme, dental). */
    protected const STOP = ['the', 'and', 'of', 'co', 'ltd', 'llc', 'inc', 'plc', 'llp', 'pvt', 'private', 'limited', 'company', 'corp', 'corporation', 'group', 'gmbh', 'pty', 'fze', 'fzc', 'fzco', 'wll', 'est', 'smc'];

    protected const TLDS = [
        'United Kingdom' => ['co.uk', 'com', 'uk'], 'United States' => ['com', 'net'], 'Pakistan' => ['com', 'pk', 'com.pk'],
        'United Arab Emirates' => ['com', 'ae'], 'Saudi Arabia' => ['com', 'sa', 'com.sa'], 'Australia' => ['com.au', 'com'],
        'Canada' => ['ca', 'com'], 'Germany' => ['de', 'com'],
    ];

    /** Hosts that are someone else's site (social, directories, domain sellers), not the business's own. */
    protected const NOT_OWN_SITE = ['facebook.com', 'instagram.com', 'linkedin.com', 'twitter.com', 'x.com', 'youtube.com', 'tiktok.com', 'google.com',
        'yelp.com', 'tripadvisor.com', 'yell.com', 'wa.me', 'whatsapp.com', 'linktr.ee', 'godaddy.com', 'sedo.com', 'dan.com', 'afternic.com',
        'hugedomains.com', 'wixsite.com', 'business.site'];

    protected SafeFetcher $fetcher;

    public function __construct(protected WebsiteAuditService $audits)
    {
        $this->fetcher = new SafeFetcher((string) config('leadpilot.sources.website_crawler.user_agent'), (int) config('leadpilot.website_finder.timeout', 8));
    }

    /** @return array{website: ?string, outcome: string, evidence: ?string, tried: list<string>} outcome: found | not_found | no_name */
    public function find(Company $company): array
    {
        $tried = [];
        foreach ($this->candidates($company) as [$host, $viaEmail]) {
            if (count($tried) >= (int) config('leadpilot.website_finder.max_candidates', 8)) {
                break;
            }
            if ($this->fetcher->blockReason("https://{$host}/") !== null) {
                continue; // does not resolve (most name guesses): no request made
            }
            $tried[] = $host;
            $proof = $this->check($company, "https://{$host}/", $viaEmail);
            if ($proof) {
                return ['website' => $proof['url'], 'outcome' => 'found', 'evidence' => $proof['evidence'], 'tried' => $tried];
            }
        }

        return ['website' => null, 'outcome' => $this->tokens($company->name) ? 'not_found' : 'no_name', 'evidence' => null, 'tried' => $tried];
    }

    /** @return list<array{0: string, 1: bool}> host and whether it is the company's own e-mail domain */
    public function candidates(Company $company): array
    {
        $out = [];
        $free = (array) config('leadpilot.free_mail_domains', []);
        $domains = array_filter(array_merge([$company->domain, Company::domainFromEmail($company->public_email)],
            $company->leads()->whereNotNull('email')->limit(5)->pluck('email')->map(fn ($e) => Company::domainFromEmail($e))->all()));
        foreach (array_unique($domains) as $d) {
            $d = strtolower((string) $d);
            // a domain other companies' contacts also use is a mail provider (btinternet.com, an ISP), not this business
            $shared = Lead::where('email', 'like', '%@' . $d)->where('company_id', '<>', $company->id)->exists();
            if ($d !== '' && !in_array($d, $free, true) && !$shared) {
                $out[] = [$d, true];
            }
        }

        $tokens = $this->tokens($company->name);
        if ($tokens) {
            $slugs = [implode('', $tokens)];
            if (count($tokens) > 1 && count($tokens) <= 4) {
                $slugs[] = implode('-', $tokens);
            }
            if (count($tokens) >= 3) {
                $slugs[] = $tokens[0] . $tokens[1];
            }
            $tlds = self::TLDS[$company->country] ?? ['com'];
            foreach ($slugs as $s) {
                if (strlen(str_replace('-', '', $s)) < 4 || strlen($s) > 40) {
                    continue; // "abc.com" proves nothing; very long names are not domains
                }
                foreach ($tlds as $tld) {
                    $out[] = ["{$s}.{$tld}", false];
                }
            }
        }

        $seen = [];

        return array_values(array_filter($out, function ($c) use (&$seen) {
            $key = preg_replace('/^www\./', '', $c[0]);

            return !isset($seen[$key]) && ($seen[$key] = true);
        }));
    }

    /** Lower-case ASCII words that identify the business; [] for non-Latin or empty names. */
    public function tokens(?string $name): array
    {
        $clean = CompanyName::forEmail($name);
        if (!$clean) {
            return [];
        }
        $words = preg_split('/[^a-z0-9]+/', strtolower(Str::ascii(str_replace('&', ' and ', $clean))), -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_filter($words, fn ($w) => !in_array($w, self::STOP, true)));
    }

    /** @return array{url: string, evidence: string}|null */
    protected function check(Company $company, string $url, bool $viaEmail): ?array
    {
        try {
            [$res, $final] = $this->fetcher->fetch($url);
        } catch (\Throwable) {
            try {
                [$res, $final] = $this->fetcher->fetch(preg_replace('#^https://#', 'http://', $url));
            } catch (\Throwable) {
                return null;
            }
        }
        if (!$res->successful()) {
            return null;
        }
        $html = (string) $res->body();
        $host = strtolower((string) parse_url($final, PHP_URL_HOST));
        $bare = preg_replace('/^www\./', '', $host);
        $notOwn = collect(self::NOT_OWN_SITE)->contains(fn ($d) => $bare === $d || str_ends_with($bare, '.' . $d));
        if ($host === '' || $notOwn
            || $this->audits->parkedReason($html, $final) || $this->audits->botWall($html, $final) || $this->audits->isEmptyShell($html)) {
            return null;
        }
        $site = (parse_url($final, PHP_URL_SCHEME) ?: 'https') . '://' . $host;

        $label = $this->brandText($html);
        $tokens = $this->tokens($company->name);
        $compact = preg_replace('/[^a-z0-9]/', '', strtolower(Str::ascii($label)));
        $words = preg_split('/[^a-z0-9]+/', strtolower(Str::ascii($label)), -1, PREG_SPLIT_NO_EMPTY);
        if ($tokens && (str_contains($compact, implode('', $tokens)) || !array_diff($tokens, $words))
            && ($viaEmail || $this->samePlace($company, $host, $html))) {
            return ['url' => $site, 'evidence' => 'page title / site name: "' . mb_substr(trim($label), 0, 90) . '"'];
        }
        $phone = preg_replace('/\D/', '', (string) $company->public_phone);
        if (strlen($phone) >= 8 && str_contains(preg_replace('/\D/', '', strip_tags($html)), substr($phone, -8))) {
            return ['url' => $site, 'evidence' => 'the company\'s phone number is on the page'];
        }
        if ($viaEmail) {
            return ['url' => $site, 'evidence' => 'live site on the company\'s own e-mail domain'];
        }

        return null;
    }

    /**
     * A guessed .com can be a same-name business elsewhere: it counts only on the company's own country domain
     * (.co.uk for a UK firm) or when the page names the company's city or country.
     */
    protected function samePlace(Company $company, string $host, string $html): bool
    {
        foreach (self::TLDS[$company->country] ?? [] as $tld) {
            if (!in_array($tld, ['com', 'net'], true) && str_ends_with($host, '.' . $tld)) {
                return true;
            }
        }
        $text = strtolower(Str::ascii(strip_tags(preg_replace('#<(script|style)\b.*?</\1>#is', ' ', $html))));
        foreach (array_filter([$company->city, $company->country]) as $place) {
            if (strlen($place) >= 3 && str_contains($text, strtolower(Str::ascii($place)))) {
                return true;
            }
        }

        return false;
    }

    /** Title, og:site_name and the first <h1>: where a business names itself. */
    protected function brandText(string $html): string
    {
        $parts = [];
        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m)) {
            $parts[] = $m[1];
        }
        if (preg_match('/<meta[^>]+property=["\']og:(?:site_name|title)["\'][^>]+content=["\']([^"\']+)/i', $html, $m)
            || preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:(?:site_name|title)/i', $html, $m)) {
            $parts[] = $m[1];
        }
        if (preg_match('/<h1[^>]*>(.*?)<\/h1>/is', $html, $m)) {
            $parts[] = $m[1];
        }
        if (preg_match('/<img[^>]+class=["\'][^"\']*logo[^"\']*["\'][^>]+alt=["\']([^"\']+)/i', $html, $m)) {
            $parts[] = $m[1];
        }

        return html_entity_decode(trim(preg_replace('/\s+/', ' ', strip_tags(implode(' | ', $parts)))), ENT_QUOTES | ENT_HTML5);
    }
}
