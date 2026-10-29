<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebsiteReport extends Model
{
    protected $fillable = ['company_id', 'benchmark', 'solutions', 'ai', 'ai_provider', 'created_by'];

    protected function casts(): array
    {
        return ['benchmark' => 'array', 'solutions' => 'array', 'ai' => 'array'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
