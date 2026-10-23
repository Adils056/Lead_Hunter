<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LeadSource extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'provider_type',
        'description',
        'config',
        'is_active',
        'status',
        'last_synced_at',
        'sync_frequency',
        'total_discovered',
        'total_errors',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'config' => 'array',
            'is_active' => 'boolean',
            'last_synced_at' => 'datetime',
            'total_discovered' => 'integer',
            'total_errors' => 'integer',
        ];
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }
}
