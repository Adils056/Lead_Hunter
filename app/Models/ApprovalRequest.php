<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ApprovalRequest extends Model
{
    public const OPEN = ['pending', 'info_requested'];

    protected $fillable = [
        'type', 'dedupe_key', 'status', 'priority', 'title', 'summary', 'subject_type', 'subject_id', 'payload', 'evidence',
        'attempted', 'options', 'recommended_action', 'assigned_role', 'assigned_user_id', 'escalation_level', 'due_at',
        'last_notified_at', 'decided_by', 'decided_at', 'decision_note', 'execution_result',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array', 'evidence' => 'array', 'attempted' => 'array', 'options' => 'array',
            'due_at' => 'datetime', 'last_notified_at' => 'datetime', 'decided_at' => 'datetime', 'escalation_level' => 'integer',
        ];
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function events(): HasMany
    {
        return $this->hasMany(ApprovalEvent::class)->orderBy('id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }

    public function scopeOpen($q)
    {
        return $q->whereIn('status', self::OPEN);
    }
}
