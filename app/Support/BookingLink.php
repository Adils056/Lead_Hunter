<?php

namespace App\Support;

use App\Models\Lead;
use App\Models\OutreachSetting;

/**
 * The meeting booking link (Outreach Engine setting, else LEADPILOT_BOOKING_URL). For a lead, the link carries
 * utm_content=lp{lead id}: Calendly keeps UTM values on the booking, so a booking made with a personal Gmail address
 * from a WhatsApp chat is still matched to the right lead.
 */
class BookingLink
{
    public static function base(): ?string
    {
        $url = trim((string) (OutreachSetting::current()->booking_url ?: config('leadpilot.agency.booking_url')));

        return $url !== '' ? $url : null;
    }

    public static function for(?Lead $lead, string $medium = 'whatsapp'): ?string
    {
        $url = self::base();
        if (!$url || !$lead?->id) {
            return $url;
        }

        return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query(['utm_source' => 'leadpilot', 'utm_medium' => $medium, 'utm_content' => 'lp' . $lead->id]);
    }

    /** The lead id carried by a booking's utm_content, or null. */
    public static function leadId(?string $utmContent): ?int
    {
        return preg_match('/^lp(\d{1,10})$/', trim((string) $utmContent), $m) ? (int) $m[1] : null;
    }
}
