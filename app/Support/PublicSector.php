<?php

namespace App\Support;

/**
 * Government, state-school, university and health-service bodies buy through procurement, not cold e-mail:
 * an agency pitch to them is noise at best and a complaint at worst. Recognised from the e-mail or website domain.
 */
class PublicSector
{
    public const DOMAIN_PATTERNS = [
        '/(^|\.)(gov|mil)(\.[a-z]{2})?$/',     // gov.uk, gov.ae, gov.sa, gov.pk, gov.au, .gov, .mil
        '/(^|\.)edu(\.[a-z]{2})?$/',           // det.nsw.edu.au, edu.pk, .edu
        '/(^|\.)(sch|ac|nhs|police|mod)\.uk$/', // UK state schools, universities, NHS, police, MoD
        '/(^|\.)nhs\.net$/',
        '/(^|\.)ac\.[a-z]{2}$/',               // universities elsewhere (ac.ae, ac.pk, ...)
    ];

    public static function domain(?string $emailOrUrl): ?string
    {
        $v = strtolower(trim((string) $emailOrUrl));
        if ($v === '') {
            return null;
        }
        if (str_contains($v, '@')) {
            return substr(strrchr($v, '@'), 1) ?: null;
        }

        return parse_url(str_contains($v, '://') ? $v : "https://{$v}", PHP_URL_HOST) ?: null;
    }

    public static function matches(?string ...$emailsOrUrls): bool
    {
        foreach ($emailsOrUrls as $v) {
            $host = self::domain($v);
            if ($host === null) {
                continue;
            }
            foreach (self::DOMAIN_PATTERNS as $p) {
                if (preg_match($p, $host)) {
                    return true;
                }
            }
        }

        return false;
    }
}
