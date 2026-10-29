<?php

namespace App\Services\Audit;

use App\Models\BuyingSignal;
use App\Models\Company;
use App\Models\DataProvenance;
use App\Models\Lead;
use App\Models\WebsiteAudit;
use DOMDocument;
use DOMXPath;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Evidence-based website & business audit.
 *
 * Principles:
 *  - Every finding comes from a check this service actually ran; each check stores
 *    status (pass|warn|fail|info|skipped), the measured value and the evidence string.
 *  - robots.txt is honoured for our user agent and "*". Disallowed => no crawl.
 *  - Only public business contact details printed on the company's own site are collected.
 *  - "Response time" is server/HTML response time measured from our server, NOT a full
 *    page-load or Core Web Vitals measurement. Real mobile performance comes only from the
 *    optional PageSpeed Insights call.
 */
class WebsiteAuditService
{
    /** Check weights for the opportunity score (fail = full, warn = half). */
    public const WEIGHTS = [
        'reachable' => 20, 'https' => 10, 'viewport' => 12, 'response_time' => 8, 'cta' => 10, 'contact_form' => 5,
        'click_to_call' => 3, 'title' => 5, 'meta_description' => 5, 'h1' => 4, 'lang' => 2, 'alt_text' => 3,
        'canonical' => 2, 'og_tags' => 2, 'structured_data' => 3, 'robots_noindex' => 8, 'copyright' => 4,
        'mixed_content' => 4, 'legacy_tech' => 4, 'broken_links' => 8, 'html_size' => 2, 'pagespeed_mobile' => 10,
        'ssl_certificate' => 10, 'https_redirect' => 3, 'security_headers' => 3, 'server_disclosure' => 2, 'outdated_server' => 4, 'sitemap' => 2,
    ];

    /** What to do about each finding: shown under every failed / warned check and in the PDF report. */
    public const FIXES = [
        'reachable' => 'Restore hosting / DNS so the homepage answers; set up uptime monitoring.',
        'https' => 'Install a free Let\'s Encrypt certificate and serve every page over https://.',
        'ssl_certificate' => 'Renew the certificate (or turn on auto-renewal) and make sure it covers both the domain and www.',
        'https_redirect' => 'Add a permanent (301) redirect from http:// to https:// for every page.',
        'security_headers' => 'Send Strict-Transport-Security, X-Content-Type-Options: nosniff, a frame policy (X-Frame-Options or CSP frame-ancestors) and a Referrer-Policy from the web server.',
        'server_disclosure' => 'Hide software versions: ServerTokens Prod / server_tokens off, and expose_php = Off.',
        'outdated_server' => 'Upgrade PHP to a supported version (8.2 or later); old versions get no security fixes.',
        'viewport' => 'Add <meta name="viewport" content="width=device-width, initial-scale=1"> and a responsive layout.',
        'response_time' => 'Add page caching / a CDN and check the hosting plan; the server should answer in under 1 second.',
        'pagespeed_mobile' => 'Compress and lazy-load images, cut unused scripts, and cache static files.',
        'html_size' => 'Remove inline scripts / base64 images from the HTML and load them as cached files.',
        'cta' => 'Put one clear "Get a quote" / "Book now" / "Call us" button above the fold.',
        'contact_form' => 'Add a short enquiry form (name, phone, message) on the homepage or contact page.',
        'click_to_call' => 'Make the phone number a tap-to-call tel: link.',
        'title' => 'Write a unique 30-60 character page title with the business name and main service.',
        'meta_description' => 'Write a 70-160 character meta description that says what you do and where.',
        'h1' => 'Use exactly one H1 heading that names the main service.',
        'lang' => 'Set <html lang="..."> for the page language.',
        'alt_text' => 'Describe every meaningful image in its alt attribute.',
        'canonical' => 'Add a canonical link to the preferred URL of each page.',
        'og_tags' => 'Add Open Graph tags (og:title, og:description, og:image) for link previews.',
        'structured_data' => 'Add schema.org LocalBusiness / Organization JSON-LD with address, phone and hours.',
        'robots_noindex' => 'Remove the noindex directive so Google can list the page.',
        'copyright' => 'Update the footer year (ideally automatically).',
        'mixed_content' => 'Load every image, script and stylesheet over https://.',
        'legacy_tech' => 'Update the CMS, plugins and front-end libraries (old jQuery has known vulnerabilities).',
        'broken_links' => 'Fix or redirect the internal links that return errors.',
        'sitemap' => 'Publish /sitemap.xml and submit it in Google Search Console.',
    ];

    protected string $ua;
    protected int $timeout;
    protected array $robotsCache = [];
    /** Bumped when the team-page crawl improves, so leadpilot:find-people reads those sites again. */
    public const PEOPLE_CRAWL_VERSION = 4;

    protected SafeFetcher $fetcher;
    protected ?string $lastFinalUrl = null;

    public function __construct()
    {
        $this->ua = (string) config('leadpilot.sources.website_crawler.user_agent');
        $this->timeout = (int) config('leadpilot.sources.website_crawler.timeout', 15);
        $this->fetcher = new SafeFetcher($this->ua, $this->timeout);
    }

    public function audit(Company $company): WebsiteAudit
    {
        $url = $this->normalizeUrl($company->website ?: ($company->domain ? 'https://' . $company->domain : null));
        if (!$url) {
            throw new \InvalidArgumentException('Company has no website to audit.');
        }

        $checks = [];
        $tech = [];
        $contacts = ['emails' => [], 'phones' => [], 'socials' => []];
        $pagespeed = null;

        if ($block = $this->fetcher->blockReason($url)) {
            return $this->store($company, $url, null, 'failed', null, null, null, [$this->check('reachable', 'Website reachable', 'skipped', null, $block)], [], $contacts, null);
        }

        if (!$this->robotsAllows($url)) {
            return $this->store($company, $url, null, 'blocked_by_robots', null, null, null, [
                $this->check('robots', 'robots.txt permits audit', 'info', 'disallowed', "robots.txt disallows {$this->path($url)} for our crawler; no pages were fetched."),
            ], [], $contacts, null);
        }

        // ---- 1. Fetch homepage
        $t0 = microtime(true);
        try {
            $res = $this->get($url);
        } catch (\Throwable $e) {
            $res = null;
            $err = $e->getMessage();
        }
        $ms = (int) round((microtime(true) - $t0) * 1000);

        // 401/403/407/429/451: the site is up but refuses THIS server (geo/IP block, firewall, rate limit). Seen live:
        // a US law firm answered "403 Forbidden" to Pakistan while it loads fine in the US. Never a "site is down" claim.
        if ($res && in_array($res->status(), self::BLOCKED_STATUSES, true)) {
            return $this->withoutClaims($company, $this->store($company, $url, $this->lastFinalUrl ?? $url, 'bot_protected', $res->status(), $ms, null, [
                $this->check('reachable', 'Website reachable', 'info', $res->status(), "The site refused our server (HTTP {$res->status()}: blocked by region, firewall or rate limit). It is not down; it could not be audited from here."),
            ], [], $contacts, null));
        }
        if (!$res || $res->status() >= 400) {
            $checks[] = $this->check('reachable', 'Website reachable', 'fail', $res?->status(), $res ? "Homepage returned HTTP {$res->status()}." : 'Request failed: ' . mb_substr($err ?? 'unknown', 0, 200), ['website_unreachable']);
            // One failed load proves nothing (outage, geo-block, or a directory listing pointing at someone else's
            // site). It becomes evidence only when an earlier audit, at least an hour before, also failed, and even
            // then it is a modest 40: a dead site can also mean a closed business.
            $confirmed = WebsiteAudit::where('company_id', $company->id)->where('status', 'unreachable')->where('created_at', '<=', now()->subHour())->exists();
            $audit = $this->store($company, $url, null, 'unreachable', $res?->status(), $ms, null, $checks, [], $contacts, null);
            $audit->update(['opportunity_score' => $confirmed ? 40 : 0]);
            if ($confirmed) {
                $this->syncSignal($company, $audit);
            } else {
                BuyingSignal::where('company_id', $company->id)->where('signal_type', 'website_issues')->where('metadata->source', 'website_audit')->delete();
            }

            return $audit;
        }

        $finalUrl = $this->lastFinalUrl ?? $url;
        $html = $res->body();
        $bytes = strlen($html);

        // A tiny page that only forwards the visitor (meta refresh / window.location) is not the website: follow it,
        // up to two hops (live case: rklawgroup.com answered 163 bytes and was audited as "no H1, no viewport").
        for ($hop = 0; $hop < 2 && ($next = $this->clientRedirect($html, $finalUrl)); $hop++) {
            if ($this->fetcher->blockReason($next) || !$this->robotsAllows($next)) {
                break;
            }
            try {
                $r = $this->get($next);
            } catch (\Throwable) {
                break;
            }
            if (!$r->successful()) {
                break;
            }
            [$res, $finalUrl, $html] = [$r, $this->lastFinalUrl ?? $next, $r->body()];
            $bytes = strlen($html);
        }
        if ($parked = $this->parkedReason($html, $finalUrl)) {
            return $this->withoutClaims($company, $this->store($company, $url, $finalUrl, 'no_content', $res->status(), $ms, $bytes, [
                $this->check('reachable', 'Website reachable', 'info', $res->status(), "The address shows {$parked}, not the business's own site; nothing was audited and no gap is claimed."),
            ], [], $contacts, null));
        }
        if ($this->isEmptyShell($html)) {
            // nothing a visitor can read (JavaScript-only app or unfollowed redirect): auditing it would invent gaps
            return $this->withoutClaims($company, $this->store($company, $url, $finalUrl, 'no_content', $res->status(), $ms, $bytes, [
                $this->check('reachable', 'Website reachable', 'info', $res->status(), "The homepage returned only {$bytes} bytes with no readable content (a redirect or JavaScript-only page); nothing was audited and no gap is claimed."),
            ], [], $contacts, null));
        }

        // A bot-protection challenge (captcha / "checking your browser") is not the business's website: auditing it
        // would report gaps the real site may not have. Record that the site could not be audited, claim nothing.
        if ($wall = $this->botWall($html, $finalUrl)) {
            return $this->withoutClaims($company, $this->store($company, $url, $finalUrl, 'bot_protected', $res->status(), $ms, $bytes, [
                $this->check('reachable', 'Website reachable', 'info', $res->status(), "The site answered with a bot-protection page ({$wall}); the real homepage could not be audited."),
            ], [], $contacts, null));
        }
        $checks[] = $this->check('reachable', 'Website reachable', 'pass', $res->status(), "Homepage returned HTTP {$res->status()}.");

        // ---- 2. Transport
        $https = str_starts_with(strtolower($finalUrl), 'https://');
        $checks[] = $https
            ? $this->check('https', 'Served over HTTPS', 'pass', $finalUrl, 'Final URL uses HTTPS.')
            : $this->check('https', 'Served over HTTPS', 'fail', $finalUrl, "Final URL is not HTTPS ({$finalUrl}). Browsers mark it 'Not secure'.", ['no_https']);

        $checks[] = match (true) {
            $ms > 3000 => $this->check('response_time', 'Server response time (HTML only)', 'fail', "{$ms} ms", "HTML took {$ms} ms to download from our server (threshold 3000 ms). Not a full page-load measurement.", ['slow_response']),
            $ms > 1500 => $this->check('response_time', 'Server response time (HTML only)', 'warn', "{$ms} ms", "HTML took {$ms} ms (warning above 1500 ms). Not a full page-load measurement.", ['slow_response']),
            default => $this->check('response_time', 'Server response time (HTML only)', 'pass', "{$ms} ms", "HTML downloaded in {$ms} ms."),
        };
        $kb = (int) round($bytes / 1024);
        $checks[] = $bytes > 500 * 1024
            ? $this->check('html_size', 'HTML document size', 'warn', "{$kb} KB", "Homepage HTML alone is {$kb} KB (warning above 500 KB).", ['large_page'])
            : $this->check('html_size', 'HTML document size', 'pass', "{$kb} KB", "Homepage HTML is {$kb} KB.");

        if (config('leadpilot.sources.website_crawler.tech_checks', true)) {
            $checks = array_merge($checks, $this->technicalChecks($res, $finalUrl, $https));
        }

        // ---- 3. Parse
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        $xp = new DOMXPath($dom);

        $checks = array_merge($checks, $this->onPageChecks($xp, $html, $https));
        $tech = $this->detectTech($html, $res, $xp);
        $legacy = array_values(array_filter($tech, fn ($t) => !empty($t['legacy'])));
        $checks[] = $legacy
            ? $this->check('legacy_tech', 'Up-to-date front-end libraries', 'warn', implode(', ', array_column($legacy, 'name')), 'Outdated library versions detected: ' . implode('; ', array_column($legacy, 'evidence')) . '.', array_values(array_unique(array_merge(...array_map(fn ($t) => (array) $t['legacy'], $legacy)))))
            : $this->check('legacy_tech', 'Up-to-date front-end libraries', 'pass', null, 'No outdated jQuery/CMS version detected in page source.');

        // ---- 4. Contacts (homepage + contact/about pages permitted by robots.txt)
        $pages = [$finalUrl => $html];
        // Pages the homepage itself links to as team / people / about / contact come first (a law firm's people live at
        // /attorneys, a clinic's at /our-doctors); the fixed paths are the fallback.
        $candidates = array_unique(array_merge($this->peoplePageLinks($xp, $finalUrl),
            array_map(fn ($path) => $this->origin($finalUrl) . $path, (array) config('leadpilot.sources.website_crawler.contact_paths', []))));
        foreach ($candidates as $u) {
            if (count($pages) >= (int) config('leadpilot.sources.website_crawler.max_pages', 5) || isset($pages[$u]) || !$this->robotsAllows($u)) {
                continue;
            }
            try {
                $r = $this->get($u);
                if ($r->successful() && SafeFetcher::sameSite($this->lastFinalUrl ?? $u, $finalUrl) && str_contains(strtolower($r->header('Content-Type') ?: 'text/html'), 'html')) {
                    $pages[$u] = $r->body();
                }
            } catch (\Throwable) {
            }
        }
        $contacts['pages'] = array_keys($pages); // which pages were read, for "why no names?" questions
        $domain = Company::normalizeDomain($finalUrl);
        $board = null;
        foreach ($pages as $pageUrl => $body) {
            $this->extractContacts($body, $pageUrl, $domain, $contacts);
        }
        $people = new PeopleExtractor();
        foreach ($pages as $pageUrl => $body) {
            foreach ($people->extract($body, $pageUrl, array_values($contacts['emails'] ?? []), $company->name) as $p) {
                $key = strtolower($p['name']);
                if (!isset($contacts['people'][$key]) || ($p['email'] && !$contacts['people'][$key]['email'])) {
                    $contacts['people'][$key] = $p;
                }
            }
        }
        foreach ($pages as $pageUrl => $body) {
            // A careers link to a public job board lets the hiring-signal source check this company's open roles.
            if (!$board && preg_match_all('#https?://[^"\'\s<>]+#i', $body, $links)) {
                foreach ($links[0] as $link) {
                    if ($board = \App\Services\Discovery\Adapters\GreenhouseLeverWatchlistAdapter::boardFromUrl($link)) {
                        break;
                    }
                }
            }
        }
        if ($board && $company->ats_board !== $board) {
            $company->update(['ats_board' => $board]);
        }
        $contactPageHasForm = false;
        foreach ($pages as $pageUrl => $body) {
            if ($pageUrl !== $finalUrl && $this->hasLeadForm($body)) {
                $contactPageHasForm = true;
            }
        }
        $homeForm = $this->hasLeadForm($html);
        $checks[] = ($homeForm || $contactPageHasForm)
            ? $this->check('contact_form', 'Enquiry form available', 'pass', null, 'Found a form with email/phone/message fields on ' . ($homeForm ? 'the homepage.' : 'a contact page.'))
            : $this->check('contact_form', 'Enquiry form available', 'warn', null, 'No enquiry form found on the homepage or ' . (count($pages) - 1) . ' contact/about page(s) checked.', ['no_contact_form']);

        // ---- 5. Broken internal links
        $checks[] = $this->brokenLinkCheck($xp, $finalUrl);

        // ---- 6. Optional Google PageSpeed Insights (real mobile lab data)
        if (config('leadpilot.sources.pagespeed.enabled')) {
            [$pagespeed, $psCheck] = $this->pageSpeed($finalUrl);
            if ($psCheck) {
                $checks[] = $psCheck;
            }
        }

        $audit = $this->store($company, $url, $finalUrl, 'completed', $res->status(), $ms, $bytes, $checks, $tech, $contacts, $pagespeed);
        $this->applyToCompany($company, $audit);
        $this->syncSignal($company, $audit);

        return $audit;
    }

    /** HTTP answers that mean "not for you", not "down". */
    public const BLOCKED_STATUSES = [401, 403, 407, 429, 451];

    public const BOT_WALL_PATTERNS = [
        'captcha', 'just a moment', 'attention required', 'checking your browser', 'are you a robot', 'are you human',
        'access denied', 'ddos-guard', 'bot manager', 'please verify you are a human', 'request unsuccessful',
    ];

    /**
     * Security and technical health a visitor or Google would notice: the TLS certificate, the http -> https redirect,
     * security headers, software versions the server gives away (and an end-of-life PHP), and a sitemap. Facts only:
     * a check that could not be made is reported as info, never as a problem.
     */
    protected function technicalChecks(\Illuminate\Http\Client\Response $res, string $finalUrl, bool $https): array
    {
        $out = [];
        $timeout = (int) config('leadpilot.sources.website_crawler.tech_timeout', 8);
        $host = (string) parse_url($finalUrl, PHP_URL_HOST);
        $origin = (parse_url($finalUrl, PHP_URL_SCHEME) ?: 'https') . '://' . $host;

        if ($https) {
            $tls = app(TlsInspector::class)->inspect($finalUrl, $timeout);
            $out[] = match (true) {
                $tls['error'] !== null => $this->check('ssl_certificate', 'SSL certificate', 'info', null, 'Could not read the certificate: ' . mb_substr($tls['error'], 0, 120)),
                !$tls['ok'] => $this->check('ssl_certificate', 'SSL certificate', 'fail', $tls['problem'], "The SSL certificate is {$tls['problem']}; browsers show a security warning.", ['ssl_invalid']),
                $tls['days_left'] < 21 => $this->check('ssl_certificate', 'SSL certificate', 'warn', $tls['expires_at']->toDateString(), "The SSL certificate expires in {$tls['days_left']} day(s) ({$tls['expires_at']->format('j M Y')}).", ['ssl_expiring']),
                default => $this->check('ssl_certificate', 'SSL certificate', 'pass', $tls['expires_at']->toDateString(), 'Valid certificate' . ($tls['issuer'] ? " from {$tls['issuer']}" : '') . ', expires ' . $tls['expires_at']->format('j M Y') . '.'),
            };
            try {
                [$plain, $plainFinal] = $this->fetcher->fetch('http://' . $host . '/', 'GET', $timeout);
                $out[] = str_starts_with(strtolower($plainFinal), 'https://')
                    ? $this->check('https_redirect', 'http:// redirects to https://', 'pass', $plainFinal, 'Visitors typing http:// are sent to the secure site.')
                    : $this->check('https_redirect', 'http:// redirects to https://', 'warn', $plainFinal, "http://{$host}/ stays on http:// (HTTP {$plain->status()}) instead of redirecting to https://.", ['no_https_redirect']);
            } catch (\Throwable) {
                // port 80 closed or refused: nothing a visitor would see, nothing to claim
            }
        }

        $h = fn (string $name) => trim((string) $res->header($name));
        $csp = strtolower($h('Content-Security-Policy'));
        $present = array_filter([
            'Strict-Transport-Security' => !$https || $h('Strict-Transport-Security') !== '',
            'X-Content-Type-Options' => strtolower($h('X-Content-Type-Options')) === 'nosniff',
            'frame policy' => $h('X-Frame-Options') !== '' || str_contains($csp, 'frame-ancestors'),
            'Referrer-Policy' => $h('Referrer-Policy') !== '',
            'Content-Security-Policy' => $csp !== '',
        ], fn ($ok) => !$ok);
        $missing = array_keys($present);
        $out[] = count($missing) >= 3
            ? $this->check('security_headers', 'Security headers', 'warn', count($missing) . ' of 5 missing', 'Missing security headers: ' . implode(', ', $missing) . '.', ['missing_security_headers'])
            : $this->check('security_headers', 'Security headers', 'pass', count($missing) . ' of 5 missing', $missing ? 'Most security headers are set; missing: ' . implode(', ', $missing) . '.' : 'All five security headers are set.');

        $server = $h('Server');
        $powered = $h('X-Powered-By');
        $versions = array_values(array_filter([preg_match('/\d+\.\d+/', $server) ? "Server: {$server}" : null, preg_match('/\d+\.\d+/', $powered) ? "X-Powered-By: {$powered}" : null]));
        $out[] = $versions
            ? $this->check('server_disclosure', 'Software versions hidden', 'warn', implode('; ', $versions), 'The server tells every visitor its exact software versions (' . implode('; ', $versions) . '), which helps attackers pick known exploits.', ['server_version_exposed'])
            : $this->check('server_disclosure', 'Software versions hidden', 'pass', null, 'The server does not reveal software versions in its headers.');
        if (preg_match('#PHP/(\d+)\.(\d+)#i', $powered, $m)) {
            $old = (int) $m[1] < 8 || ((int) $m[1] === 8 && (int) $m[2] < 2);
            $out[] = $old
                ? $this->check('outdated_server', 'Supported PHP version', 'fail', "PHP {$m[1]}.{$m[2]}", "The site runs PHP {$m[1]}.{$m[2]}, which no longer receives security fixes.", ['outdated_php'])
                : $this->check('outdated_server', 'Supported PHP version', 'pass', "PHP {$m[1]}.{$m[2]}", "PHP {$m[1]}.{$m[2]} is a supported version.");
        }

        try {
            [$sm] = $this->fetcher->fetch($origin . '/sitemap.xml', 'GET', $timeout);
            $ok = $sm->successful() && preg_match('/<(urlset|sitemapindex)\b/i', substr((string) $sm->body(), 0, 4000));
            $out[] = $ok
                ? $this->check('sitemap', 'XML sitemap', 'pass', '/sitemap.xml', 'A sitemap is published at /sitemap.xml.')
                : $this->check('sitemap', 'XML sitemap', 'warn', null, 'No sitemap at /sitemap.xml, so Google finds new pages more slowly.', ['no_sitemap']);
        } catch (\Throwable) {
        }

        return $out;
    }

    /** Name of the bot-protection wall the response is, or null for a normal page. */
    public function botWall(string $html, string $finalUrl): ?string
    {
        $title = preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m) ? mb_strtolower(trim(html_entity_decode(strip_tags($m[1])))) : '';
        foreach (self::BOT_WALL_PATTERNS as $p) {
            if ($title !== '' && str_contains($title, $p)) {
                return "page title \"" . mb_substr($title, 0, 60) . "\"";
            }
        }
        $u = mb_strtolower($finalUrl);
        if (str_contains($u, 'botmanager') || str_contains($u, 'captcha') || str_contains($u, '/cdn-cgi/challenge')) {
            return 'redirected to a challenge URL';
        }
        if (str_contains($html, '_Incapsula_Resource') || str_contains($html, 'cf-chl-') || str_contains($html, 'challenge-platform')) {
            return 'challenge script';
        }

        return null;
    }

    // ------------------------------------------------------------------ checks

    protected function onPageChecks(DOMXPath $xp, string $html, bool $https): array
    {
        $c = [];
        $q = fn (string $x) => $xp->query($x);
        $first = function (string $x) use ($q) {
            $n = $q($x);

            return $n && $n->length ? trim($n->item(0)->nodeValue ?? $n->item(0)->textContent) : null;
        };

        $viewport = $first('//meta[translate(@name,"VIEWPORT","viewport")="viewport"]/@content');
        $c[] = $viewport
            ? $this->check('viewport', 'Mobile viewport configured', 'pass', $viewport, "meta viewport present: \"{$viewport}\".")
            : $this->check('viewport', 'Mobile viewport configured', 'fail', null, 'No <meta name="viewport"> tag — page will render zoomed-out on phones.', ['no_viewport']);

        $title = $first('//title');
        $len = $title ? mb_strlen($title) : 0;
        $c[] = match (true) {
            !$title => $this->check('title', 'Page title', 'fail', null, 'No <title> tag.', ['missing_title']),
            $len < 10 || $len > 65 => $this->check('title', 'Page title', 'warn', $title, "Title is {$len} characters (recommended 10–65).", ['missing_title']),
            default => $this->check('title', 'Page title', 'pass', $title, "Title present ({$len} chars)."),
        };

        $desc = $first('//meta[translate(@name,"DESCRIPTION","description")="description"]/@content');
        $c[] = $desc
            ? $this->check('meta_description', 'Meta description', 'pass', mb_substr($desc, 0, 160), 'Meta description present (' . mb_strlen($desc) . ' chars).')
            : $this->check('meta_description', 'Meta description', 'fail', null, 'No meta description — Google writes its own snippet.', ['missing_meta_description']);

        $h1 = $q('//h1')->length;
        $c[] = match (true) {
            $h1 === 0 => $this->check('h1', 'Single H1 heading', 'warn', 0, 'No <h1> heading on the homepage.', ['missing_h1']),
            $h1 > 1 => $this->check('h1', 'Single H1 heading', 'warn', $h1, "{$h1} <h1> headings found (1 recommended).", ['multiple_h1']),
            default => $this->check('h1', 'Single H1 heading', 'pass', 1, 'Exactly one <h1>.'),
        };

        $lang = $first('//html/@lang');
        $c[] = $lang
            ? $this->check('lang', 'Language declared', 'pass', $lang, "html lang=\"{$lang}\".")
            : $this->check('lang', 'Language declared', 'warn', null, 'No lang attribute on <html> (screen readers and Google use it).', ['missing_lang']);

        $imgs = $q('//img')->length;
        $noAlt = $q('//img[not(@alt)]')->length;
        if ($imgs === 0) {
            $c[] = $this->check('alt_text', 'Image alt text', 'info', '0 images', 'No <img> tags on homepage.');
        } else {
            $pct = (int) round(100 * $noAlt / $imgs);
            $c[] = $pct > 20
                ? $this->check('alt_text', 'Image alt text', 'warn', "{$noAlt}/{$imgs}", "{$noAlt} of {$imgs} images ({$pct}%) have no alt attribute.", ['missing_alt_text'])
                : $this->check('alt_text', 'Image alt text', 'pass', "{$noAlt}/{$imgs}", "{$noAlt} of {$imgs} images lack alt.");
        }

        $c[] = $first('//link[@rel="canonical"]/@href')
            ? $this->check('canonical', 'Canonical URL', 'pass', null, 'rel=canonical present.')
            : $this->check('canonical', 'Canonical URL', 'warn', null, 'No rel=canonical link.', ['no_canonical']);

        $c[] = $first('//meta[@property="og:title"]/@content')
            ? $this->check('og_tags', 'Social sharing tags', 'pass', null, 'Open Graph og:title present.')
            : $this->check('og_tags', 'Social sharing tags', 'warn', null, 'No Open Graph tags — links shared on WhatsApp/Facebook/LinkedIn show no preview card.', ['no_og_tags']);

        $c[] = $q('//script[@type="application/ld+json"]')->length
            ? $this->check('structured_data', 'Structured data (schema.org)', 'pass', null, 'JSON-LD structured data present.')
            : $this->check('structured_data', 'Structured data (schema.org)', 'warn', null, 'No JSON-LD structured data (LocalBusiness/Organization) found.', ['no_structured_data']);

        $robotsMeta = strtolower((string) $first('//meta[translate(@name,"ROBOTS","robots")="robots"]/@content'));
        $c[] = str_contains($robotsMeta, 'noindex')
            ? $this->check('robots_noindex', 'Indexable by search engines', 'fail', $robotsMeta, "meta robots contains \"noindex\" — the homepage is hidden from Google.", ['robots_noindex'])
            : $this->check('robots_noindex', 'Indexable by search engines', 'pass', $robotsMeta ?: null, 'No noindex directive.');

        // CTAs
        $ctaRegex = '/\b(contact|get (a )?quote|request|book|schedule|appointment|call (us|now)|enquir|inquir|get started|order|buy|shop now|free consultation|whatsapp|sign up|demo)\b/i';
        $ctas = [];
        $contactLinks = [];
        foreach ($q('//a|//button|//input[@type="submit"]') as $n) {
            $text = trim(preg_replace('/\s+/', ' ', $n->textContent . ' ' . ($n->getAttribute('value') ?? '')));
            $href = (string) $n->getAttribute('href');
            if ($text !== '' && mb_strlen($text) < 40 && !str_contains($text, '@') && preg_match($ctaRegex, $text)) {
                $ctas[] = $text;
            } elseif (preg_match('#^(tel:|mailto:|https?://(wa\.me|api\.whatsapp))#i', $href)) {
                $contactLinks[] = $text !== '' ? $text : $href;
            }
        }
        $ctas = array_values(array_unique($ctas));
        $c[] = match (true) {
            (bool) $ctas => $this->check('cta', 'Clear call-to-action', 'pass', count($ctas), 'CTAs found: ' . implode(' | ', array_slice($ctas, 0, 5)) . '.'),
            (bool) $contactLinks => $this->check('cta', 'Clear call-to-action', 'warn', 0, 'No contact/quote/booking button — only plain contact links (' . implode(', ', array_slice(array_unique($contactLinks), 0, 3)) . ').', ['no_cta']),
            default => $this->check('cta', 'Clear call-to-action', 'fail', 0, 'No contact/quote/booking/call button or link found on the homepage.', ['no_cta']),
        };

        $tel = $q('//a[starts-with(translate(@href,"TEL","tel"),"tel:")]')->length;
        $c[] = $tel
            ? $this->check('click_to_call', 'Click-to-call link', 'pass', $tel, "{$tel} tel: link(s) found.")
            : $this->check('click_to_call', 'Click-to-call link', 'warn', 0, 'No tel: link — mobile visitors cannot tap to call.', ['no_click_to_call']);

        // Copyright year (outdated-site signal)
        $text = strip_tags($html);
        if (preg_match_all('/(?:©|&copy;|copyright)\s*(?:[^0-9]{0,30})?((?:19|20)\d{2})(?:\s*[-–]\s*((?:19|20)\d{2}))?/iu', $text, $m, PREG_SET_ORDER)) {
            $years = [];
            foreach ($m as $mm) {
                $years[] = (int) $mm[1];
                if (!empty($mm[2])) {
                    $years[] = (int) $mm[2];
                }
            }
            $max = max($years);
            $cur = (int) date('Y');
            $c[] = $max < $cur - 1
                ? $this->check('copyright', 'Recently maintained (copyright year)', 'warn', $max, "Footer copyright shows {$max}; current year is {$cur}. Often indicates an unmaintained site.", ['outdated_copyright'])
                : $this->check('copyright', 'Recently maintained (copyright year)', 'pass', $max, "Copyright year {$max}.");
        } else {
            $c[] = $this->check('copyright', 'Recently maintained (copyright year)', 'info', null, 'No copyright year found.');
        }

        if ($https) {
            $mixed = [];
            foreach ($q('//img[starts-with(@src,"http://")]/@src|//script[starts-with(@src,"http://")]/@src|//link[@rel="stylesheet"][starts-with(@href,"http://")]/@href|//iframe[starts-with(@src,"http://")]/@src') as $n) {
                $mixed[] = $n->nodeValue;
            }
            $c[] = $mixed
                ? $this->check('mixed_content', 'No mixed (insecure) content', 'warn', count($mixed), count($mixed) . ' resource(s) loaded over http:// on an https page, e.g. ' . mb_substr($mixed[0], 0, 120) . '.', ['mixed_content'])
                : $this->check('mixed_content', 'No mixed (insecure) content', 'pass', 0, 'No http:// resources on the https page.');
        }

        return $c;
    }

    protected function detectTech(string $html, Response $res, DOMXPath $xp): array
    {
        $t = [];
        $add = function (string $name, string $evidence, ?string $version = null, array $legacy = [], string $cat = 'other') use (&$t) {
            $t[$name] = array_filter(['name' => $name, 'category' => $cat, 'version' => $version, 'evidence' => $evidence, 'legacy' => $legacy ?: null]);
        };
        $gen = '';
        foreach ($xp->query('//meta[translate(@name,"GENERATOR","generator")="generator"]/@content') as $n) {
            $gen .= ' ' . $n->nodeValue;
        }

        if (preg_match('/WordPress\s*([\d.]+)?/i', $gen, $m) || str_contains($html, '/wp-content/')) {
            $v = $m[1] ?? null;
            $legacy = ($v && version_compare($v, '6.0', '<')) ? ['outdated_cms_version'] : [];
            $add('WordPress', $v ? "generator meta: WordPress {$v}" : 'wp-content paths in HTML', $v, $legacy, 'cms');
        }
        if (str_contains($html, 'woocommerce')) {
            $add('WooCommerce', 'woocommerce classes/scripts in HTML', null, [], 'ecommerce');
        }
        if (str_contains($html, 'elementor')) {
            $add('Elementor', 'elementor classes in HTML', null, [], 'page_builder');
        }
        if (str_contains($html, 'cdn.shopify.com') || str_contains($html, 'Shopify.theme')) {
            $add('Shopify', 'cdn.shopify.com assets', null, [], 'ecommerce');
        }
        if (stripos($gen, 'Wix') !== false || str_contains($html, 'static.wixstatic.com')) {
            $add('Wix', 'Wix generator/static assets', null, [], 'cms');
        }
        if (str_contains($html, 'squarespace.com') || stripos($gen, 'Squarespace') !== false) {
            $add('Squarespace', 'squarespace assets', null, [], 'cms');
        }
        if (stripos($gen, 'Joomla') !== false) {
            $add('Joomla', 'generator meta', null, [], 'cms');
        }
        if (stripos($gen, 'Drupal') !== false || str_contains($html, 'Drupal.settings')) {
            $add('Drupal', 'generator meta / Drupal.settings', null, [], 'cms');
        }
        if (preg_match('/jquery[.-]?(\d+\.\d+(?:\.\d+)?)(?:\.min)?\.js|jquery(?:\.min)?\.js\?ver=(\d+\.\d+(?:\.\d+)?)/i', $html, $m)) {
            $v = $m[1] ?: ($m[2] ?? null);
            $add('jQuery', "script src references jQuery {$v}", $v, ($v && version_compare($v, '3.0', '<')) ? ['legacy_jquery'] : [], 'js_library');
        }
        if (preg_match('/bootstrap(?:\.min)?\.(?:css|js)\?ver=(\d[\d.]*)|bootstrap@(\d[\d.]*)|bootstrap\/(\d[\d.]*)\//i', $html, $m)) {
            $v = $m[1] ?: ($m[2] ?: ($m[3] ?? null));
            $add('Bootstrap', "asset path references Bootstrap {$v}", $v, [], 'ui_framework');
        }
        if (str_contains($html, '__NEXT_DATA__') || str_contains($html, '/_next/')) {
            $add('Next.js', '__NEXT_DATA__ / _next assets', null, [], 'js_framework');
        }
        if (str_contains($html, '__NUXT__') || str_contains($html, '/_nuxt/')) {
            $add('Nuxt', '__NUXT__ / _nuxt assets', null, [], 'js_framework');
        }
        $cookies = strtolower(implode(';', (array) $res->header('Set-Cookie')));
        if (str_contains($cookies, 'laravel_session') || str_contains($cookies, 'xsrf-token')) {
            $add('Laravel', 'laravel_session / XSRF-TOKEN cookie', null, [], 'backend');
        }
        if ($pw = $res->header('X-Powered-By')) {
            if (preg_match('/PHP\/?([\d.]+)?/i', $pw, $m)) {
                $v = $m[1] ?? null;
                $add('PHP', "X-Powered-By: {$pw}", $v, ($v && version_compare($v, '8.1', '<')) ? ['outdated_cms_version'] : [], 'backend');
            }
        }
        if ($srv = $res->header('Server')) {
            if (stripos($srv, 'cloudflare') !== false) {
                $add('Cloudflare', "Server: {$srv}", null, [], 'cdn');
            }
        }
        if (preg_match('/googletagmanager\.com\/gtm\.js|GTM-[A-Z0-9]+/', $html)) {
            $add('Google Tag Manager', 'GTM container snippet', null, [], 'analytics');
        }
        if (preg_match('/gtag\(|google-analytics\.com|G-[A-Z0-9]{6,}/', $html)) {
            $add('Google Analytics', 'gtag/GA snippet', null, [], 'analytics');
        }
        if (str_contains($html, 'fbq(') || str_contains($html, 'connect.facebook.net')) {
            $add('Meta Pixel', 'fbq() / connect.facebook.net', null, [], 'ads');
        }
        if (preg_match('/add[ _-]to[ _-]cart/i', $html)) {
            $add('Online store', '"add to cart" in HTML', null, [], 'ecommerce');
        }

        return array_values($t);
    }

    /** The site could not really be audited: drop the opportunity score and any earlier audit claim. */
    protected function withoutClaims(Company $company, WebsiteAudit $audit): WebsiteAudit
    {
        $company->update(['website_opportunity_score' => 0, 'website_audited_at' => $audit->audited_at]);
        BuyingSignal::where('company_id', $company->id)->where('signal_type', 'website_issues')->where('metadata->source', 'website_audit')->delete();

        return $audit;
    }

    /** Target of a meta-refresh or JavaScript redirect on a small forwarding page, or null. */
    protected function clientRedirect(string $html, string $base): ?string
    {
        if (strlen($html) > 8192) {
            return null; // a real page that happens to contain a script redirect somewhere
        }
        $target = null;
        if (preg_match('/<meta[^>]+http-equiv=["\']?refresh["\']?[^>]*content=["\']?\s*\d*\s*;?\s*url\s*=\s*[\'"]?([^"\'>\s]+)/i', $html, $m)) {
            $target = $m[1];
        } elseif (preg_match('/(?:window\.|document\.|top\.|self\.)?location(?:\.href)?\s*(?:=|\.replace\(|\.assign\()\s*["\']([^"\']+)["\']/i', $html, $m)) {
            $target = $m[1];
        }
        if (!$target) {
            return null;
        }
        $abs = $this->absolutize(html_entity_decode(trim($target)), $base);

        return $abs && SafeFetcher::safeUrl($abs) && rtrim($abs, '/') !== rtrim($base, '/') ? $abs : null;
    }

    /** No readable text and no links: nothing a visitor (or an audit) can judge. */
    public function isEmptyShell(string $html): bool
    {
        if (strlen($html) > 4096) {
            return false;
        }
        $text = trim(preg_replace('/\s+/', ' ', strip_tags(preg_replace('#<(script|style|noscript)\b.*?</\1>#is', ' ', $html))));

        return mb_strlen($text) < 40 && !preg_match('/<a\s[^>]*href=/i', $html);
    }

    /** The hosting company's placeholder, a parked domain or "coming soon": the business has no live site here. */
    public function parkedReason(string $html, string $finalUrl): ?string
    {
        if (preg_match('#/cgi-sys/defaultwebpage\.cgi|/cgi-sys/suspendedpage\.cgi#i', $finalUrl)) {
            return 'the hosting control panel\'s default page';
        }
        $head = mb_strtolower(mb_substr(strip_tags(preg_replace('#<(script|style)\b.*?</\1>#is', ' ', $html)), 0, 3000));
        foreach ([
            'default web site page' => 'the hosting default page', 'this site is temporarily unavailable' => 'a hosting suspension page',
            'account suspended' => 'a hosting suspension page', 'this domain is for sale' => 'a domain-for-sale page', 'buy this domain' => 'a domain-for-sale page',
            'domain is parked' => 'a parked-domain page', 'parked free' => 'a parked-domain page', 'future home of' => 'a placeholder page',
            'website coming soon' => 'a coming-soon page', 'site under construction' => 'an under-construction page', 'index of /' => 'a bare directory listing',
            'this domain has been registered' => 'a registrar placeholder page', 'domain names from heart internet' => 'a hosting placeholder page',
            'web hosting, reseller hosting' => 'a hosting placeholder page',
            'apache2 default page' => 'the web server default page', 'welcome to nginx' => 'the web server default page', 'iis windows server' => 'the web server default page',
        ] as $needle => $what) {
            if (str_contains($head, $needle)) {
                return $what;
            }
        }

        return null;
    }

    /** Same-site links whose text or path says team / people / about / contact, best first (at most 6). */
    protected function peoplePageLinks(DOMXPath $xp, string $base): array
    {
        $tiers = [
            '/\b(team|our[ -]?people|people|staff|attorneys?|lawyers?|solicitors?|doctors?|dentists?|physicians?|leadership|management|directors|partners|founders?|meet)\b/i',
            '/\b(about|who[ -]?we[ -]?are|our[ -]?story|company)\b/i',
            '/\b(contact)\b/i',
        ];
        $found = [];
        foreach ($xp->query('//a[@href]') as $a) {
            $href = trim($a->getAttribute('href'));
            if ($href === '' || preg_match('#^(\#|mailto:|tel:|javascript:|whatsapp:)#i', $href)) {
                continue;
            }
            $abs = $this->absolutize($href, $base);
            if (!$abs || !SafeFetcher::sameSite($abs, $base) || preg_match('/\.(pdf|jpe?g|png|gif|webp|zip|docx?|xlsx?)(\?|$)/i', $abs)) {
                continue;
            }
            $abs = strtok($abs, '#');
            if (in_array(trim((string) parse_url($abs, PHP_URL_PATH), '/'), ['', 'index.html', 'index.php', 'home'], true)) {
                continue; // the homepage itself, under any scheme
            }
            $haystack = trim($a->textContent) . ' ' . str_replace(['-', '_', '/'], ' ', (string) parse_url($abs, PHP_URL_PATH));
            foreach ($tiers as $rank => $re) {
                if (preg_match($re, $haystack)) {
                    $found[$abs] = min($found[$abs] ?? 9, $rank);
                    break;
                }
            }
        }
        asort($found);

        return array_slice(array_keys($found), 0, 6);
    }

    protected function brokenLinkCheck(DOMXPath $xp, string $base): array
    {
        $origin = $this->origin($base);
        $max = (int) config('leadpilot.sources.website_crawler.max_internal_links_checked', 15);
        $links = [];
        foreach ($xp->query('//a/@href') as $n) {
            $href = trim($n->nodeValue);
            if ($href === '' || preg_match('#^(\#|mailto:|tel:|javascript:|whatsapp:)#i', $href)) {
                continue;
            }
            $abs = $this->absolutize($href, $base);
            if ($abs && SafeFetcher::sameOrigin($abs, $origin) && !preg_match('/\.(pdf|jpe?g|png|gif|webp|zip|docx?|xlsx?)(\?|$)/i', $abs)) {
                $links[strtok($abs, '#')] = true;
            }
            if (count($links) >= $max) {
                break;
            }
        }
        $broken = [];
        $checked = 0;
        foreach (array_keys($links) as $l) {
            if (!$this->robotsAllows($l)) {
                continue;
            }
            try {
                [$r] = $this->fetcher->fetch($l, 'HEAD', 8);
                if (in_array($r->status(), [405, 501], true)) {
                    [$r] = $this->fetcher->fetch($l, 'GET', 8);
                }
                $checked++;
                if ($r->status() >= 400) {
                    $broken[] = "{$l} (HTTP {$r->status()})";
                }
            } catch (BlockedUrlException) {
                continue; // link resolves somewhere we refuse to fetch; not counted
            } catch (\Throwable) {
                $checked++;
                $broken[] = "{$l} (no response)";
            }
        }
        if ($checked === 0) {
            return $this->check('broken_links', 'No broken internal links', 'info', '0 checked', 'No internal links found/permitted to check.');
        }

        return $broken
            ? $this->check('broken_links', 'No broken internal links', 'fail', count($broken) . "/{$checked}", 'Broken: ' . implode('; ', array_slice($broken, 0, 5)) . '.', ['broken_links'])
            : $this->check('broken_links', 'No broken internal links', 'pass', "0/{$checked}", "All {$checked} internal links checked returned < 400.");
    }

    protected function pageSpeed(string $url): array
    {
        try {
            $params = ['url' => $url, 'strategy' => 'mobile', 'category' => 'performance'];
            if ($key = config('leadpilot.sources.pagespeed.api_key')) {
                $params['key'] = $key;
            }
            $r = Http::timeout(60)->get('https://www.googleapis.com/pagespeedonline/v5/runPagespeed', $params);
            if (!$r->successful()) {
                return [null, $this->check('pagespeed_mobile', 'Mobile performance (PageSpeed)', 'skipped', null, 'PageSpeed Insights HTTP ' . $r->status())];
            }
            $score = $r->json('lighthouseResult.categories.performance.score');
            $audits = $r->json('lighthouseResult.audits', []);
            $data = [
                'performance_score' => $score !== null ? (int) round($score * 100) : null,
                'lcp' => $audits['largest-contentful-paint']['displayValue'] ?? null,
                'cls' => $audits['cumulative-layout-shift']['displayValue'] ?? null,
                'tbt' => $audits['total-blocking-time']['displayValue'] ?? null,
                'fetched_at' => now()->toIso8601String(),
            ];
            $s = $data['performance_score'];
            $ev = "Google PageSpeed Insights (mobile lab): performance {$s}/100, LCP {$data['lcp']}, CLS {$data['cls']}.";
            $check = match (true) {
                $s === null => $this->check('pagespeed_mobile', 'Mobile performance (PageSpeed)', 'skipped', null, 'No score returned.'),
                $s < 50 => $this->check('pagespeed_mobile', 'Mobile performance (PageSpeed)', 'fail', $s, $ev, ['slow_mobile_performance']),
                $s < 90 => $this->check('pagespeed_mobile', 'Mobile performance (PageSpeed)', 'warn', $s, $ev, ['slow_mobile_performance']),
                default => $this->check('pagespeed_mobile', 'Mobile performance (PageSpeed)', 'pass', $s, $ev),
            };

            return [$data, $check];
        } catch (\Throwable $e) {
            return [null, $this->check('pagespeed_mobile', 'Mobile performance (PageSpeed)', 'skipped', null, 'PageSpeed error: ' . mb_substr($e->getMessage(), 0, 120))];
        }
    }

    // ------------------------------------------------------------------ contacts

    protected function extractContacts(string $html, string $pageUrl, ?string $domain, array &$contacts): void
    {
        $decoded = html_entity_decode($html);
        preg_match_all('/mailto:([^"\'?\s>]+)/i', $decoded, $m1);
        preg_match_all('/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/i', preg_replace('/<[^>]+>/', ' ', $decoded), $m2);
        foreach (array_merge($m1[1], $m2[0]) as $e) {
            $e = strtolower(trim(urldecode($e)));
            if (!filter_var($e, FILTER_VALIDATE_EMAIL) || preg_match('/\.(png|jpe?g|gif|webp|svg)$/', $e) || preg_match('/(example\.|sentry|wixpress|domain\.com|email\.com|yourname)/', $e)) {
                continue;
            }
            $ed = substr(strrchr($e, '@'), 1);
            $onDomain = $domain && ($ed === $domain || str_ends_with($ed, '.' . $domain));
            $contacts['emails'][$e] = ['value' => $e, 'source_url' => $pageUrl, 'on_company_domain' => $onDomain];
        }
        preg_match_all('/href=["\']tel:([^"\']+)["\']/i', $html, $m3);
        foreach ($m3[1] as $p) {
            $p = trim(urldecode($p));
            if (strlen(preg_replace('/\D/', '', $p)) >= 7) {
                $contacts['phones'][$p] = ['value' => $p, 'source_url' => $pageUrl];
            }
        }
        preg_match_all('#https?://(?:www\.)?(facebook\.com|instagram\.com|linkedin\.com|twitter\.com|x\.com|youtube\.com|tiktok\.com|wa\.me)/[^"\'\s<>]+#i', $html, $m4);
        foreach ($m4[0] as $s) {
            if (!preg_match('#/(sharer|share|intent|plugins|tr\?|dialog)#i', $s)) {
                $contacts['socials'][strtolower(parse_url($s, PHP_URL_HOST))] ??= $s;
            }
        }
    }

    protected function hasLeadForm(string $html): bool
    {
        return (bool) preg_match('/<form\b[\s\S]{0,4000}?(type=["\']email["\']|type=["\']tel["\']|<textarea|name=["\'][^"\']*(email|phone|message)[^"\']*["\'])/i', $html);
    }

    // ------------------------------------------------------------------ persistence

    protected function store(Company $company, string $url, ?string $finalUrl, string $status, ?int $http, ?int $ms, ?int $bytes, array $checks, array $tech, array $contacts, ?array $pagespeed): WebsiteAudit
    {
        $contacts = [
            'emails' => array_values($contacts['emails'] ?? []),
            'phones' => array_values($contacts['phones'] ?? []),
            'socials' => $contacts['socials'] ?? [],
            'people' => array_values($contacts['people'] ?? []),
            'pages' => array_values($contacts['pages'] ?? []),
            'people_crawl' => self::PEOPLE_CRAWL_VERSION,
        ];
        $score = $this->opportunityScore($checks);
        $opps = $this->mapOpportunities($checks);

        return WebsiteAudit::create([
            'company_id' => $company->id,
            'url' => mb_substr($url, 0, 500), // redirect targets can carry kilobytes of tracking query string
            'final_url' => $finalUrl === null ? null : mb_substr($finalUrl, 0, 500),
            'status' => $status,
            'http_status' => $http,
            'response_time_ms' => $ms,
            'page_size_bytes' => $bytes,
            'opportunity_score' => $score,
            'checks' => $checks,
            'technologies' => $tech,
            'contacts_found' => $contacts,
            'opportunities' => $opps,
            'pagespeed' => $pagespeed,
            'summary' => $this->summary($company, $status, $checks, $opps, $finalUrl ?? $url),
            'audited_at' => now(),
        ]);
    }

    public function opportunityScore(array $checks): int
    {
        $possible = 0;
        $earned = 0;
        foreach ($checks as $c) {
            $w = self::WEIGHTS[$c['key']] ?? 0;
            if (!$w || in_array($c['status'], ['info', 'skipped'], true)) {
                continue;
            }
            $possible += $w;
            $earned += $c['status'] === 'fail' ? $w : ($c['status'] === 'warn' ? $w / 2 : 0);
        }
        if (collect($checks)->contains(fn ($c) => $c['key'] === 'reachable' && $c['status'] === 'fail')) {
            return 85; // site down: strong rebuild opportunity, but unverifiable beyond that
        }

        return $possible ? (int) round(100 * $earned / $possible) : 0;
    }

    /** Evidence keys -> services from the catalog, each with the checks that justify it. */
    public function mapOpportunities(array $checks): array
    {
        $catalog = config('leadpilot.services');
        $out = [];
        foreach ($checks as $c) {
            if (!in_array($c['status'], ['fail', 'warn'], true)) {
                continue;
            }
            $services = [];
            foreach ((array) ($c['evidence_keys'] ?? []) as $key) {
                foreach ($catalog as $svc => $def) {
                    if (in_array($key, $def['triggers'], true)) {
                        $services[$svc] = true; // count each check once per service
                    }
                }
            }
            foreach (array_keys($services) as $svc) {
                $out[$svc] ??= ['service' => $svc, 'label' => $catalog[$svc]['label'], 'weight' => 0, 'evidence' => []];
                $out[$svc]['weight'] += (self::WEIGHTS[$c['key']] ?? 1) * ($c['status'] === 'fail' ? 1 : 0.5) * (float) ($catalog[$svc]['impact'] ?? 1);
                $out[$svc]['evidence'][] = $c['label'] . ': ' . $c['evidence'];
            }
        }
        usort($out, fn ($a, $b) => $b['weight'] <=> $a['weight']);

        return array_values($out);
    }

    protected function summary(Company $company, string $status, array $checks, array $opps, string $url): string
    {
        if ($status === 'blocked_by_robots') {
            return "Audit not performed: {$url} disallows crawling in robots.txt.";
        }
        if ($status === 'bot_protected') {
            return 'Audit not performed: ' . Company::normalizeDomain($url) . ' answered with a bot-protection page, so no website gaps are claimed.';
        }
        if ($status === 'unreachable') {
            return "{$url} could not be loaded during the audit (" . ($checks[0]['evidence'] ?? '') . ') Recommend confirming before outreach — it may be a temporary outage.';
        }
        $fail = collect($checks)->where('status', 'fail');
        $warn = collect($checks)->where('status', 'warn');
        $ran = collect($checks)->whereIn('status', ['pass', 'warn', 'fail'])->count();
        $top = $fail->merge($warn)->take(3)->map(fn ($c) => rtrim(preg_split('/(?<=[.—])\s/u', $c['evidence'])[0], ' .—'))->implode('; ');
        $s = "Ran {$ran} automated checks on {$url} on " . now()->toDateString() . ": {$fail->count()} failed, {$warn->count()} warnings.";
        if ($top) {
            $s .= " Main gaps: {$top}.";
        }
        if ($opps) {
            $s .= ' Best-fit offer: ' . $opps[0]['label'] . '.';
        }

        return $s;
    }

    protected function applyToCompany(Company $company, WebsiteAudit $audit): void
    {
        $update = ['website_opportunity_score' => $audit->opportunity_score, 'website_audited_at' => $audit->audited_at];
        $techNames = array_column((array) $audit->technologies, 'name');
        if ($techNames && empty($company->tech_stack)) {
            $update['tech_stack'] = $techNames;
        }

        $emails = collect($audit->contacts_found['emails'] ?? []);
        // Only addresses on the company's own domain: a footer "site by hello@agency.com" is not the company's inbox.
        $best = $emails->firstWhere('on_company_domain', true);
        $phone = collect($audit->contacts_found['phones'] ?? [])->first();
        if ($best && empty($company->public_email)) {
            $update['public_email'] = $best['value'];
        }
        if ($phone && empty($company->public_phone)) {
            $update['public_phone'] = $phone['value'];
        }
        $company->update($update);

        DataProvenance::record($company, array_filter([
            'tech_stack' => $update['tech_stack'] ?? null,
            'public_email' => isset($update['public_email']) ? $best['value'] : null,
            'public_phone' => $update['public_phone'] ?? null,
        ]), 'website_crawl', $best['source_url'] ?? $audit->final_url, 'crawl', 90);

        $this->addNamedContact($company, $audit);

        // Give contact-less leads the PUBLIC business inbox printed on the site (never a guessed address).
        if ($best) {
            Lead::where('company_id', $company->id)->where('contact_status', 'needs_enrichment')->whereNull('email')->get()
                ->each(function (Lead $lead) use ($best) {
                    if (Lead::where('email', $best['value'])->exists()) {
                        return;
                    }
                    $lead->update(['email' => $best['value'], 'contact_status' => 'generic_inbox']);
                    DataProvenance::record($lead, ['email' => $best['value']], 'website_crawl', $best['source_url'], 'crawl', 90);
                });
        }
    }

    /**
     * A decision-maker the site names together with their own published on-domain address becomes a named contact
     * (SQA item 21). The address is unverified until the SMTP check runs, like every other address.
     */
    protected function addNamedContact(Company $company, WebsiteAudit $audit): ?Lead
    {
        $person = collect($audit->contacts_found['people'] ?? [])->first(fn ($p) => !empty($p['email']));

        return $person ? self::namedContact($company, $person, $person['email'], 'Company website (team / about page)', 'crawl') : null;
    }

    /** One named decision-maker contact (unverified until the SMTP check runs), unless that address is already a lead. */
    public static function namedContact(Company $company, array $person, string $email, string $sourceName, string $method): ?Lead
    {
        $email = strtolower($email);
        if (Lead::where('email', $email)->exists()) {
            return null;
        }
        $sibling = Lead::where('company_id', $company->id)->orderBy('id')->first();
        $lead = Lead::create([
            'company_id' => $company->id,
            'icp_profile_id' => $sibling?->icp_profile_id,
            'first_name' => $person['first_name'],
            'last_name' => $person['last_name'],
            'job_title' => $person['role'],
            'email' => $email,
            'phone' => $company->public_phone,
            'contact_status' => 'identified',
            'country' => $company->country,
            'industry' => $company->industry,
            'source_name' => $sourceName,
            'source_url' => $person['source_url'] ?? null,
            'discovery_date' => now(),
            'relevant_services' => $sibling?->relevant_services,
            'email_verification_status' => 'unverified',
            'lifecycle_stage' => 'new',
        ]);
        DataProvenance::record($lead, ['first_name' => $person['first_name'], 'last_name' => $person['last_name'], 'job_title' => $person['role'], 'email' => $email],
            $method === 'crawl' ? 'website_crawl' : 'email_pattern_smtp', $person['source_url'] ?? null, $method, 85);

        return $lead;
    }

    /** Replace this company's previous audit-derived signal with the current evidence. */
    protected function syncSignal(Company $company, WebsiteAudit $audit): void
    {
        BuyingSignal::where('company_id', $company->id)->where('signal_type', 'website_issues')
            ->where('metadata->source', 'website_audit')->delete();

        if ($audit->status !== 'unreachable' && ($audit->opportunity_score < 25 || !$audit->opportunities)) { // unreachable arrives here only once confirmed
            return;
        }
        $top = $audit->opportunities[0] ?? null;
        BuyingSignal::create([
            'company_id' => $company->id,
            'signal_type' => 'website_issues',
            'title' => 'Website audit: ' . count($audit->issues()) . ' issue(s), opportunity ' . $audit->opportunity_score . '/100',
            'source_url' => $audit->final_url ?? $audit->url,
            'evidence_text' => $audit->summary,
            'observed_date' => now()->toDateString(),
            'confidence' => min(95, 50 + (int) ($audit->opportunity_score / 2)),
            'relevant_service' => $top['service'] ?? 'web_redesign',
            'expiry_date' => now()->addDays(90)->toDateString(),
            'verification_status' => 'verified',
            'metadata' => ['source' => 'website_audit', 'audit_id' => $audit->id, 'evidence_keys' => $audit->issueKeys()],
        ]);
    }

    // ------------------------------------------------------------------ helpers

    protected function check(string $key, string $label, string $status, mixed $value, string $evidence, array $evidenceKeys = []): array
    {
        return array_filter([
            'key' => $key, 'label' => $label, 'status' => $status,
            'value' => is_scalar($value) || $value === null ? $value : json_encode($value),
            'evidence' => $evidence, 'evidence_keys' => $evidenceKeys ?: null,
        ], fn ($v) => $v !== null);
    }

    protected function get(string $url): Response
    {
        [$res, $final] = $this->fetcher->fetch($url);
        $this->lastFinalUrl = $final;

        return $res;
    }

    public function robotsAllows(string $url): bool
    {
        $origin = $this->origin($url);
        if (!array_key_exists($origin, $this->robotsCache)) {
            $rules = ['*' => [], 'self' => []];
            try {
                [$r] = $this->fetcher->fetch($origin . '/robots.txt', 'GET', 8);
                if ($r->successful()) {
                    $rules = $this->parseRobots($r->body());
                } elseif ($r->serverError()) {
                    $rules = ['*' => [['disallow', '/']], 'self' => []]; // 5xx => treat as full disallow (Google's convention)
                }
            } catch (BlockedUrlException) {
                $rules = ['*' => [['disallow', '/']], 'self' => []];
            } catch (\Throwable) {
            }
            $this->robotsCache[$origin] = $rules;
        }
        $rules = $this->robotsCache[$origin];
        $set = $rules['self'] ?: $rules['*'];
        $path = $this->path($url);
        $verdict = true;
        $longest = -1;
        foreach ($set as [$type, $prefix]) {
            if ($prefix === '' && $type === 'disallow') {
                continue;
            }
            $re = '#^' . str_replace(['\*', '\$'], ['.*', '$'], preg_quote($prefix, '#')) . '#';
            if (preg_match($re, $path) && strlen($prefix) > $longest) {
                $longest = strlen($prefix);
                $verdict = $type === 'allow';
            }
        }

        return $verdict;
    }

    protected function parseRobots(string $body): array
    {
        $botToken = strtolower(strtok($this->ua, '/'));
        $rules = ['*' => [], 'self' => []];
        $agents = [];
        $inRules = false;
        foreach (preg_split('/\r?\n/', $body) as $line) {
            $line = trim(preg_replace('/#.*/', '', $line));
            if ($line === '' || !str_contains($line, ':')) {
                continue;
            }
            [$k, $v] = array_map('trim', explode(':', $line, 2));
            $k = strtolower($k);
            if ($k === 'user-agent') {
                if ($inRules) {
                    $agents = [];
                    $inRules = false;
                }
                $agents[] = strtolower($v);
            } elseif (in_array($k, ['allow', 'disallow'], true)) {
                $inRules = true;
                foreach ($agents as $a) {
                    if ($a === '*') {
                        $rules['*'][] = [$k, $v];
                    } elseif ($a !== '' && strtolower(strtok($a, '/')) === $botToken) {
                        $rules['self'][] = [$k, $v];
                    }
                }
            }
        }

        return $rules;
    }

    public function normalizeUrl(?string $url): ?string
    {
        $url = trim((string) $url);
        if ($url === '') {
            return null;
        }
        if (!preg_match('#^https?://#i', $url)) {
            $url = 'https://' . $url;
        }

        return filter_var($url, FILTER_VALIDATE_URL) ? $url : null;
    }

    protected function origin(string $url): string
    {
        $p = parse_url($url);

        return ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '') . (isset($p['port']) ? ':' . $p['port'] : '');
    }

    protected function path(string $url): string
    {
        $p = parse_url($url);

        return ($p['path'] ?? '/') . (isset($p['query']) ? '?' . $p['query'] : '');
    }

    protected function absolutize(string $href, string $base): ?string
    {
        if (preg_match('#^https?://#i', $href)) {
            return $href;
        }
        if (str_starts_with($href, '//')) {
            return (parse_url($base, PHP_URL_SCHEME) ?: 'https') . ':' . $href;
        }
        if (str_starts_with($href, '/')) {
            return $this->origin($base) . $href;
        }
        $dir = preg_replace('#/[^/]*$#', '/', $this->path($base) ?: '/');

        return $this->origin($base) . $dir . $href;
    }
}
