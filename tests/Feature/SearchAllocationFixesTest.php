<?php

namespace Tests\Feature;

use App\Models\DiscoveryRun;
use App\Models\IcpProfile;
use App\Models\SearchArm;
use App\Services\Autopilot\SearchAllocator;
use App\Services\Discovery\DiscoveryManager;
use App\Services\Discovery\DiscoveryResult;
use App\Services\Discovery\LeadDiscoveryInterface;
use App\Services\Hunting\LeadHuntService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Two live findings from the owner's PC (23 Oct 2026):
 *  - the allocator kept choosing Google Places (no key) and skipping it, 19 cycles in a row, while OSM idled;
 *  - a 5-result validation run that returned 5 already-known clinics rested the OSM arm for 3 days.
 */
class SearchAllocationFixesTest extends TestCase
{
    use RefreshDatabase;

    /** DiscoveryManager is not a singleton: bind one instance carrying the stub so LeadHuntService sees it, and block all real HTTP. */
    protected function useAdapter(LeadDiscoveryInterface $adapter): void
    {
        \Illuminate\Support\Facades\Http::preventStrayRequests();
        $manager = new DiscoveryManager(app(\App\Services\LeadScoringService::class));
        $manager->registerAdapter($adapter);
        $this->app->instance(DiscoveryManager::class, $manager);
    }

    protected function icp(): IcpProfile
    {
        return IcpProfile::create(['name' => 'Clinics', 'is_active' => true, 'countries' => ['Pakistan'], 'cities' => ['Rawalpindi'], 'niches' => ['dental clinic'], 'business_types' => ['local_business'], 'sources' => ['osm_overpass', 'google_places'], 'max_results_per_run' => 20]);
    }

    public function test_allocator_never_picks_a_source_that_is_not_configured(): void
    {
        config(['leadpilot.sources.google_places.api_key' => null]);
        $this->icp();

        $picked = collect(app(SearchAllocator::class)->pick(5))->pluck('slug')->all();

        $this->assertContains('osm_overpass', $picked);
        $this->assertNotContains('google_places', $picked, 'an unconfigured source can only produce skipped runs');
    }

    public function test_capped_run_with_only_known_results_does_not_rest_the_arm(): void
    {
        $icp = $this->icp();
        $arm = SearchArm::create(['icp_profile_id' => $icp->id, 'source_slug' => 'osm_overpass', 'runs' => 1, 'leads' => 5, 'last_run_at' => now()->subHour()]);

        // Adapter that "finds" nothing new but reports it hit the cap (found == limit).
        $adapter = new class implements LeadDiscoveryInterface {
            public function getSourceSlug(): string { return 'osm_overpass'; }
            public function getSourceName(): string { return 'OSM stub'; }
            public function getProviderType(): string { return 'local_discovery'; }
            public function isConfigured(): bool { return true; }
            public function testConnection(): array { return ['success' => true, 'message' => 'ok', 'details' => []]; }
            public function discover(array $options = []): DiscoveryResult
            {
                $r = new DiscoveryResult('osm_overpass', 'OSM stub');
                $r->totalFound = (int) ($options['limit'] ?? 5);

                return $r;
            }
        };
        $this->useAdapter($adapter);

        $runs = app(LeadHuntService::class)->hunt($icp, null, false, 0, 'osm_overpass');
        $run = collect($runs)->firstWhere('source_slug', 'osm_overpass');
        $this->assertNotNull($run);
        $this->assertSame((int) ($run->options['limit'] ?? 0), (int) $run->total_found, 'fixture: the run is capped');

        $this->assertNull($arm->fresh()->exhausted_until, 'a capped run proves nothing about exhaustion');

        // An uncapped empty run (found < limit), repeated, still rests the arm.
        $adapter2 = new class implements LeadDiscoveryInterface {
            public function getSourceSlug(): string { return 'osm_overpass'; }
            public function getSourceName(): string { return 'OSM stub'; }
            public function getProviderType(): string { return 'local_discovery'; }
            public function isConfigured(): bool { return true; }
            public function testConnection(): array { return ['success' => true, 'message' => 'ok', 'details' => []]; }
            public function discover(array $options = []): DiscoveryResult { return new DiscoveryResult('osm_overpass', 'OSM stub'); }
        };
        $this->useAdapter($adapter2);
        app(LeadHuntService::class)->hunt($icp, null, false, 0, 'osm_overpass');
        $this->assertNotNull($arm->fresh()->exhausted_until);
    }
}
