<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Company extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'website',
        'domain',
        'industry',
        'country',
        'city',
        'size_range',
        'description',
        'public_email',
        'public_phone',
        'public_address',
        'tech_stack',
        'source_url',
        'source_provider',
        'ats_board',
        'ats_checked_at',
        'discovery_date',
        'last_verified_at',
        'status',
        'notes',
        'assigned_user_id',
        'ai_research',
        'ai_researched_at',
        'external_id',
        'google_rating',
        'google_review_count',
        'business_status',
        'website_opportunity_score',
        'website_audited_at',
    ];

    protected function casts(): array
    {
        return [
            'tech_stack' => 'array',
            'ai_research' => 'array',
            'discovery_date' => 'datetime',
            'last_verified_at' => 'datetime',
            'ai_researched_at' => 'datetime',
            'website_audited_at' => 'datetime',
            'ats_checked_at' => 'datetime',
            'google_rating' => 'float',
        ];
    }

    public static function extractDomain(?string $url): ?string
    {
        if (empty($url)) {
            return null;
        }

        // Add scheme if missing so parse_url functions reliably
        if (!preg_match('#^https?://#i', $url)) {
            $url = 'https://' . $url;
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (!$host) {
            return null;
        }

        $host = strtolower(trim($host));
        return preg_replace('/^www\./', '', $host);
    }

    public static function normalizeDomain(?string $url): ?string
    {
        return static::extractDomain($url);
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    public function buyingSignals(): HasMany
    {
        return $this->hasMany(BuyingSignal::class);
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function deals(): HasMany
    {
        return $this->hasMany(Deal::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function websiteAudits(): HasMany
    {
        return $this->hasMany(WebsiteAudit::class);
    }

    public function latestWebsiteAudit(): HasOne
    {
        return $this->hasOne(WebsiteAudit::class)->latestOfMany('audited_at');
    }

    public function provenance(): MorphMany
    {
        return $this->morphMany(DataProvenance::class, 'subject');
    }

    /** Company domain from an email, unless it is a free-mail provider. */
    public static function domainFromEmail(?string $email): ?string
    {
        if (!$email || !str_contains($email, '@')) {
            return null;
        }
        $domain = strtolower(trim(substr(strrchr($email, '@'), 1)));

        return in_array($domain, config('leadpilot.free_mail_domains', []), true) ? null : $domain;
    }

    public function enrichmentRecords(): MorphMany
    {
        return $this->morphMany(EnrichmentRecord::class, 'enrichable');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', '!=', 'archived');
    }

    public function scopeByCountry(Builder $query, ?string $country): Builder
    {
        return $country ? $query->where('country', $country) : $query;
    }

    public function scopeByIndustry(Builder $query, ?string $industry): Builder
    {
        return $industry ? $query->where('industry', $industry) : $query;
    }
}
