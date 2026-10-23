<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Incident extends Model
{
    protected $table = 'incidents';

    protected $fillable = ['type','severity','workflow','dedupe_key','status','title','details','evidence','resolved_by','resolved_at'];

    protected function casts(): array
    {
        return ['evidence' => 'array', 'resolved_at' => 'datetime'];
    }
}
