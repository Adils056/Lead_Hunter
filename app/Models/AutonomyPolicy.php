<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AutonomyPolicy extends Model
{
    protected $table = 'autonomy_policies';

    protected $fillable = ['action','mode','limits','updated_by'];

    protected function casts(): array
    {
        return ['limits' => 'array'];
    }
}
