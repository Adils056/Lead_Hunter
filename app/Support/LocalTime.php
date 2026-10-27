<?php

namespace App\Support;

use App\Models\Lead;

/**
 * The prospect's own clock: which time zone and which working days apply to a lead, from its country (and, for the
 * countries that span several zones, its city or state). Unknown countries fall back to the owner's time zone.
 */
class LocalTime
{
    public const COUNTRY_ZONES = [
        'Pakistan' => 'Asia/Karachi',
        'United States' => 'America/New_York',
        'United Kingdom' => 'Europe/London',
        'United Arab Emirates' => 'Asia/Dubai',
        'Saudi Arabia' => 'Asia/Riyadh',
        'Australia' => 'Australia/Sydney',
        'Canada' => 'America/Toronto',
        'Germany' => 'Europe/Berlin',
        'India' => 'Asia/Kolkata',
        'Qatar' => 'Asia/Qatar',
        'Ireland' => 'Europe/Dublin',
        'New Zealand' => 'Pacific/Auckland',
    ];

    /** City / state words -> zone, for countries with more than one zone. */
    public const CITY_ZONES = [
        'United States' => [
            'America/Los_Angeles' => '/\b(california|CA|los angeles|san francisco|san diego|san jose|oakland|palo alto|mountain view|sacramento|seattle|washington state|WA|portland|oregon|OR|las vegas|nevada|NV)\b/i',
            'America/Denver' => '/\b(denver|colorado|CO|salt lake|utah|UT|boise|idaho|albuquerque|new mexico|montana)\b/i',
            'America/Phoenix' => '/\b(phoenix|arizona|AZ|tucson|scottsdale)\b/i',
            'America/Chicago' => '/\b(chicago|illinois|IL|texas|TX|austin|dallas|houston|san antonio|minneapolis|minnesota|st\.? louis|missouri|kansas|nashville|tennessee|new orleans|louisiana|milwaukee|wisconsin|oklahoma|iowa|nebraska|alabama|mississippi|arkansas)\b/i',
            'Pacific/Honolulu' => '/\b(hawaii|honolulu)\b/i',
            'America/Anchorage' => '/\b(alaska|anchorage)\b/i',
        ],
        'Australia' => [
            'Australia/Perth' => '/\b(perth|western australia|WA)\b/i',
            'Australia/Adelaide' => '/\b(adelaide|south australia|SA)\b/i',
            'Australia/Brisbane' => '/\b(brisbane|queensland|QLD|gold coast)\b/i',
            'Australia/Darwin' => '/\b(darwin|northern territory|NT)\b/i',
        ],
        'Canada' => [
            'America/Vancouver' => '/\b(vancouver|british columbia|BC|victoria)\b/i',
            'America/Edmonton' => '/\b(calgary|edmonton|alberta|AB)\b/i',
            'America/Halifax' => '/\b(halifax|nova scotia)\b/i',
        ],
    ];

    /** ISO weekdays (1 = Monday) that are working days. */
    public const WORKDAYS = [
        'Saudi Arabia' => [7, 1, 2, 3, 4], // Sunday-Thursday
        'Qatar' => [7, 1, 2, 3, 4],
    ];

    public static function zone(?string $country, ?string $place = null): string
    {
        $country = trim((string) $country);
        foreach (self::CITY_ZONES[$country] ?? [] as $zone => $re) {
            if ($place && preg_match($re, $place)) {
                return $zone;
            }
        }

        return self::COUNTRY_ZONES[$country] ?? (string) config('leadpilot.timezone', 'Asia/Karachi');
    }

    public static function workdays(?string $country): array
    {
        return self::WORKDAYS[trim((string) $country)] ?? (array) config('leadpilot.autopilot.sending_days', [1, 2, 3, 4, 5]);
    }

    /** @return array{0: string, 1: int[]} time zone and working days for this lead */
    public static function forLead(?Lead $lead): array
    {
        if (!$lead) {
            return [(string) config('leadpilot.timezone', 'Asia/Karachi'), (array) config('leadpilot.autopilot.sending_days', [1, 2, 3, 4, 5])];
        }
        $company = $lead->company;
        $country = $lead->country ?: $company?->country;
        $place = trim(implode(' ', array_filter([$company?->city, $company?->public_address])));
        if ($place === '' && $company && isset(self::CITY_ZONES[$country])) {
            // hiring posts carry the location ("Austin, TX | ONSITE") on the signal, not on the company
            $place = (string) $company->buyingSignals()->latest('id')->get()->map(fn ($s) => $s->metadata['location'] ?? null)->filter()->first();
        }

        return [self::zone($country, $place ?: null), self::workdays($country)];
    }
}
