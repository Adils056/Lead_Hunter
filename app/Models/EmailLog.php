<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'campaign_recipient_id',
        'campaign_id',
        'lead_id',
        'direction',
        'provider',
        'message_id',
        'sender_email',
        'recipient_email',
        'subject',
        'body_snippet',
        'status',
        'event_metadata',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'event_metadata' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(CampaignRecipient::class, 'campaign_recipient_id');
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }
}
