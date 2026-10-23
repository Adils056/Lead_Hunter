<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BuyingSignal extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'lead_id',
        'signal_type',
        'title',
        'source_url',
        'evidence_text',
        'observed_date',
        'confidence',
        'relevant_service',
        'expiry_date',
        'verification_status',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'observed_date' => 'date',
            'expiry_date' => 'date',
            'confidence' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function isFresh(): bool
    {
        if ($this->expiry_date && Carbon::parse($this->expiry_date)->isPast()) {
            return false;
        }

        // Within 60 days
        return Carbon::parse($this->observed_date)->diffInDays(now()) <= 60;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('verification_status', '!=', 'dismissed')
            ->where(function ($q) {
                $q->whereNull('expiry_date')
                  ->orWhere('expiry_date', '>=', now()->toDateString());
            });
    }

    public function scopeVerified(Builder $query): Builder
    {
        return $query->where('verification_status', 'verified');
    }
}
