<?php

namespace App\Support;

/**
 * The name to write in an e-mail. Directory names carry noise that reads as spam in a greeting or subject (seen
 * live): a URL ("Snout https://snout.com/"), a legal suffix and tagline ("Simmons and Fletcher, P.C., Injury &
 * Accident Lawyers"), or a name in Urdu/Arabic script in an English e-mail. Null when nothing writable is left:
 * callers then greet without a name.
 */
class CompanyName
{
    public const LEGAL_SUFFIX = '/^(.*?)[,\s]+(P\.?\s?C\.?|P\.?L\.?L\.?C\.?|L\.?L\.?C\.?|L\.?L\.?P\.?|Ltd\.?|Limited|Inc\.?|Incorporated|Corp\.?|Corporation|GmbH|(?:Pvt\.?|Private)\s+(?:Ltd\.?|Limited))(?=[\s,.)]|$)/iu';

    public static function forEmail(?string $name): ?string
    {
        $n = preg_replace('#\bhttps?://\S+|\bwww\.\S+#i', '', (string) $name);
        $n = preg_replace('/\s+/u', ' ', trim((string) $n));
        if (preg_match(self::LEGAL_SUFFIX, $n, $m) && trim($m[1]) !== '') {
            $n = $m[1]; // "Simmons and Fletcher, P.C., Injury & ..." -> "Simmons and Fletcher"
        }
        $n = preg_split('/\s+[|–—:]\s+|\s+-\s+/u', $n)[0]; // drop a "— tagline" / "| tagline"
        $n = trim($n, " \t,.;:-–—|");
        if ($n === '' || !preg_match('/\p{Latin}/u', $n)) {
            return null; // non-Latin script (or nothing left) in an English e-mail: greet without a name
        }
        if (mb_strlen($n) > 40) {
            $n = rtrim(mb_substr($n, 0, 40));
            $n = mb_strpos($n, ' ') !== false ? mb_substr($n, 0, mb_strrpos($n, ' ')) : $n;
        }

        return $n;
    }
}
