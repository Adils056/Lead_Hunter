<?php

namespace App\Support;

/**
 * The country a buyer is really in, from its published address, not from the portal that listed the tender
 * (SQA bug 24: Chemonics in Kyiv was stored as "United Kingdom" because the notice was on a UK portal).
 */
class Country
{
    /** Spellings in published addresses => the country name used everywhere else in the app. */
    public const ALIASES = [
        'uk' => 'United Kingdom', 'gb' => 'United Kingdom', 'gbr' => 'United Kingdom', 'great britain' => 'United Kingdom', 'england' => 'United Kingdom',
        'scotland' => 'United Kingdom', 'wales' => 'United Kingdom', 'northern ireland' => 'United Kingdom', 'united kingdom' => 'United Kingdom',
        'us' => 'United States', 'usa' => 'United States', 'united states of america' => 'United States', 'united states' => 'United States',
        'uae' => 'United Arab Emirates', 'ae' => 'United Arab Emirates', 'ksa' => 'Saudi Arabia', 'sa' => 'Saudi Arabia', 'pk' => 'Pakistan',
        'ua' => 'Ukraine', 'ie' => 'Ireland', 'de' => 'Germany', 'fr' => 'France', 'nl' => 'Netherlands', 'be' => 'Belgium', 'au' => 'Australia', 'ca' => 'Canada',
    ];

    /** Cities that are clearly outside the portal's country, for notices that leave countryName empty. */
    public const CITIES = [
        'kyiv' => 'Ukraine', 'kiev' => 'Ukraine', 'lviv' => 'Ukraine', 'kharkiv' => 'Ukraine', 'odesa' => 'Ukraine', 'odessa' => 'Ukraine',
        'dublin' => 'Ireland', 'paris' => 'France', 'brussels' => 'Belgium', 'amsterdam' => 'Netherlands', 'berlin' => 'Germany', 'geneva' => 'Switzerland',
        'washington dc' => 'United States', 'nairobi' => 'Kenya', 'kampala' => 'Uganda',
        'lagos' => 'Nigeria', 'abuja' => 'Nigeria', 'accra' => 'Ghana', 'addis ababa' => 'Ethiopia', 'islamabad' => 'Pakistan', 'karachi' => 'Pakistan',
        'lahore' => 'Pakistan', 'dubai' => 'United Arab Emirates', 'abu dhabi' => 'United Arab Emirates', 'riyadh' => 'Saudi Arabia', 'doha' => 'Qatar',
        'kabul' => 'Afghanistan', 'dhaka' => 'Bangladesh', 'new delhi' => 'India', 'delhi' => 'India', 'manila' => 'Philippines', 'jakarta' => 'Indonesia',
        'tbilisi' => 'Georgia', 'chisinau' => 'Moldova', 'warsaw' => 'Poland', 'bucharest' => 'Romania', 'amman' => 'Jordan', 'beirut' => 'Lebanon', 'cairo' => 'Egypt',
    ];

    public static function normalize(?string $name): ?string
    {
        $key = strtolower(trim((string) $name, " .\t\n"));
        if ($key === '') {
            return null;
        }

        return self::ALIASES[$key] ?? ucwords($key);
    }

    /** @param array $address OCDS address: countryName, locality, region … */
    public static function fromAddress(array $address, string $fallback): string
    {
        if ($c = self::normalize($address['countryName'] ?? null)) {
            return $c;
        }

        return self::fromCity($address['locality'] ?? null) ?? $fallback;
    }

    /** The country a free-text post names ("US-based startup", "we are in Dubai"); null when it names none. */
    public static function fromText(string $text): ?string
    {
        if (preg_match('/\b(USA|US|UK|UAE|KSA)\b/', $text, $m)) { // upper case only: "us" is a pronoun
            return self::ALIASES[strtolower($m[1])];
        }
        $lower = strtolower($text);
        foreach (array_unique(array_values(self::ALIASES)) as $country) {
            if (preg_match('/\b' . preg_quote(strtolower($country), '/') . '\b/', $lower)) {
                return $country;
            }
        }
        foreach (self::CITIES + ['london' => 'United Kingdom', 'manchester' => 'United Kingdom', 'new york' => 'United States', 'san francisco' => 'United States',
            'toronto' => 'Canada', 'sydney' => 'Australia', 'melbourne' => 'Australia'] as $city => $country) {
            if (preg_match('/\b' . preg_quote($city, '/') . '\b/', $lower)) {
                return $country;
            }
        }

        return null;
    }

    public static function fromCity(?string $city): ?string
    {
        return self::CITIES[strtolower(trim((string) $city))] ?? null;
    }
}
