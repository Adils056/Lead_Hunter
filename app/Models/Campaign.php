<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Campaign extends Model
{
    use HasFactory;

    protected $fillable = [
        'icp_profile_id',
        'is_autopilot',
        'name',
        'service_offered',
        'target_countries',
        'target_industries',
        'min_lead_score',
        'eligible_sources',
        'contact_rules',
        'daily_limit',
        'sent_today',
        'last_sent_date',
        'schedule_config',
        'timezone',
        'email_template_id',
        'follow_up_config',
        'require_approval',
        'status',
        'emergency_stopped',
        'created_by',
        'approved_by',
        'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'is_autopilot' => 'boolean',
            'target_countries' => 'array',
            'target_industries' => 'array',
            'eligible_sources' => 'array',
            'contact_rules' => 'array',
            'schedule_config' => 'array',
            'follow_up_config' => 'array',
            'min_lead_score' => 'integer',
            'daily_limit' => 'integer',
            'sent_today' => 'integer',
            'last_sent_date' => 'date',
            'require_approval' => 'boolean',
            'emergency_stopped' => 'boolean',
            'approved_at' => 'datetime',
        ];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(EmailTemplate::class, 'email_template_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(CampaignRecipient::class);
    }

    public function canSendToday(): bool
    {
        if ($this->emergency_stopped || $this->status !== 'running') {
            return false;
        }

        if ($this->last_sent_date !== now()->toDateString()) {
            return true; // New day, limit resets
        }

        return $this->sent_today < $this->daily_limit;
    }

    public function incrementSentCount(): void
    {
        if ($this->last_sent_date !== now()->toDateString()) {
            $this->sent_today = 1;
            $this->last_sent_date = now()->toDateString();
        } else {
            $this->sent_today += 1;
        }
        $this->save();
    }

    public function scopeRunning(Builder $query): Builder
    {
        return $query->where('status', 'running')->where('emergency_stopped', false);
    }

    public function scopePendingApproval(Builder $query): Builder
    {
        return $query->where('status', 'pending_approval');
    }
}
