<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntegrationSyncLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'integration_id',
        'operation',
        'records_processed',
        'records_created',
        'records_updated',
        'errors_count',
        'log_details',
        'started_at',
        'completed_at',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'records_processed' => 'integer',
            'records_created' => 'integer',
            'records_updated' => 'integer',
            'errors_count' => 'integer',
            'log_details' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }
}
