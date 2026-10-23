<?php

namespace App\Services\Connectors;

use App\Models\Lead;

interface SocialConnectorInterface
{
    public function getSlug(): string;

    public function getName(): string;

    public function getPlatform(): string;

    public function isConfigured(): bool;

    /**
     * Clear statement of what official APIs support vs what is strictly prohibited.
     */
    public function getCapabilities(): array;

    /**
     * Test connection/reachability with configured API credentials.
     */
    public function testConnection(): array;

    /**
     * Process an authorized inbound lead form submission payload.
     */
    public function handleLeadWebhook(array $payload): ?Lead;
}
