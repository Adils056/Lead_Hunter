<?php

namespace App\Services\Audit;

use Carbon\Carbon;

/**
 * Reads a website's TLS certificate (connecting to the vetted public IP, never an internal address) and reports
 * what a visitor's browser would complain about: expired, not yet valid, issued for another name, self-signed,
 * or expiring soon. No CA store is needed for these facts, so the result does not depend on the server's setup.
 */
class TlsInspector
{
    /** @return array{ok: bool, problem: ?string, expires_at: ?Carbon, days_left: ?int, issuer: ?string, error: ?string} */
    public function inspect(string $url, int $timeout = 8): array
    {
        $blank = ['ok' => false, 'problem' => null, 'expires_at' => null, 'days_left' => null, 'issuer' => null, 'error' => null];
        try {
            [$host, , $ip] = (new SafeFetcher('LeadPilotBot', $timeout))->vetted(preg_replace('#^http://#i', 'https://', $url));
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()] + $blank;
        }
        $ctx = stream_context_create(['ssl' => ['capture_peer_cert' => true, 'verify_peer' => false, 'verify_peer_name' => false,
            'allow_self_signed' => true, 'peer_name' => $host, 'SNI_enabled' => true]]);
        $target = 'ssl://' . (str_contains($ip, ':') ? "[{$ip}]" : $ip) . ':443';
        $fp = @stream_socket_client($target, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx);
        if (!$fp) {
            return ['error' => "TLS connection failed: {$errstr}"] + $blank;
        }
        $cert = stream_context_get_params($fp)['options']['ssl']['peer_certificate'] ?? null;
        fclose($fp);
        $info = $cert ? openssl_x509_parse($cert) : false;
        if (!$info) {
            return ['error' => 'No certificate presented.'] + $blank;
        }

        return $this->judge($info, $host);
    }

    /** Pure verdict on a parsed certificate (openssl_x509_parse shape) for $host; public for tests. */
    public function judge(array $info, string $host, ?Carbon $now = null): array
    {
        $now ??= now();
        $from = Carbon::createFromTimestamp((int) ($info['validFrom_time_t'] ?? 0));
        $to = Carbon::createFromTimestamp((int) ($info['validTo_time_t'] ?? 0));
        $issuer = $info['issuer']['O'] ?? $info['issuer']['CN'] ?? null;
        $names = [];
        foreach (explode(',', (string) ($info['extensions']['subjectAltName'] ?? '')) as $san) {
            if (preg_match('/^\s*DNS:(.+)$/i', $san, $m)) {
                $names[] = strtolower(trim($m[1]));
            }
        }
        if (!$names && !empty($info['subject']['CN'])) {
            $names[] = strtolower((string) $info['subject']['CN']);
        }
        $host = strtolower($host);
        $covers = collect($names)->contains(fn ($n) => $n === $host
            || (str_starts_with($n, '*.') && str_ends_with($host, substr($n, 1)) && substr_count($host, '.') === substr_count($n, '.')));
        $selfSigned = ($info['issuer'] ?? null) === ($info['subject'] ?? null);
        $days = (int) $now->copy()->startOfDay()->diffInDays($to->copy()->startOfDay(), false);

        $problem = match (true) {
            $to->lt($now) => 'expired on ' . $to->format('j M Y'),
            $from->gt($now) => 'not valid until ' . $from->format('j M Y'),
            !$covers => 'issued for ' . implode(', ', array_slice($names, 0, 3)) . ', not ' . $host,
            $selfSigned => 'self-signed (browsers show a security warning)',
            default => null,
        };

        return ['ok' => $problem === null, 'problem' => $problem, 'expires_at' => $to, 'days_left' => $days, 'issuer' => $issuer, 'error' => null];
    }
}
