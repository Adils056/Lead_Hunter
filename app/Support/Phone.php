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
}
