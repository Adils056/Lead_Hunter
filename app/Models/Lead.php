<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Lead extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'pipeline_state',
        'pipeline_state_at',
        'outreach_eligible_at',
        'company_id',
        'icp_profile_id',
        'contact_status',
        'first_name',
        'last_name',
        'full_name',
        'job_title',
        'email',
        'email_verification_status',
        'phone',
        'profile_url',
        'lead_source_id',
        'source_name',
        'source_url',
        'discovery_date',
        'last_verified_at',
        'country',
        'industry',
        'relevant_services',
        'lifecycle_stage',
        'intent_score',
        'service_fit_score',
        'contact_confidence_score',
        'overall_score',
        'score_explanation',
        'score_breakdown',
        'scored_at',
        'assigned_user_id',
        'notes',
        'last_contacted_at',
        'next_action_date',
        'next_action_note',
        'win_tier',
        'win_points',
        'win_reasons',
        'win_blockers',
        'win_next_action',
        'win_computed_at',
    ];

    protected $attributes = [
        'lifecycle_stage' => 'new',
        'email_verification_status' => 'unverified',
        'intent_score' => 0,
        'service_fit_score' => 0,
        'contact_confidence_score' => 0,
        'overall_score' => 0,
    ];

    protected static function booted(): void
    {
        static::saving(function (Lead $lead) {
            app(\App\Services\Autopilot\LeadStateService::class)->sync($lead);
            if (empty($lead->full_name)) {
                $lead->full_name = trim(($lead->first_name ?? '') . ' ' . ($lead->last_name ?? '')) ?: 'Business Contact';
            }
        });
    }

    protected function casts(): array

    {
        return [
            'relevant_services' => 'array',
            'score_breakdown' => 'array',
            'discovery_date' => 'datetime',
            'last_verified_at' => 'datetime',
            'scored_at' => 'datetime',
            'last_contacted_at' => 'datetime',
            'next_action_date' => 'date',
            'intent_score' => 'integer',
            'service_fit_score' => 'integer',
            'contact_confidence_score' => 'integer',
            'overall_score' => 'integer',
            'win_points' => 'integer',
            'win_reply_chance' => 'float',
            'win_interested_chance' => 'float',
            'win_deal_chance' => 'float',
            'win_reasons' => 'array',
            'win_blockers' => 'array',
            'win_computed_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function leadSource(): BelongsTo
    {
        return $this->belongsTo(LeadSource::class);
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function scores(): HasMany
    {
        return $this->hasMany(LeadScore::class);
    }

    public function buyingSignals(): HasMany
    {
        return $this->hasMany(BuyingSignal::class);
    }

    public function campaignRecipients(): HasMany
    {
        return $this->hasMany(CampaignRecipient::class);
    }

    public function emailLogs(): HasMany
    {
        return $this->hasMany(EmailLog::class);
    }

    public function inboundReplies(): HasMany
    {
        return $this->hasMany(InboundReply::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function meetings(): HasMany
    {
        return $this->hasMany(Meeting::class);
    }

    public function deals(): HasMany
    {
        return $this->hasMany(Deal::class);
    }

    public function consentRecords(): HasMany
    {
        return $this->hasMany(ConsentRecord::class);
    }

    public function emailVerifications(): HasMany
    {
        return $this->hasMany(EmailVerification::class);
    }

    public function icpProfile(): BelongsTo
    {
        return $this->belongsTo(IcpProfile::class);
    }

    public function provenance(): MorphMany
    {
        return $this->morphMany(DataProvenance::class, 'subject');
    }

    public function enrichmentRecords(): MorphMany
    {
        return $this->morphMany(EnrichmentRecord::class, 'enrichable');
    }

    public function getPriorityBadgeAttribute(): string
    {
        return match (\App\Support\ScoreBands::band($this->overall_score)) {
            'high' => 'High',
            'qualified' => 'Qualified',
            'nurture' => 'Medium',
            default => 'Low',
        };
    }

    public function isVerifiedEmail(): bool
    {
        return $this->email_verification_status === \App\Support\EmailStatus::VERIFIED;
    }

    /** May outreach go to this address under the current verification policy (see EmailStatus::isSendable). */
    public function hasSendableEmail(): bool
    {
        return \App\Support\EmailStatus::isSendable($this->email_verification_status);
    }

    public function isSuppressed(): bool
    {
        if (empty($this->email)) {
            return true;
        }

        $emailSuppressed = Suppression::where('email', strtolower($this->email))->exists();
        if ($emailSuppressed) {
            return true;
        }

        if ($this->company && $this->company->domain) {
            return Suppression::where('domain', strtolower($this->company->domain))->exists();
        }

        return false;
    }

    public function canReceiveOutreach(): bool
    {
        if (!$this->hasSendableEmail()) {
            return false;
        }

        if ($this->isSuppressed()) {
            return false;
        }

        // Avoid sending to won or lost or not_a_fit or already contacted recently
        if (in_array($this->lifecycle_stage, ['won', 'lost', 'not_a_fit', 'opted_out'])) {
            return false;
        }

        return true;
    }

    public function scopeQualified(Builder $query): Builder
    {
        return $query->where('overall_score', '>=', \App\Support\ScoreBands::qualified());
    }

    public function scopeByStage(Builder $query, ?string $stage): Builder
    {
        return $stage ? $query->where('lifecycle_stage', $stage) : $query;
    }

    public function scopeByCountry(Builder $query, ?string $country): Builder
    {
        return $country ? $query->where('country', $country) : $query;
    }
}
