<?php

namespace App\Support;

use App\Models\OutreachSetting;

/** One list of the services Eziline sells: slug => label. Settings, campaigns and templates all read it. */
class ServiceCatalog
{
    /** Old keys that mean the same service as a catalog key. */
    public const ALIASES = ['maintenance_support' => 'maintenance'];

    /** Services sold but not tied to an audit trigger in config('leadpilot.services'). */
    protected const EXTRA = ['database_optimization' => 'Database Design & Optimization'];

    public static function all(): array
    {
        return collect(config('leadpilot.services'))->map(fn ($d) => $d['label'])->all() + self::EXTRA;
    }

    public static function normalize(?string $slug): ?string
    {
        return $slug === null ? null : (self::ALIASES[$slug] ?? $slug);
    }

    public static function label(?string $slug): string
    {
        $slug = self::normalize($slug);

        return self::all()[$slug] ?? (string) $slug;
    }

    /** slug => label for the services ticked in Settings → Outreach Engine (all of them when none are ticked). */
    public static function approved(): array
    {
        $approved = array_unique(array_map([self::class, 'normalize'], OutreachSetting::current()->approved_services ?: []));

        return $approved ? array_intersect_key(self::all(), array_flip($approved)) : self::all();
    }
}
