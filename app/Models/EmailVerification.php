<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailVerification extends Model
{
    use HasFactory;

    protected $fillable = [
        'lead_id',
        'email',
        'status',
        'provider',
        'score',
        'is_disposable',
        'is_free_mailbox',
        'has_mx_records',
        'has_smtp_check',
        'provider_response',
        'credits_used',
        'verified_at',
    ];

    protected function casts(): array
    {
        return [
            'score' => 'integer',
            'is_disposable' => 'boolean',
            'is_free_mailbox' => 'boolean',
            'has_mx_records' => 'boolean',
            'has_smtp_check' => 'boolean',
            'provider_response' => 'array',
            'credits_used' => 'integer',
            'verified_at' => 'datetime',
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }
}
