<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class WebsiteReport extends Model
{
    protected $fillable = ['company_id', 'benchmark', 'solutions', 'ai', 'ai_provider', 'created_by', 'share_token', 'view_count', 'first_viewed_at', 'last_viewed_at'];

    protected function casts(): array
    {
        return ['benchmark' => 'array', 'solutions' => 'array', 'ai' => 'array', 'first_viewed_at' => 'datetime', 'last_viewed_at' => 'datetime'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** Worth showing the prospect: the site was scored and compared with at least one audited local competitor. */
    public function isShareable(): bool
    {
        $b = (array) $this->benchmark;
        $scored = collect($b['competitors'] ?? [])->filter(fn ($c) => !empty($c['scores']));

        return !empty($b['own']) && $scored->isNotEmpty();
    }

    public function competitorCount(): int
    {
        return collect(((array) $this->benchmark)['competitors'] ?? [])->filter(fn ($c) => !empty($c['scores']))->count();
    }

    /** The private link the prospect gets (unguessable token; the same link keeps working when the report is rebuilt). */
    public function shareUrl(): string
    {
        if (!$this->share_token) {
            $this->forceFill(['share_token' => Str::random(40)])->save();
        }

        return route('reports.public', $this->share_token);
    }
}
