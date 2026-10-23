<?php

namespace App\Services\Discovery\Adapters;

use App\Services\Discovery\DiscoveryResult;
use App\Services\Discovery\ProspectIngestor;

/**
 * REMOVED in v3/v4. The original class contained a hard-coded list of FAKE tenders with invented
 * URLs and email addresses. It is replaced by real sources: UkContractsFinderAdapter,
 * UkFindATenderAdapter and EuTedAdapter. This inert stub exists only so that an install which did not
 * delete the old file cannot run it. It is not registered anywhere and always returns no data.
 * You may safely delete this file.
 */
class PublicProcurementAdapter extends AbstractDiscoveryAdapter
{
    protected string $sourceSlug = 'public_procurement_removed';
    protected string $sourceName = 'Removed (fake data)';
    protected string $providerType = 'removed';

    public function isConfigured(): bool
    {
        return false;
    }

    public function testConnection(): array
    {
        return ['success' => false, 'message' => 'Removed: contained fabricated tenders.', 'details' => []];
    }

    protected function fetchAndIngest(array $options, DiscoveryResult $result, ProspectIngestor $ingestor): void
    {
        $result->addError('PublicProcurementAdapter was removed because it contained fabricated data.');
    }
}
