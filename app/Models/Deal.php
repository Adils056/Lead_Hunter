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

    public const CLOSED_STAGES = ['won', 'closed_won', 'lost', 'closed_lost'];

    protected static function booted(): void
    {
        // "won this month" reports read closed_at: stamp it when a deal closes, clear it if the deal is reopened
        static::saving(function (Deal $deal) {
            if (in_array($deal->stage, self::CLOSED_STAGES, true)) {
                $deal->closed_at ??= now();
            } elseif ($deal->isDirty('stage')) {
                $deal->closed_at = null;
            }
        });
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
