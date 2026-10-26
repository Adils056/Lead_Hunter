<?php

namespace App\Services\Discovery;

use App\Models\Lead;
use App\Models\LeadSource;
use App\Services\Discovery\Adapters\ApolloOrgDiscoveryAdapter;
use App\Services\Discovery\Adapters\PublicJobBoardDemandAdapter;
use App\Services\Discovery\Adapters\GooglePlacesAdapter;
use App\Services\Discovery\Adapters\GreenhouseLeverWatchlistAdapter;
use App\Services\Discovery\Adapters\UkContractsFinderAdapter;
use App\Services\LeadScoringService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class DiscoveryManager
{
    /** @var array<string, LeadDiscoveryInterface> */
    protected array $adapters = [];
    protected LeadScoringService $scoringService;

    public function __construct(LeadScoringService $scoringService)
    {
        $this->scoringService = $scoringService;

        // Register default discovery adapters
        $this->registerAdapter(new \App\Services\Discovery\Adapters\OverturePlacesAdapter()); // free, keyless (bundled DuckDB)
        $this->registerAdapter(new \App\Services\Discovery\Adapters\OsmOverpassAdapter());
        $this->registerAdapter(new GooglePlacesAdapter());
        $this->registerAdapter(new \App\Services\Discovery\Adapters\UkFindATenderAdapter());
        $this->registerAdapter(new \App\Services\Discovery\Adapters\EuTedAdapter());
        $this->registerAdapter(new UkContractsFinderAdapter());
        $this->registerAdapter(new GreenhouseLeverWatchlistAdapter());
        $this->registerAdapter(new \App\Services\Discovery\Adapters\HnWhoIsHiringAdapter()); // free, keyless (Algolia HN API)
        $this->registerAdapter(new \App\Services\Discovery\Adapters\WikidataOrgsAdapter()); // free, keyless B2B (CC0)
        $this->registerAdapter(new ApolloOrgDiscoveryAdapter());
        $this->registerAdapter(new PublicJobBoardDemandAdapter()); // disabled unless LEADPILOT_REMOTIVE_ENABLED
    }

    public function registerAdapter(LeadDiscoveryInterface $adapter): void
    {
        $this->adapters[$adapter->getSourceSlug()] = $adapter;
    }

    /**
     * @return array<string, LeadDiscoveryInterface>
     */
    public function getAdapters(): array
    {
        return $this->adapters;
    }

    public function getAdapter(string $slug): ?LeadDiscoveryInterface
    {
        return $this->adapters[$slug] ?? null;
    }

    /**
     * Run discovery for a specific adapter and trigger scoring for new leads.
     */
    public function runDiscovery(string $slug, array $options = []): DiscoveryResult
    {
        $adapter = $this->getAdapter($slug);
        if (!$adapter) {
            $res = new DiscoveryResult($slug, 'Unknown Source');
            $res->addError("Discovery adapter '{$slug}' is not registered.");
            return $res;
        }

        $result = $adapter->discover($options);

        // Auto-score newly discovered leads
        if ($result->newLeadsCount > 0) {
            $recentLeads = Lead::whereIn('id', $result->newLeadIds)->get();

            foreach ($recentLeads as $lead) {
                try {
                    $this->scoringService->evaluateLead($lead);
                } catch (\Exception $e) {
                    Log::warning("Failed to score discovered lead #{$lead->id}: " . $e->getMessage());
                }
            }
        }

        return $result;
    }

    /**
     * Run discovery across all active adapters.
     *
     * @return array<string, DiscoveryResult>
     */
    public function runAllActive(array $options = []): array
    {
        $results = [];

        foreach ($this->adapters as $slug => $adapter) {
            // Check if source is active in DB
            $dbSource = LeadSource::where('slug', $slug)->first();
            if ($dbSource && !$dbSource->is_active) {
                continue;
            }

            if (!$adapter->isConfigured()) {
                continue;
            }

            $results[$slug] = $this->runDiscovery($slug, $options);
        }

        return $results;
    }

    /**
     * Test connection for all adapters.
     */
    public function testAll(): array
    {
        $statuses = [];
        foreach ($this->adapters as $slug => $adapter) {
            $statuses[$slug] = [
                'name' => $adapter->getSourceName(),
                'type' => $adapter->getProviderType(),
                'configured' => $adapter->isConfigured(),
                'test' => $adapter->testConnection(),
            ];
        }
        return $statuses;
    }
}
