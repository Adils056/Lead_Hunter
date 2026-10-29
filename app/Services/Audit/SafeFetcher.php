<?php

namespace App\Services\Audit;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * SSRF-safe HTTP fetcher for crawling third-party / user-supplied URLs.
 *
 *  - Only http/https.
 *  - Every hop (initial URL and each redirect) is resolved and every resolved IP must be public;
 *    fails CLOSED when a host does not resolve.
 *  - The connection is pinned to the vetted IP (CURLOPT_RESOLVE) so DNS rebinding between
 *    check and connect cannot redirect the request to an internal address.
 *  - Redirects are followed manually (max 5), re-validating each Location.
 *
 * Tests can map fake hosts to public IPs via config('leadpilot.sources.website_crawler.dns_overrides').
 */
class SafeFetcher
{
    public function __construct(protected string $ua, protected int $timeout = 15)
    {
    }

    /** @return array{0: Response, 1: string} response and final URL */
    public function fetch(string $url, string $method = 'GET', ?int $timeout = null, int $maxRedirects = 5): array
    {
        $current = $url;
        for ($hop = 0; $hop <= $maxRedirects; $hop++) {
            [$host, $port, $ip] = $this->vet($current);

            $req = Http::withUserAgent($this->ua)
                ->timeout($timeout ?? $this->timeout)
                ->withOptions([
                    'allow_redirects' => false,
                    'curl' => [CURLOPT_RESOLVE => ["{$host}:{$port}:" . (str_contains($ip, ':') ? "[{$ip}]" : $ip)]],
                ])
                ->accept('text/html,application/xhtml+xml,text/plain;q=0.9,*/*;q=0.5');
            $res = strtoupper($method) === 'HEAD' ? $req->head($current) : $req->get($current);

            if (in_array($res->status(), [301, 302, 303, 307, 308], true) && ($loc = $res->header('Location'))) {
                $current = $this->resolveLocation($loc, $current);
                if (strtoupper($method) === 'HEAD' && $res->status() === 303) {
                    $method = 'GET';
                }
                continue;
            }

            return [$res, $current];
        }
        throw new BlockedUrlException("Too many redirects starting at {$url}.");
    }

    public function blockReason(string $url): ?string
    {
        try {
            $this->vet($url);

            return null;
        } catch (BlockedUrlException $e) {
            return $e->getMessage();
        }
    }

    /** @return array{0: string, 1: int, 2: string} host, port, vetted IP — for raw connections (TLS certificate check) */
    public function vetted(string $url): array
    {
        return $this->vet($url);
    }

    /** @return array{0: string, 1: int, 2: string} host, port, vetted IP */
    protected function vet(string $url): array
    {
        $p = parse_url($url);
        $scheme = strtolower($p['scheme'] ?? '');
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new BlockedUrlException("Refusing non-HTTP URL: {$url}");
        }
        $host = strtolower(trim((string) ($p['host'] ?? ''), '[]'));
        if ($host === '' || in_array($host, ['localhost', 'localhost.localdomain'], true) || str_ends_with($host, '.localhost') || str_ends_with($host, '.internal') || str_ends_with($host, '.local')) {
            throw new BlockedUrlException("Refusing to fetch local/internal host '{$host}'.");
        }
        if (isset($p['user']) || isset($p['pass'])) {
            throw new BlockedUrlException('Refusing URL with embedded credentials.');
        }
        $port = (int) ($p['port'] ?? ($scheme === 'https' ? 443 : 80));
        if (!in_array($port, [80, 443, 8080, 8443], true)) {
            throw new BlockedUrlException("Refusing non-web port {$port}.");
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : $this->resolve($host);
        if (!$ips) {
            throw new BlockedUrlException("Host '{$host}' did not resolve.");
        }
        foreach ($ips as $ip) {
            if (!self::isPublicIp($ip)) {
                throw new BlockedUrlException("Refusing {$host}: resolves to non-public address {$ip}.");
            }
        }

        return [$host, $port, $ips[0]];
    }

    protected function resolve(string $host): array
    {
        $overrides = (array) config('leadpilot.sources.website_crawler.dns_overrides', []);
        if (array_key_exists($host, $overrides)) {
            return (array) $overrides[$host];
        }
        $ips = [];
        $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];
        foreach ($records as $r) {
            if (!empty($r['ip'])) {
                $ips[] = $r['ip'];
            }
            if (!empty($r['ipv6'])) {
                $ips[] = $r['ipv6'];
            }
        }

        return array_values(array_unique($ips));
    }

    public static function isPublicIp(string $ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }
        // IPv4-mapped / -compatible IPv6 => check the embedded IPv4
        if (preg_match('/^::(ffff:)?(\d+\.\d+\.\d+\.\d+)$/i', $ip, $m)) {
            return self::isPublicIp($m[2]);
        }
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $n = ip2long($ip);
            $blocked = [['0.0.0.0', 8], ['100.64.0.0', 10], ['127.0.0.0', 8], ['169.254.0.0', 16], ['192.0.0.0', 24], ['198.18.0.0', 15], ['224.0.0.0', 4], ['240.0.0.0', 4]];
            foreach ($blocked as [$net, $bits]) {
                $mask = -1 << (32 - $bits);
                if (($n & $mask) === (ip2long($net) & $mask)) {
                    return false;
                }
            }

            return true;
        }
        $lower = strtolower($ip);

        return !($lower === '::1' || $lower === '::' || str_starts_with($lower, 'fe8') || str_starts_with($lower, 'fe9') || str_starts_with($lower, 'fea') || str_starts_with($lower, 'feb') || str_starts_with($lower, 'fc') || str_starts_with($lower, 'fd') || str_starts_with($lower, 'ff') || str_starts_with($lower, '64:ff9b'));
    }

    public function resolveLocation(string $loc, string $base): string
    {
        if (preg_match('#^https?://#i', $loc)) {
            return $loc;
        }
        $b = parse_url($base);
        $origin = ($b['scheme'] ?? 'https') . '://' . ($b['host'] ?? '') . (isset($b['port']) ? ':' . $b['port'] : '');
        if (str_starts_with($loc, '//')) {
            return ($b['scheme'] ?? 'https') . ':' . $loc;
        }
        if (str_starts_with($loc, '/')) {
            return $origin . $loc;
        }
        $dir = preg_replace('#/[^/]*$#', '/', $b['path'] ?? '/');

        return $origin . $dir . $loc;
    }

    public static function sameOrigin(string $a, string $b): bool
    {
        $x = parse_url($a);
        $y = parse_url($b);
        if (!$x || !$y || isset($x['user']) || isset($y['user'])) {
            return false;
        }
        $norm = fn ($p) => [strtolower($p['scheme'] ?? ''), strtolower(preg_replace('/^www\./', '', $p['host'] ?? '')), $p['port'] ?? null];

        return $norm($x) === $norm($y);
    }

    /**
     * Same website, whatever the scheme: http://firm.com links to https://www.firm.com/attorneys and redirects
     * there. Used to decide which of a site's own pages the audit may read (every fetch is still SSRF-vetted).
     */
    public static function sameSite(string $a, string $b): bool
    {
        $x = parse_url($a);
        $y = parse_url($b);
        if (!$x || !$y || isset($x['user']) || isset($y['user'])) {
            return false;
        }
        $host = fn ($p) => strtolower(preg_replace('/^www\./', '', $p['host'] ?? ''));
        $port = fn ($p) => in_array($p['port'] ?? null, [null, 80, 443], true) ? null : $p['port'];

        return $host($x) !== '' && $host($x) === $host($y) && $port($x) === $port($y);
    }

    /** Only allow http(s) links to be stored/rendered (blocks javascript:, data: etc.). */
    public static function safeUrl(?string $url): ?string
    {
        $url = trim((string) $url);

        return preg_match('#^https?://[^\s<>"\']+$#i', $url) ? mb_substr($url, 0, 500) : null;
    }
}
