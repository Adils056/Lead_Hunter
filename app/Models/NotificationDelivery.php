<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationDelivery extends Model
{
    protected $table = 'notification_deliveries';

    protected $fillable = ['user_id','channel','dedupe_key','status','error','subject_ref'];

    protected function casts(): array
    {
        return [];
    }
}
