<?php

namespace App\Services;

use App\Models\EmailVerification;
use App\Models\Integration;
use App\Models\Lead;
use App\Services\Integrations\BudgetGuard;
use App\Services\Integrations\CostLedger;
use App\Support\EmailStatus;
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
     * Verify an email address with a free-first waterfall:
     *   1. local cache (proven results 30 days; mx_only/unknown only for `inconclusive_cache_hours` so a later probe can upgrade them)
     *   2. free check: syntax -> MX -> disposable/free-mail lists -> SMTP mailbox probe (no mail sent)
     *   3. Hunter, only when step 2 could not reach the mailbox (mx_only / unknown), Hunter is configured, and the budget allows it
     * The stored status uses the EmailStatus vocabulary; nothing becomes "verified" without an SMTP or Hunter answer.
     */
    public function verifyEmail(string $email, ?Lead $lead = null, ?int $userId = null, bool $fresh = false): array
    {
        $email = strtolower(trim($email));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->recordResult($email, EmailStatus::INVALID, [
                'score' => 0,
                'is_disposable' => false,
                'is_free_mailbox' => false,
                'has_mx_records' => false,
                'provider' => 'syntax_validator',
                'details' => 'Invalid RFC email syntax.',
            ], $lead, 0);
        }

        if (!$fresh && ($cached = $this->cached($email))) {
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

        [$status, $data] = $this->freeCheck($email);
        $credits = 0;
        $steps = [['provider' => $data['provider'], 'status' => $status]];

        if (in_array($status, [EmailStatus::MX_ONLY, EmailStatus::UNKNOWN], true)) {
            $skip = $this->hunterSkipReason();
            if ($skip === null) {
                try {
                    [$hStatus, $hData] = $this->verifyWithHunter($email, $userId);
                    $credits = 1;
                    $steps[] = ['provider' => 'hunter', 'status' => $hStatus];
                    if ($hStatus !== EmailStatus::UNKNOWN) {
                        [$status, $data] = [$hStatus, $hData];
                    }
                } catch (\Exception $e) {
                    Log::warning("Hunter API failed for {$email}: " . $e->getMessage() . '. Keeping the free result.');
                    $steps[] = ['provider' => 'hunter', 'status' => 'error', 'note' => mb_substr($e->getMessage(), 0, 120)];
                }
            } else {
                $steps[] = ['provider' => 'hunter', 'status' => 'skipped', 'note' => $skip];
            }
        }
        $data['raw_response'] = ($data['raw_response'] ?? []) + ['waterfall' => $steps];

        return $this->recordResult($email, $status, $data, $lead, $credits);
    }

    protected function cached(string $email): ?EmailVerification
    {
        $inconclusiveHours = (int) config('leadpilot.email_verification.inconclusive_cache_hours', 24);

        return EmailVerification::where('email', $email)
            ->where(fn ($q) => $q->where(fn ($q) => $q->whereIn('status', EmailStatus::PROVEN)->where('created_at', '>=', now()->subDays(30)))
                ->orWhere(fn ($q) => $q->whereNotIn('status', EmailStatus::PROVEN)->where('created_at', '>=', now()->subHours($inconclusiveHours))))
            ->latest('id')
            ->first();
    }

    /** Null when Hunter may be called for an inconclusive free result; otherwise why not. */
    protected function hunterSkipReason(): ?string
    {
        if (!$this->isHunterConfigured) {
            return 'Hunter not configured';
        }
        if (!config('leadpilot.email_verification.hunter_escalation', true)) {
            return 'Hunter escalation disabled (LEADPILOT_HUNTER_ESCALATION)';
        }
        $budget = app(BudgetGuard::class)->check('hunter', self::HUNTER_COST_CENTS);

        return $budget['allowed'] ? null : $budget['reason'];
    }

    public const HUNTER_COST_CENTS = 2;

    /**
     * Hunter.io v2 email-verifier. `data.status` is the current field (valid, invalid, accept_all, webmail, disposable,
     * unknown); `data.result` (deliverable, undeliverable, risky) is the deprecated one and only used as a fallback.
     *
     * @return array{0: string, 1: array}
     */
    protected function verifyWithHunter(string $email, ?int $userId): array
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
        $status = self::mapHunter($data);

        app(CostLedger::class)->record('hunter', 'email_verification', self::HUNTER_COST_CENTS, true, [
            'user_id' => $userId ?? Auth::id(),
            'email' => $email,
            'status' => $status,
        ]);

        return [$status, [
            'score' => $data['score'] ?? null,
            'is_disposable' => (bool) ($data['disposable'] ?? false),
            'is_free_mailbox' => (bool) ($data['webmail'] ?? false),
            'has_mx_records' => (bool) ($data['mx_records'] ?? false),
            // Hunter's answer is a mailbox-level check whenever it reached a verdict about the mailbox.
            'has_smtp_check' => (bool) ($data['smtp_check'] ?? false) || in_array($status, [EmailStatus::VERIFIED, EmailStatus::INVALID], true),
            'provider' => 'hunter',
            'raw_response' => $data,
        ]];
    }

    public static function mapHunter(array $data): string
    {
        $smtpOk = (bool) ($data['smtp_check'] ?? false);

        return match ($data['status'] ?? null) {
            'valid' => EmailStatus::VERIFIED,
            'invalid', 'disposable' => EmailStatus::INVALID,
            'accept_all' => EmailStatus::CATCH_ALL,
            'webmail' => $smtpOk || ($data['result'] ?? null) === 'deliverable' ? EmailStatus::VERIFIED : EmailStatus::MX_ONLY,
            'unknown' => EmailStatus::UNKNOWN,
            default => match ($data['result'] ?? null) {
                'deliverable' => EmailStatus::VERIFIED,
                'undeliverable' => EmailStatus::INVALID,
                'risky' => ($data['accept_all'] ?? false) ? EmailStatus::CATCH_ALL : EmailStatus::RISKY,
                default => EmailStatus::UNKNOWN,
            },
        };
    }

    /**
     * Free verification (the alternative to Hunter): syntax -> real MX lookup -> disposable/free-mail lists ->
     * SMTP mailbox probe (RCPT TO on the MX, no mail sent). Statuses say exactly what was proven:
     *  - invalid:   no MX, disposable domain, or the MX rejected the mailbox (5xx)
     *  - verified:  the MX accepted the mailbox (score 95; 80 for a free mailbox)
     *  - catch_all: the domain accepts any address, so mailbox existence cannot be proven
     *  - mx_only:   the domain has an MX but the mailbox could not be probed (port 25 blocked, greylisted, skipped)
     *
     * @return array{0: string, 1: array}
     */
    protected function freeCheck(string $email): array
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
            !$hasMx => [EmailStatus::INVALID, 0, "No MX or A record for {$domain}."],
            $isDisposable => [EmailStatus::INVALID, 10, "{$domain} is a disposable-mail domain."],
            $probe['result'] === 'undeliverable' => [EmailStatus::INVALID, 5, "Mail server {$probe['host']} rejected the mailbox (SMTP {$probe['code']})."],
            $probe['result'] === 'deliverable' && $isFree => [EmailStatus::VERIFIED, 80, "Mailbox accepted by {$probe['host']} (SMTP {$probe['code']}); free mailbox provider."],
            $probe['result'] === 'deliverable' => [EmailStatus::VERIFIED, 95, "Mailbox accepted by {$probe['host']} (SMTP {$probe['code']})."],
            $probe['result'] === 'catch_all' => [EmailStatus::CATCH_ALL, 60, "{$domain} accepts any address (catch-all): mailbox existence cannot be proven."],
            $isFree => [EmailStatus::MX_ONLY, 40, 'Free mailbox provider; mailbox not probed (' . $probe['note'] . ').'],
            default => [EmailStatus::MX_ONLY, 50, 'Domain accepts mail (MX found); mailbox not probed (' . $probe['note'] . ').'],
        };

        return [$status, [
            'score' => $score,
            'is_disposable' => $isDisposable,
            'is_free_mailbox' => $isFree,
            'has_mx_records' => $hasMx,
            'has_smtp_check' => $probed,
            'provider' => $probed ? 'smtp_check' : 'dns_check',
            'details' => $details,
            'raw_response' => ['checked_domain' => $domain, 'has_mx' => $hasMx, 'mx' => array_slice($mxHosts, 0, 3), 'smtp_probe' => $probe, 'details' => $details],
        ]];
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
     * address to detect catch-all. No message is sent (QUIT after RCPT). One domain whose MX refuses TCP proves
     * nothing about the network; it is treated as blocking port 25 (for an hour) only when the known-good canary MX
     * also refuses, or, with no canary configured, after `port25_block_threshold` distinct domains failed within an hour.
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
            $fp = $this->openSmtp($host);
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
            if ($this->recordPort25Failure($domain)) {
                return ['result' => 'blocked', 'code' => null, 'host' => null, 'note' => 'no MX accepted a connection on port 25 (network blocks outbound SMTP)'];
            }

            return ['result' => 'unreachable', 'code' => null, 'host' => null, 'note' => "no MX of {$domain} accepted a connection on port 25"];
        }
        Cache::forget(self::PORT25_FAILURES_KEY);

        return ['result' => 'unknown', 'code' => null, 'host' => null, 'note' => 'MX reachable but gave no usable answer'];
    }

    public const PORT25_FAILURES_KEY = 'smtp_probe_port25_failures';

    /** Records a refused domain; returns true when the network is now considered to block outbound port 25. */
    protected function recordPort25Failure(string $domain): bool
    {
        $canary = (string) config('leadpilot.email_verification.port25_canary', '');
        if ($canary !== '') {
            $blocked = !$this->canConnect($canary);
        } else {
            $failures = array_filter((array) Cache::get(self::PORT25_FAILURES_KEY, []), fn ($t) => $t >= now()->subHour()->getTimestamp());
            $failures[$domain] = now()->getTimestamp();
            Cache::put(self::PORT25_FAILURES_KEY, $failures, now()->addHour());
            $blocked = count($failures) >= max(1, (int) config('leadpilot.email_verification.port25_block_threshold', 3));
        }
        if ($blocked) {
            Cache::put('smtp_probe_port25_blocked', now()->toIso8601String(), now()->addHour());
            Cache::forget(self::PORT25_FAILURES_KEY);
        }

        return $blocked;
    }

    /** @return resource|false */
    protected function openSmtp(string $host)
    {
        return @stream_socket_client("tcp://{$host}:25", $errno, $errstr, 8);
    }

    protected function canConnect(string $host): bool
    {
        $fp = $this->openSmtp($host);
        if (!$fp) {
            return false;
        }
        fclose($fp);

        return true;
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
