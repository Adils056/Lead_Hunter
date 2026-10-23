<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DiscoveryRun extends Model
{
    protected $fillable = [
        'icp_profile_id', 'source_slug', 'status', 'options', 'total_found', 'new_companies', 'new_leads',
        'new_signals', 'duplicates', 'excluded', 'errors', 'estimated_cost_cents', 'triggered_by',
        'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'errors' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function icpProfile(): BelongsTo
    {
        return $this->belongsTo(IcpProfile::class);
    }
}
