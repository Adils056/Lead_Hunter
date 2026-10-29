<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * The Latin-script name a business uses for itself on its own website, for companies the map sources list only in
 * Urdu / Arabic script. Taken, best first, from its schema.org name, og:site_name / application-name, then a
 * <title> part — each only when it matches the site's own domain ("Royaute Luxury Hotel" on royauteluxury.com),
 * because site names and titles are often slogans. Nothing is transliterated or guessed: no match, no name.
 */
class EnglishName
{
    protected const GENERIC = '/^(home|homepage|home page|welcome|index|main|official( web)?site|website|untitled|coming soon|under construction|just another wordpress site|wordpress|default|page not found|404|login|contact us|about us)$/i';

    protected const COMMON_WORDS = '/^(health|healthcare|clinic|clinics|dental|medical|hospital|hotel|hotels|school|schools|care|services?|travel|tours|international|global|national|academy|institute|trading|real|estate|propert(y|ies)|solutions?|technolog(y|ies)|tech|systems?|consult(ing|ants?)|restaurants?|cafe|store|shop|online|official|world|group|home|homes|life|smart|best|first|city|plus|pharmacy|beauty|salon|fitness|motors?|auto|foods?|sweets|bakers?)$/';

    public static function isLatin(?string $name): bool
    {
        return (bool) preg_match('/\p{Latin}/u', (string) $name);
    }

    public static function fromHtml(string $html, ?string $host = null): ?string
    {
        $label = strtolower(preg_replace('/[^a-z0-9]/i', '', explode('.', preg_replace('/^www\./i', '', (string) $host))[0] ?? ''));

        if (strlen($label) < 3) {
            return null;
        }
        $names = array_merge(self::jsonLdNames($html), self::metaNames($html));
        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m)) {
            $names = array_merge($names, preg_split('/\s+(?:\||-|–|—|::|:|·|»)\s+/u', html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5)));
        }
        foreach ($names as $n) {
            // every source must agree with the site's own domain: a site name or title can be a slogan ("Success Spirit")
            if (($clean = self::clean($n)) && self::matchesDomain($clean, $label)) {
                return $clean;
            }
        }

        return null;
    }

    /** "Royaute Luxury Hotel" ~ royauteluxury, "Czech Institute of Physiotherapy" ~ czechcenter, "National Blood & Cancer Center" ~ nbcc. */
    public static function matchesDomain(string $name, string $label): bool
    {
        $compact = strtolower(preg_replace('/[^a-z0-9]/i', '', Str::ascii($name)));
        if (strlen($compact) >= 4 && (str_contains($compact, $label) || str_contains($label, $compact))) {
            return true;
        }
        $words = array_values(array_filter(preg_split('/[^a-z0-9]+/', strtolower(Str::ascii($name)), -1, PREG_SPLIT_NO_EMPTY),
            fn ($w) => !in_array($w, ['the', 'and', 'of', 'for', 'al', 'co', 'llc', 'ltd', 'est', 'center', 'centre', 'company', 'group'], true)));
        foreach ($words as $w) {
            // a distinctive word only: "health" in smilehealth.com says nothing about "Global Health Partners"
            if (strlen($w) >= 4 && !preg_match(self::COMMON_WORDS, $w) && str_contains($label, $w)) {
                return true;
            }
        }
        $initials = implode('', array_map(fn ($w) => $w[0], array_filter(preg_split('/[^a-z0-9]+/', strtolower(Str::ascii($name)), -1, PREG_SPLIT_NO_EMPTY),
            fn ($w) => !in_array($w, ['the', 'and', 'of', 'for'], true))));

        return strlen($initials) >= 3 && str_starts_with($label, $initials);
    }

    /** A usable business name, or null: mostly Latin letters, 2-60 chars, at most 8 words, not "Home" / a domain. */
    public static function clean(?string $name): ?string
    {
        $n = html_entity_decode(strip_tags((string) $name), ENT_QUOTES | ENT_HTML5);
        if (preg_match('#[a-z][a-z0-9+.-]*://|^www\.#i', trim($n))) {
            return null; // a link where the name should be ("https:// /")
        }
        $n = preg_replace('/\b[a-z0-9-]+(?:\.[a-z0-9-]+)*\.[a-z]{2,}\b/i', ' ', $n); // a domain is not a name
        $n = trim(preg_replace('/\s+/u', ' ', $n), " \t\n\r\0\x0B|-–—:·»,");
        if ($n === '' || mb_strlen($n) < 2 || mb_strlen($n) > 60 || count(explode(' ', $n)) > 8 || preg_match(self::GENERIC, $n)) {
            return null;
        }
        preg_match_all('/\p{L}/u', $n, $letters);
        preg_match_all('/\p{Latin}/u', $n, $latin);
        if (count($letters[0]) < 2 || count($latin[0]) / count($letters[0]) < 0.8) {
            return null; // still mostly another script
        }

        return $n;
    }

    /** "name" of the first organisation-like schema.org node (Organization, LocalBusiness, Hotel, WebSite ...). */
    protected static function jsonLdNames(string $html): array
    {
        if (!preg_match_all('#<script[^>]+application/ld\+json[^>]*>(.*?)</script>#is', $html, $m)) {
            return [];
        }
        $out = [];
        $walk = function ($node) use (&$walk, &$out) {
            if (!is_array($node)) {
                return;
            }
            $type = implode(' ', array_map('strval', (array) ($node['@type'] ?? [])));
            if ($type !== '' && !preg_match('/Person|WebPage|BreadcrumbList|ImageObject|Article|Product|Offer|Review|Event|FAQ|Question|SearchAction/i', $type)
                && is_string($node['name'] ?? null)) {
                // the business itself before the site: an Organization / LocalBusiness name beats a WebSite name
                preg_match('/WebSite/i', $type) ? $out[] = $node['name'] : array_unshift($out, $node['name']);
            }
            foreach ($node as $v) {
                if (is_array($v)) {
                    $walk($v);
                }
            }
        };
        foreach ($m[1] as $json) {
            $walk(json_decode(trim($json), true));
        }

        return $out;
    }

    protected static function metaNames(string $html): array
    {
        $out = [];
        foreach (['og:site_name', 'application-name', 'apple-mobile-web-app-title'] as $key) {
            if (preg_match('/<meta[^>]+(?:property|name)=["\']' . preg_quote($key, '/') . '["\'][^>]+content=["\']([^"\']+)/i', $html, $mm)
                || preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+(?:property|name)=["\']' . preg_quote($key, '/') . '["\']/i', $html, $mm)) {
                $out[] = $mm[1];
            }
        }

        return $out;
    }
}
