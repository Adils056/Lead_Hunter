<?php

namespace App\Services\Discovery;

interface LeadDiscoveryInterface
{
    /**
     * Unique machine identifier for this source adapter.
     */
    public function getSourceSlug(): string;

    /**
     * Human-friendly name.
     */
    public function getSourceName(): string;

    /**
     * Type classification: public_procurement, job_board, apollo_orgs, public_announcements, csv_import
     */
    public function getProviderType(): string;

    /**
     * Check if prerequisite credentials or network resources are available.
     */
    public function isConfigured(): bool;

    /**
     * Test connection/reachability to the source.
     * Returns: ['success' => bool, 'message' => string, 'details' => array]
     */
    public function testConnection(): array;

    /**
     * Execute discovery cycle with parameters (keywords, target countries, limit).
     */
    public function discover(array $options = []): DiscoveryResult;
}
