<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkflowControl extends Model
{
    protected $table = 'workflow_controls';

    protected $fillable = ['workflow','paused','reason','incident_id','paused_at'];

    protected function casts(): array
    {
        return ['paused' => 'boolean', 'paused_at' => 'datetime'];
    }
}
