<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class EnrichmentRecord extends Model
{
    use HasFactory;

    protected $fillable = [
        'enrichable_type',
        'enrichable_id',
        'provider',
        'operation',
        'status',
        'raw_payload',
        'normalized_data',
        'credits_used',
        'cost_estimated',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'raw_payload' => 'array',
            'normalized_data' => 'array',
            'credits_used' => 'integer',
            'cost_estimated' => 'float',
        ];
    }

    public function enrichable(): MorphTo
    {
        return $this->morphTo();
    }
}
