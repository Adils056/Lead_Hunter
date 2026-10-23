<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MailboxCursor extends Model
{
    protected $table = 'mailbox_cursors';

    protected $fillable = ['mailbox','uid_validity','last_uid','last_synced_at'];

    protected function casts(): array
    {
        return ['last_synced_at' => 'datetime'];
    }
}
