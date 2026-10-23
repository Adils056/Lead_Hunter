<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CampaignRecipient extends Model
{
    use HasFactory;

    protected $fillable = [
        'campaign_id',
        'lead_id',
        'sequence_step',
        'parent_recipient_id',
        'rfc_message_id',
        'facts_used',
        'status',
        'idempotency_key',
        'personalized_subject',
        'personalized_body',
        'scheduled_at',
        'sent_at',
        'delivered_at',
        'replied_at',
        'bounced_at',
        'provider_message_id',
        'bounce_type',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'facts_used' => 'array',
            'scheduled_at' => 'datetime',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'replied_at' => 'datetime',
            'bounced_at' => 'datetime',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(EmailLog::class);
    }

    public function replies(): HasMany
    {
        return $this->hasMany(InboundReply::class);
    }

    public function children(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(CampaignRecipient::class, 'parent_recipient_id');
    }

    public function parent(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(CampaignRecipient::class, 'parent_recipient_id');
    }
}
