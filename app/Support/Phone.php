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

    /** Digits in a full international number (with its calling code). Seen live: 97180021776789 was refused. */
    public const LENGTHS = ['92' => [11, 12], '971' => [11, 12], '966' => [12], '1' => [11], '44' => [11, 12], '974' => [11], '968' => [11], '965' => [11],
        '973' => [11], '91' => [12], '61' => [11], '49' => [11, 12, 13, 14], '353' => [11, 12], '64' => [10, 11, 12]];

    /** The known calling code an international number starts with, longest first, or null. */
    public static function codeOf(string $international): ?string
    {
        foreach ([3, 2, 1] as $len) {
            $code = substr($international, 0, $len);
            if (isset(self::LENGTHS[$code])) {
                return $code;
            }
        }

        return null;
    }

    /** Whether an international number has the right length for its country (8-15 digits when the code is unknown). */
    public static function plausible(?string $international): bool
    {
        $n = (string) $international;
        if (!preg_match('/^[1-9]\d{7,14}$/', $n)) {
            return false;
        }
        $code = self::codeOf($n);

        return !$code || in_array(strlen($n), self::LENGTHS[$code], true);
    }

    /**
     * The number in a business's own wa.me link, made international. Websites often link a local number (wa.me/0300…,
     * wa.me/5168002238 without the US 1), which the gateway refuses. Seen live: 5 of 13 failed WhatsApps were such
     * numbers. Null when it cannot be made into a valid number for that country.
     */
    public static function fromWhatsappLink(string $digits, ?string $country): ?string
    {
        $d = preg_replace('/\D/', '', $digits);
        if (str_starts_with($d, '00')) {
            $d = substr($d, 2);
        } elseif (!str_starts_with($d, '0') && self::codeOf($d) && self::plausible($d)) {
            return $d; // already international
        }
        $n = self::international($d, $country);

        return self::plausible($n) ? $n : null;
    }

    /** False when the business's country is known and the number is from another country (a typo or a foreign line). */
    public static function inCountry(string $international, ?string $country): bool
    {
        $code = self::CALLING_CODES[$country ?? ''] ?? null;

        return !$code || str_starts_with($international, $code);
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
