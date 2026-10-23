<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SystemHeartbeat extends Model
{
    protected $table = 'system_heartbeats';

    protected $fillable = ['component','beat_at','meta'];

    protected function casts(): array
    {
        return ['beat_at' => 'datetime', 'meta' => 'array'];
    }
}
