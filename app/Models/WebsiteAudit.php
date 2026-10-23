<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebsiteAudit extends Model
{
    protected $fillable = [
        'company_id', 'url', 'final_url', 'status', 'http_status', 'response_time_ms', 'page_size_bytes',
        'opportunity_score', 'checks', 'technologies', 'contacts_found', 'opportunities', 'pagespeed',
        'summary', 'audited_at',
    ];

    protected function casts(): array
    {
        return [
            'checks' => 'array',
            'technologies' => 'array',
            'contacts_found' => 'array',
            'opportunities' => 'array',
            'pagespeed' => 'array',
            'audited_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** Checks that failed or warned — i.e. the evidence for opportunities. */
    public function issues(): array
    {
        return array_values(array_filter((array) $this->checks, fn ($c) => in_array($c['status'] ?? '', ['fail', 'warn'], true)));
    }

    /** Evidence keys (e.g. no_viewport) for every failed/warned check. */
    public function issueKeys(): array
    {
        return array_values(array_unique(array_merge(...array_map(fn ($c) => (array) ($c['evidence_keys'] ?? []), $this->issues() ?: [[]]))));
    }
}
