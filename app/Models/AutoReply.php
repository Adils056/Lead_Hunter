<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A prospect's message that LeadPilot answered by itself, or decided to leave to a person. */
class AutoReply extends Model
{
    protected $fillable = ['channel', 'source_id', 'lead_id', 'status', 'attempts', 'reason', 'body', 'writer'];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }
}
