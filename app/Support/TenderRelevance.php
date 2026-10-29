<?php

namespace App\Support;

/**
 * Is a public tender actually software / web work we can bid for? Seen live: "Overbridge Civils", "Microsoft
 * Enterprise Licensing", "Handheld 3D SLAM scanner" and "ITT for Training Providers" became top leads because a
 * loose word ("software", "digital", "portal") appeared somewhere in the notice.
 */
class TenderRelevance
{
    /** Works, goods and licence resale: never our job, whatever the CPV or wording. */
    public const NOT_OURS = '/\b(civils?|civil engineering|construction|building works|refurbishment|demolition|highways?|roads?|bridges?|overbridge|infrastructure advisory|climate[- ]resilient|scanners?|hardware|vehicles?|furniture|catering|cleaning|security guard|training providers?|licen[cs]ing agreement|enterprise agreement|licen[cs]e (renewal|resale)|microsoft (enterprise|365 licen)|uniforms?|medical|pharmac\w*|laborator\w*|fuel|waste|grounds maintenance|recruitment services|insurance)\b/i';

    /** Phrases that on their own mean software / web work. */
    public const SOFTWARE_WORK = '/\b(website|web ?site|web application|web app|web development|web portal development|software development|bespoke software|custom software|application development|app development|mobile app|digital platform development|laravel|php|crm|erp|e-?commerce|online (shop|store|booking)|case management system)\b/i';

    /** Single words that appear in almost every notice: never enough by themselves. */
    public const TOO_LOOSE = ['software', 'digital', 'portal', 'application', 'integration', 'api', 'automation', 'redesign', 'online', 'system', 'systems', 'platform', 'technology', 'it'];

    public static function isSoftwareWork(string $text, string $cpv = '', array $keywords = []): bool
    {
        if (preg_match(self::NOT_OURS, $text)) {
            return false;
        }
        if (str_starts_with($cpv, '72') || str_starts_with($cpv, '48')) {
            return true; // IT services / software packages
        }
        if (preg_match(self::SOFTWARE_WORK, $text)) {
            return true;
        }
        foreach ($keywords as $kw) {
            $kw = strtolower(trim((string) $kw));
            if ($kw !== '' && !in_array($kw, self::TOO_LOOSE, true) && preg_match('/\b' . preg_quote($kw, '/') . '\b/iu', $text)) {
                return true;
            }
        }

        return false;
    }
}
