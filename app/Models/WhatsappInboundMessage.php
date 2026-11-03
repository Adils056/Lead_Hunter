<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsappInboundMessage extends Model
{
    protected $fillable = ['provider_message_id', 'from_number', 'body', 'lead_id', 'whatsapp_message_id', 'action', 'payload'];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function whatsappMessage(): BelongsTo
    {
        return $this->belongsTo(WhatsappMessage::class);
    }
}
