<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SearchArm extends Model
{
    protected $table = 'search_arms';

    protected $fillable = ['icp_profile_id','source_slug','runs','leads','qualified','positive','cost_cents','query_cursor','last_run_at','exhausted_until'];

    protected function casts(): array
    {
        return ['last_run_at' => 'datetime', 'exhausted_until' => 'datetime'];
    }

    public function icpProfile(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(IcpProfile::class);
    }
}
