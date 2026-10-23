<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProviderHealth extends Model
{
    protected $table = 'provider_health';

    protected $fillable = ['provider','status','consecutive_failures','auth_failures','total_calls','total_failures','last_success_at','last_failure_at','last_error','disabled_until','last_verified_at'];

    protected function casts(): array
    {
        return ['last_success_at' => 'datetime', 'last_failure_at' => 'datetime', 'disabled_until' => 'datetime', 'last_verified_at' => 'datetime'];
    }
}
