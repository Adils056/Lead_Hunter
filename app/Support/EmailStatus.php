<?php

namespace App\Support;

use App\Models\OutreachSetting;

/**
 * The ONE e-mail verification vocabulary. Every writer (free probe, Hunter, bounces) stores one of these values and
 * every reader (eligibility gate, win ladder, scoring, campaigns, dashboards, views) asks isSendable() / label().
 *
 *  - verified   mailbox accepted by an SMTP probe or confirmed by Hunter (real proof)
 *  - catch_all  the domain accepts any address: deliverable, mailbox existence unprovable
 *  - risky      a provider flagged it risky for another reason
 *  - mx_only    only the domain's MX was found; the mailbox was never probed (port 25 blocked, greylisted, skipped)
 *  - unknown    the provider could not decide
 *  - invalid    no MX, disposable, rejected by the MX, or bounced
 *  - unverified not checked yet
 */
class EmailStatus
{
    public const VERIFIED = 'verified';
    public const CATCH_ALL = 'catch_all';
    public const RISKY = 'risky';
    public const MX_ONLY = 'mx_only';
    public const UNKNOWN = 'unknown';
    public const INVALID = 'invalid';
    public const UNVERIFIED = 'unverified';

    public const ALL = [self::VERIFIED, self::CATCH_ALL, self::RISKY, self::MX_ONLY, self::UNKNOWN, self::INVALID, self::UNVERIFIED];

    /** Statuses backed by a mailbox-level check (SMTP probe or Hunter). */
    public const PROVEN = [self::VERIFIED, self::CATCH_ALL, self::RISKY, self::INVALID];

    /**
     * Statuses outreach may go to. catch_all/risky stay sendable (as the gate always allowed); mx_only only when the
     * owner switched "allow MX-only" on in Settings -> Outreach Engine (default off).
     */
    public static function sendableStatuses(?OutreachSetting $settings = null): array
    {
        $settings ??= OutreachSetting::current();
        $statuses = [self::VERIFIED, self::CATCH_ALL, self::RISKY];
        if ($settings->allow_mx_only) {
            $statuses[] = self::MX_ONLY;
        }

        return $statuses;
    }

    public static function isSendable(?string $status, ?OutreachSetting $settings = null): bool
    {
        return in_array($status, self::sendableStatuses($settings), true);
    }

    public static function label(?string $status): string
    {
        return match ($status) {
            self::VERIFIED => 'Verified',
            self::CATCH_ALL => 'Catch-all',
            self::RISKY => 'Risky',
            self::MX_ONLY => 'MX only',
            self::UNKNOWN => 'Unknown',
            self::INVALID => 'Invalid',
            default => 'Unverified',
        };
    }

    public static function badgeClass(?string $status): string
    {
        return match ($status) {
            self::VERIFIED => 'bg-success-subtle text-success',
            self::CATCH_ALL, self::RISKY => 'bg-warning-subtle text-warning',
            self::MX_ONLY => 'bg-info-subtle text-info',
            self::INVALID => 'bg-danger-subtle text-danger',
            default => 'bg-secondary-subtle text-secondary',
        };
    }

    public static function icon(?string $status): string
    {
        return match ($status) {
            self::VERIFIED => 'bi-check-circle-fill',
            self::CATCH_ALL, self::RISKY => 'bi-exclamation-triangle',
            self::MX_ONLY => 'bi-hourglass-split',
            self::INVALID => 'bi-x-circle',
            default => 'bi-question-circle',
        };
    }
}
