<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UsageLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'capability',
        'cost_cents',
        'is_estimate',
        'icp_profile_id',
        'campaign_id',
        'provider',
        'service_type',
        'units_consumed',
        'tokens_used',
        'cost_estimated',
        'user_id',
        'metadata',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'is_estimate' => 'boolean',
            'units_consumed' => 'integer',
            'tokens_used' => 'integer',
            'cost_estimated' => 'float',
            'metadata' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
