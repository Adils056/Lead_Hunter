<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeadScore extends Model
{
    use HasFactory;

    protected $fillable = [
        'lead_id',
        'company_id',
        'scoring_version',
        'intent_score',
        'service_fit_score',
        'contact_confidence_score',
        'overall_score',
        'priority_level',
        'reasoning',
        'breakdown',
        'contributing_signals',
        'evaluated_by',
        'evaluated_at',
    ];

    protected function casts(): array
    {
        return [
            'intent_score' => 'integer',
            'service_fit_score' => 'integer',
            'contact_confidence_score' => 'integer',
            'overall_score' => 'integer',
            'breakdown' => 'array',
            'contributing_signals' => 'array',
            'evaluated_at' => 'datetime',
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

    public function evaluator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'evaluated_by');
    }
}
