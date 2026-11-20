<?php

namespace App\Support;

use App\Models\Company;
use App\Models\Lead;

/**
 * "Chat with us on WhatsApp": a wa.me link to the team's WhatsApp number with a message already written, put in every
 * e-mail and website report. The prospect writes first, so nothing is sent to someone who did not ask; that is what keeps
 * the number from being banned (seen 20 Nov: the number used for cold WhatsApp messages was banned).
 * The message carries a short reference (LP + lead id, or LC + company id) so the conversation joins the right lead.
 */
class WhatsappChatLink
{
    public static function number(): ?string
    {
        $n = preg_replace('/\D/', '', (string) config('leadpilot.whatsapp.chat_number'));

        return strlen($n) >= 8 ? $n : null;
    }

    public static function forLead(?Lead $lead): ?string
    {
        if (!$lead?->id || !($n = self::number())) {
            return null;
        }
        $company = $lead->company?->name ?: 'my business';

        return self::url($n, 'Hi ' . self::sender() . ", I got your e-mail about the {$company} website. (ref LP{$lead->id})");
    }

    public static function forCompany(?Company $company): ?string
    {
        if (!$company?->id || !($n = self::number())) {
            return null;
        }

        return self::url($n, 'Hi ' . self::sender() . ", I saw the website report for {$company->name}. (ref LC{$company->id})");
    }

    /** The lead or company a message refers to, from its reference: ['lead', 12] / ['company', 7], or null. */
    public static function ref(?string $text): ?array
    {
        return preg_match('/\bL([PC])(\d{1,10})\b/', (string) $text, $m) ? [$m[1] === 'P' ? 'lead' : 'company', (int) $m[2]] : null;
    }

    protected static function url(string $number, string $text): string
    {
        return "https://wa.me/{$number}?text=" . rawurlencode($text);
    }

    protected static function sender(): string
    {
        return trim((string) config('leadpilot.agency.sender_name')) ?: 'there';
    }
}
