<?php

namespace App\Services\Discovery;

use Carbon\Carbon;

class DiscoveryResult
{
    public string $sourceSlug;
    public string $sourceName;
    public int $totalFound = 0;
    public int $newCompaniesCount = 0;
    public int $newLeadsCount = 0;
    public int $newSignalsCount = 0;
    public int $duplicatesCount = 0;
    public int $excludedCount = 0;
    public int $estimatedCostCents = 0;
    /** @var int[] */
    public array $newLeadIds = [];
    public array $errors = [];
    public array $items = [];
    public Carbon $syncedAt;

    public function __construct(string $sourceSlug, string $sourceName)
    {
        $this->sourceSlug = $sourceSlug;
        $this->sourceName = $sourceName;
        $this->syncedAt = Carbon::now();
    }

    public function recordItem(array $item): void
    {
        $this->items[] = $item;
    }

    public function addError(string $error): void
    {
        $this->errors[] = $error;
    }

    public function isSuccess(): bool
    {
        return empty($this->errors) || $this->totalFound > 0;
    }

    public function toArray(): array
    {
        return [
            'source_slug' => $this->sourceSlug,
            'source_name' => $this->sourceName,
            'total_found' => $this->totalFound,
            'new_companies' => $this->newCompaniesCount,
            'new_leads' => $this->newLeadsCount,
            'new_signals' => $this->newSignalsCount,
            'duplicates' => $this->duplicatesCount,
            'excluded' => $this->excludedCount,
            'estimated_cost_cents' => $this->estimatedCostCents,
            'errors' => $this->errors,
            'synced_at' => $this->syncedAt->toIso8601String(),
        ];
    }
}
