<?php

namespace App\Services;

use App\Models\EmailVerification;
use App\Models\Integration;
use App\Models\Lead;
use App\Models\UsageLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class EmailVerificationService
{
    protected ?string $hunterApiKey = null;
    protected bool $isHunterConfigured = false;

    public function __construct()
    {
        $integration = Integration::where('provider', 'hunter')->first();
        if ($integration && $integration->is_active) {
            $creds = $integration->getDecryptedCredentials();
            $this->hunterApiKey = $creds['api_key'] ?? null;
        } else {
            $this->hunterApiKey = env('HUNTER_API_KEY');
        }

        $this->isHunterConfigured = !empty($this->hunterApiKey) && !str_starts_with($this->hunterApiKey, 'placeholder');
    }

    /**
     * Verify an email address.
     * Checks local cache first to avoid redundant API credit expenditure.
     */
    public function verifyEmail(string $email, ?Lead $lead = null, ?int $userId = null): array
    {
        $email = strtolower(trim($email));

        // Basic syntax check
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->recordResult($email, 'invalid', [
                'score' => 0,
                'is_disposable' => false,
                'is_free_mailbox' => false,
                'has_mx_records' => false,
                'provider' => 'syntax_validator',
                'details' => 'Invalid RFC email syntax.',
            ], $lead, 0);
        }

        // 1. Check local cache (within past 30 days) to save credits
        $cached = EmailVerification::where('email', $email)
            ->where('created_at', '>=', now()->subDays(30))
            ->latest()
            ->first();

        if ($cached) {
            if ($lead && $lead->email === $email) {
                $lead->update([
                    'email_verification_status' => $cached->status,
                    'last_verified_at' => $cached->verified_at,
                ]);
            }
            return [
                'status' => $cached->status,
                'score' => $cached->score,
                'provider' => $cached->provider . ' (cached)',
                'is_disposable' => $cached->is_disposable,
                'is_free_mailbox' => $cached->is_free_mailbox,
                'has_mx_records' => $cached->has_mx_records,
                'has_smtp_check' => $cached->has_smtp_check,
            ];
        }

        // 2. If Hunter API is configured, call real Hunter.io API
        if ($this->isHunterConfigured) {
            try {
                return $this->verifyWithHunter($email, $lead, $userId);
            } catch (\Exception $e) {
                Log::warning("Hunter API failed for {$email}: " . $e->getMessage() . ". Falling back to DNS validation.");
            }
        }

        // 3. Fallback to DNS MX records and disposable domain checks
        return $this->verifyWithDnsAndSyntax($email, $lead);
    }

    /**
     * Hunter.io API verification
     */
    protected function verifyWithHunter(string $email, ?Lead $lead, ?int $userId): array
    {
        $response = Http::timeout(15)
            ->get('https://api.hunter.io/v2/email-verifier', [
                'email' => $email,
                'api_key' => $this->hunterApiKey,
            ]);

        if (!$response->successful()) {
            throw new \Exception("Hunter API responded with status " . $response->status());
        }

        $data = $response->json('data') ?? [];
        $rawStatus = $data['status'] ?? 'unknown'; // deliverable, risky, undeliverable, etc.
        
        $status = match ($rawStatus) {
            'deliverable' => 'verified',
            'risky' => ($data['result'] ?? '') === 'catch_all' ? 'catch_all' : 'risky',
            'undeliverable' => 'invalid',
            default => 'unknown',
        };

        $resultData = [
            'score' => $data['score'] ?? 50,
            'is_disposable' => $data['disposable'] ?? false,
            'is_free_mailbox' => ($data['webmail'] ?? false),
            'has_mx_records' => $data['mx_records'] ?? true,
            'has_smtp_check' => $data['smtp_check'] ?? true,
            'provider' => 'hunter',
            'raw_response' => $data,
        ];

        UsageLog::create([
            'provider' => 'hunter',
            'service_type' => 'email_verification',
            'units_consumed' => 1,
            'cost_estimated' => 0.015,
            'user_id' => $userId ?? Auth::id(),
            'metadata' => ['email' => $email, 'status' => $status],
            'occurred_at' => now(),
        ]);

        return $this->recordResult($email, $status, $resultData, $lead, 1);
    }

    /**
     * Free verification (the alternative to Hunter): syntax -> real MX lookup -> disposable/free-mail lists ->
     * SMTP mailbox probe (RCPT TO on the MX, no mail sent). Statuses are honest about what was proven:
     *  - invalid: no MX, disposable domain, or the MX rejected the mailbox (5xx)
     *  - verified: MX accepted the mailbox (score 95; 80 for a free mailbox), or MX exists and the probe could not run (85)
     *  - risky: catch-all domain (accepts any address) or a free mailbox that could not be probed
     */
    protected function verifyWithDnsAndSyntax(string $email, ?Lead $lead): array
    {
        $domain = strtolower((string) substr(strrchr($email, '@'), 1));
        $mxHosts = $this->mxHosts($domain);
        $hasMx = $mxHosts !== [];

        $isFree = in_array($domain, (array) config('leadpilot.free_mail_domains', []), true);
        $isDisposable = in_array($domain, self::DISPOSABLE_DOMAINS, true);

        $probe = ['result' => 'skipped', 'code' => null, 'host' => null, 'note' => 'not probed'];
        if ($hasMx && !$isDisposable) {
            $probe = $this->smtpProbe($email, $mxHosts);
        }
        $probed = in_array($probe['result'], ['deliverable', 'undeliverable', 'catch_all'], true);

        [$status, $score, $details] = match (true) {
            !$hasMx => ['invalid', 0, "No MX or A record for {$domain}."],
            $isDisposable => ['invalid', 10, "{$domain} is a disposable-mail domain."],
            $probe['result'] === 'undeliverable' => ['invalid', 5, "Mail server {$probe['host']} rejected the mailbox (SMTP {$probe['code']})."],
            $probe['result'] === 'deliverable' && $isFree => ['verified', 80, "Mailbox accepted by {$probe['host']} (SMTP {$probe['code']}); free mailbox provider."],
            $probe['result'] === 'deliverable' => ['verified', 95, "Mailbox accepted by {$probe['host']} (SMTP {$probe['code']})."],
            $probe['result'] === 'catch_all' => ['risky', 60, "{$domain} accepts any address (catch-all): mailbox existence cannot be proven."],
            $isFree => ['risky', 55, 'Free mailbox provider; mailbox not probed (' . $probe['note'] . ').'],
            default => ['verified', 85, 'Domain accepts mail (MX found); mailbox not probed (' . $probe['note'] . ').'],
        };

        return $this->recordResult($email, $status, [
            'score' => $score,
            'is_disposable' => $isDisposable,
            'is_free_mailbox' => $isFree,
            'has_mx_records' => $hasMx,
            'has_smtp_check' => $probed,
            'provider' => $probed ? 'smtp_check' : 'dns_check',
            'details' => $details,
            'raw_response' => ['checked_domain' => $domain, 'has_mx' => $hasMx, 'mx' => array_slice($mxHosts, 0, 3), 'smtp_probe' => $probe, 'details' => $details],
        ], $lead, 0);
    }

    public const DISPOSABLE_DOMAINS = [
        'mailinator.com', '10minutemail.com', 'tempmail.com', 'temp-mail.org', 'guerrillamail.com', 'guerrillamail.net', 'trashmail.com', 'yopmail.com',
        'sharklasers.com', 'getnada.com', 'dispostable.com', 'maildrop.cc', 'fakeinbox.com', 'throwawaymail.com', 'mohmal.com', 'emailondeck.com',
    ];

    /** MX hosts by priority; falls back to the A record host (RFC 5321 implicit MX). Empty = domain cannot receive mail. */
    public function mxHosts(string $domain): array
    {
        if ($domain === '') {
            return [];
        }
        $hosts = [];
        $weights = [];
        if (@getmxrr($domain, $hosts, $weights) && $hosts) {
            array_multisort($weights, SORT_ASC, $hosts);

            return array_values(array_unique(array_filter($hosts)));
        }

        return @checkdnsrr($domain, 'A') ? [$domain] : [];
    }

    /**
     * SMTP mailbox probe: connect to the MX on port 25, EHLO, MAIL FROM, RCPT TO:<email>, then RCPT TO a random
     * address to detect catch-all. No message is sent (QUIT after RCPT). When no MX accepts a TCP connection the
     * network is treated as blocking port 25 for an hour (most home ISPs do) and the result is "blocked".
     *
     * @return array{result: string, code: ?int, host: ?string, note: string}
     */
    public function smtpProbe(string $email, array $mxHosts): array
    {
        if (!config('leadpilot.smtp_probe', true)) {
            return ['result' => 'skipped', 'code' => null, 'host' => null, 'note' => 'SMTP probe disabled (LEADPILOT_SMTP_PROBE)'];
        }
        if (Cache::has('smtp_probe_port25_blocked')) {
            return ['result' => 'blocked', 'code' => null, 'host' => null, 'note' => 'outbound port 25 blocked on this network (rechecked hourly)'];
        }
        $domain = substr(strrchr($email, '@'), 1);
        $fromAddress = (string) config('mail.from.address');
        $helo = str_contains($fromAddress, '@') ? substr(strrchr($fromAddress, '@'), 1) : 'leadpilot.local';
        $from = str_contains($fromAddress, '@') ? $fromAddress : "postmaster@{$helo}";
        $connected = false;

        foreach (array_slice($mxHosts, 0, 3) as $host) {
            $fp = @stream_socket_client("tcp://{$host}:25", $errno, $errstr, 8);
            if (!$fp) {
                continue;
            }
            $connected = true;
            stream_set_timeout($fp, 10);
            try {
                if ($this->smtpRead($fp) !== 220) {
                    fclose($fp);
                    continue;
                }
                if ($this->smtpCmd($fp, "EHLO {$helo}") !== 250 && $this->smtpCmd($fp, "HELO {$helo}") !== 250) {
                    fclose($fp);
                    continue;
                }
                if ($this->smtpCmd($fp, "MAIL FROM:<{$from}>") !== 250) {
                    $this->smtpCmd($fp, 'QUIT');
                    fclose($fp);
                    continue;
                }
                $code = $this->smtpCmd($fp, "RCPT TO:<{$email}>");
                $result = match (true) {
                    $code >= 250 && $code < 260 => 'deliverable',
                    $code >= 500 => 'undeliverable',
                    default => 'unknown', // 4xx: greylisting / temporary policy — proves nothing either way
                };
                if ($result === 'deliverable') {
                    $random = 'lp' . bin2hex(random_bytes(6)) . 'zq@' . $domain;
                    $rc = $this->smtpCmd($fp, "RCPT TO:<{$random}>");
                    if ($rc >= 250 && $rc < 260) {
                        $result = 'catch_all';
                    }
                }
                $this->smtpCmd($fp, 'QUIT');
                fclose($fp);

                return ['result' => $result, 'code' => $code, 'host' => $host, 'note' => "probed via {$host}"];
            } catch (\Throwable $e) {
                @fclose($fp);
                continue;
            }
        }
        if (!$connected) {
            Cache::put('smtp_probe_port25_blocked', now()->toIso8601String(), now()->addHour());

            return ['result' => 'blocked', 'code' => null, 'host' => null, 'note' => 'no MX accepted a connection on port 25 (network blocks outbound SMTP)'];
        }

        return ['result' => 'unknown', 'code' => null, 'host' => null, 'note' => 'MX reachable but gave no usable answer'];
    }

    /** @param resource $fp */
    protected function smtpCmd($fp, string $cmd): int
    {
        fwrite($fp, $cmd . "\r\n");

        return $this->smtpRead($fp);
    }

    /** Reads a (possibly multi-line) SMTP reply and returns its 3-digit code (0 on timeout/garbage). */
    protected function smtpRead($fp): int
    {
        $code = 0;
        for ($i = 0; $i < 50; $i++) {
            $line = fgets($fp, 1024);
            if ($line === false) {
                break;
            }
            $code = (int) substr($line, 0, 3);
            if (!isset($line[3]) || $line[3] !== '-') {
                break;
            }
        }

        return $code;
    }

    protected function recordResult(string $email, string $status, array $data, ?Lead $lead, int $creditsUsed): array
    {
        $verification = EmailVerification::create([
            'lead_id' => $lead?->id,
            'email' => $email,
            'status' => $status,
            'provider' => $data['provider'] ?? 'dns_check',
            'score' => $data['score'] ?? null,
            'is_disposable' => $data['is_disposable'] ?? false,
            'is_free_mailbox' => $data['is_free_mailbox'] ?? false,
            'has_mx_records' => $data['has_mx_records'] ?? true,
            'has_smtp_check' => $data['has_smtp_check'] ?? false,
            'provider_response' => $data['raw_response'] ?? $data,
            'credits_used' => $creditsUsed,
            'verified_at' => now(),
        ]);

        if ($lead && $lead->email === $email) {
            $lead->update([
                'email_verification_status' => $status,
                'last_verified_at' => now(),
            ]);
        }

        return [
            'id' => $verification->id,
            'status' => $status,
            'score' => $data['score'] ?? null,
            'provider' => $data['provider'] ?? 'dns_check',
            'is_disposable' => $data['is_disposable'] ?? false,
            'is_free_mailbox' => $data['is_free_mailbox'] ?? false,
            'has_mx_records' => $data['has_mx_records'] ?? true,
            'has_smtp_check' => $data['has_smtp_check'] ?? false,
        ];
    }
}
