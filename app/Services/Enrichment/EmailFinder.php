<?php

namespace App\Services\Enrichment;

use App\Services\EmailVerificationService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * The address of a decision-maker the company names on its own site (owner's choice A, 29 Oct 2026): common
 * patterns are tried against the company's own mail server and one is kept ONLY when the server accepts that
 * mailbox and rejects a random one. A catch-all domain proves nothing, so nothing is kept. No mail is sent.
 */
class EmailFinder
{
    /** Most common business patterns first. */
    public const PATTERNS = ['{f}', '{f}.{l}', '{fi}{l}', '{f}{l}', '{fi}.{l}', '{f}_{l}', '{f}{li}', '{l}'];

    public function __construct(protected EmailVerificationService $verifier)
    {
    }

    /**
     * @return array{email: ?string, outcome: string, tried: list<string>}
     *         outcome: found | catch_all | no_mx | free_mail | cannot_probe | not_found | bad_name
     */
    public function find(string $first, string $last, string $domain): array
    {
        $domain = strtolower(trim($domain));
        $candidates = $this->candidates($first, $last, $domain);
        if (!$candidates) {
            return ['email' => null, 'outcome' => 'bad_name', 'tried' => []];
        }
        if ($domain === '' || in_array($domain, (array) config('leadpilot.free_mail_domains', []), true)) {
            return ['email' => null, 'outcome' => 'free_mail', 'tried' => []];
        }
        if (Cache::has("email_finder:catch_all:{$domain}")) {
            return ['email' => null, 'outcome' => 'catch_all', 'tried' => []];
        }
        $mx = $this->verifier->mxHosts($domain);
        if (!$mx) {
            return ['email' => null, 'outcome' => 'no_mx', 'tried' => []];
        }

        $tried = [];
        foreach (array_slice($candidates, 0, (int) config('leadpilot.email_finder.max_attempts', 6)) as $email) {
            $tried[] = $email;
            $probe = $this->verifier->smtpProbe($email, $mx);
            switch ($probe['result']) {
                case 'deliverable':
                    return ['email' => $email, 'outcome' => 'found', 'tried' => $tried];
                case 'catch_all':
                    Cache::put("email_finder:catch_all:{$domain}", true, now()->addDays(30));

                    return ['email' => null, 'outcome' => 'catch_all', 'tried' => $tried];
                case 'undeliverable':
                    continue 2;
                default: // blocked / unreachable / greylisted: no proof either way, stop asking this server
                    return ['email' => null, 'outcome' => 'cannot_probe', 'tried' => $tried];
            }
        }

        return ['email' => null, 'outcome' => 'not_found', 'tried' => $tried];
    }

    /** @return list<string> */
    public function candidates(string $first, string $last, string $domain): array
    {
        $f = preg_replace('/[^a-z]/', '', strtolower(Str::ascii($first)));
        $l = preg_replace('/[^a-z]/', '', strtolower(Str::ascii($last)));
        if (strlen($f) < 2 || strlen($l) < 2 || $domain === '') {
            return [];
        }
        $vars = ['{fi}' => $f[0], '{li}' => $l[0], '{f}' => $f, '{l}' => $l];

        return array_values(array_unique(array_map(fn ($p) => strtr($p, $vars) . '@' . $domain, self::PATTERNS)));
    }
}
