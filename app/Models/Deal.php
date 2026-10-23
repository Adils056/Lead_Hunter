<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Deal extends Model
{
    use HasFactory;

    protected $fillable = [
        'lead_id',
        'company_id',
        'owner_id',
        'title',
        'value',
        'currency',
        'stage',
        'probability',
        'expected_close_date',
        'closed_at',
        'lost_reason',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'float',
            'probability' => 'integer',
            'expected_close_date' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }
}
