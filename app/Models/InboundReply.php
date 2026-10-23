<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InboundReply extends Model
{
    use HasFactory;

    protected $fillable = [
        'category',
        'message_id',
        'source',
        'campaign_recipient_id',
        'lead_id',
        'from_email',
        'to_email',
        'subject',
        'body_text',
        'classification',
        'ai_confidence',
        'ai_reasoning',
        'is_reviewed',
        'reviewed_by',
        'reviewed_at',
        'review_notes',
        'received_at',
    ];

    protected function casts(): array
    {
        return [
            'ai_confidence' => 'integer',
            'is_reviewed' => 'boolean',
            'reviewed_at' => 'datetime',
            'received_at' => 'datetime',
        ];
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(CampaignRecipient::class, 'campaign_recipient_id');
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
