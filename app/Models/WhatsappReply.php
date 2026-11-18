<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Our answer to someone who replied on WhatsApp, sent by a person from the conversation screen. */
class WhatsappReply extends Model
{
    protected $fillable = ['whatsapp_message_id', 'lead_id', 'user_id', 'to_number', 'body', 'status', 'provider_response', 'sent_at'];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(WhatsappMessage::class, 'whatsapp_message_id');
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
