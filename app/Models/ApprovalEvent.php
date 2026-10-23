<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApprovalEvent extends Model
{
    protected $table = 'approval_events';
    public $timestamps = false;

    protected $fillable = ['approval_request_id','user_id','event','data','created_at'];

    protected function casts(): array
    {
        return ['data' => 'array', 'created_at' => 'datetime'];
    }

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
