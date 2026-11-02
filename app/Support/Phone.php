<?php

namespace App\Support;

/** Phone numbers in the international digits-only form WhatsApp links need (923001234567). */
class Phone
{
    public const CALLING_CODES = [
        'Pakistan' => '92', 'United Kingdom' => '44', 'United States' => '1', 'Canada' => '1', 'United Arab Emirates' => '971',
        'Saudi Arabia' => '966', 'Australia' => '61', 'Germany' => '49', 'Qatar' => '974', 'Oman' => '968', 'Kuwait' => '965', 'Bahrain' => '973',
        'Ireland' => '353', 'New Zealand' => '64', 'India' => '91',
    ];

    public static function international(?string $phone, ?string $country): ?string
    {
        $raw = trim((string) $phone);
        if ($raw === '') {
            return null;
        }
        $digits = preg_replace('/\D/', '', $raw);
        if (str_starts_with($raw, '+')) {
            return strlen($digits) >= 8 ? $digits : null;
        }
        if (str_starts_with($digits, '00')) {
            return strlen($digits) >= 10 ? substr($digits, 2) : null;
        }
        $code = self::CALLING_CODES[$country ?? ''] ?? null;
        if (!$code) {
            return null; // unknown country: a guessed code would message a stranger
        }
        if (str_starts_with($digits, $code) && strlen($digits) >= strlen($code) + 8) {
            return $digits; // already international without the +
        }

        return strlen(ltrim($digits, '0')) >= 7 ? $code . ltrim($digits, '0') : null;
    }

    /** Mobile prefixes (after the country code) where a number on WhatsApp is likely; anything else there is a landline. */
    protected const MOBILE_PREFIX = ['92' => '3', '971' => '5', '966' => '5', '974' => ['3', '5', '6', '7'], '44' => '7', '61' => '4', '353' => '8', '64' => '2'];

    /** Countries where businesses mostly list office lines and rarely use WhatsApp for customers. */
    protected const EMAIL_FIRST = ['United States', 'Canada', 'United Kingdom', 'Australia', 'Ireland', 'New Zealand', 'Germany'];

    /**
     * A warning to show before opening WhatsApp, or null when the number looks like a mobile in a WhatsApp market.
     * Landline: a number that cannot be on WhatsApp. US / Canada numbers do not show mobile vs landline at all.
     */
    public static function whatsappHint(?string $international, ?string $country): ?string
    {
        if (!$international) {
            return null;
        }
        foreach (self::MOBILE_PREFIX as $code => $mobile) {
            if (str_starts_with($international, (string) $code)) {
                $rest = substr($international, strlen((string) $code));
                $isMobile = collect((array) $mobile)->contains(fn ($p) => str_starts_with($rest, (string) $p));
                if (!$isMobile) {
                    return 'This looks like a landline, which cannot be on WhatsApp. Email or call instead.';
                }

                return in_array($country, self::EMAIL_FIRST, true)
                    ? 'A mobile number, but businesses in ' . $country . ' rarely answer on WhatsApp. Email usually works better.'
                    : null;
            }
        }

        return in_array($country, self::EMAIL_FIRST, true) || str_starts_with($international, '1')
            ? 'Businesses in ' . ($country ?: 'this country') . ' usually list an office landline that is not on WhatsApp. Email usually works better.'
            : null;
    }
}
