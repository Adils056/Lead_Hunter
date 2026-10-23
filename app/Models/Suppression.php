<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Suppression extends Model
{
    use HasFactory;

    protected $fillable = [
        'email',
        'domain',
        'reason',
        'source',
        'notes',
        'added_by',
    ];

    public function adder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by');
    }

    public static function isSuppressed(?string $email, ?string $domain = null): bool
    {
        if (!empty($email)) {
            $email = strtolower(trim($email));
            if (self::where('email', $email)->exists()) {
                return true;
            }
        }

        if (!empty($domain)) {
            $domain = strtolower(trim($domain));
            // only explicit DOMAIN-LEVEL blocks (email IS NULL) suppress a whole domain; one person
            // unsubscribing must not block every gmail.com address or their whole company (v4 fix)
            if (self::whereNull('email')->where('domain', $domain)->exists()) {
                return true;
            }
        }

        return false;
    }

    public static function addSuppression(string $email, string $reason = 'unsubscribe', ?string $source = 'system', ?int $userId = null): self
    {
        $domain = substr(strrchr($email, "@"), 1);

        return self::firstOrCreate(
            ['email' => strtolower(trim($email))],
            [
                'domain' => $domain ? strtolower(trim($domain)) : null,
                'reason' => $reason,
                'source' => $source,
                'added_by' => $userId,
            ]
        );
    }

    /** Explicit domain-wide block (admin decision, e.g. "never contact acme.com"). */
    public static function addDomainSuppression(string $domain, string $reason = 'domain_block', ?string $source = 'admin', ?int $userId = null): self
    {
        return self::firstOrCreate(['email' => null, 'domain' => strtolower(trim($domain))], ['reason' => $reason, 'source' => $source, 'added_by' => $userId]);
    }

    protected static function booted(): void
    {
        // keep pipeline_state truthful when an address is suppressed
        $refresh = function (Suppression $s) {
            if ($s->email) {
                Lead::where('email', $s->email)->get()->each(fn ($l) => app(\App\Services\Autopilot\LeadStateService::class)->refresh($l));
            }
        };
        static::created($refresh);
        static::deleted($refresh);
    }
}
