<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RadarItem extends Model
{
    public const LEVELS = ['hot' => 'Hot', 'warm' => 'Warm', 'normal' => 'Normal'];

    protected $fillable = ['platform', 'external_id', 'buying_signal_id', 'company_id', 'title', 'excerpt', 'url', 'author', 'country', 'category',
        'service', 'intent_level', 'intent_score', 'reasons', 'status', 'posted_at'];

    protected function casts(): array
    {
        return ['reasons' => 'array', 'posted_at' => 'datetime'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function signal(): BelongsTo
    {
        return $this->belongsTo(BuyingSignal::class, 'buying_signal_id');
    }
}
