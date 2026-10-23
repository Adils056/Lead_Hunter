<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SpendBudget extends Model
{
    protected $table = 'spend_budgets';

    protected $fillable = ['scope','scope_ref','period','limit_cents','is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
