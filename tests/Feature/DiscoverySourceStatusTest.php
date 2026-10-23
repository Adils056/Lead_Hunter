<?php

namespace Tests\Feature;

use App\Models\DiscoveryRun;
use App\Models\ProviderHealth;
use App\Models\SearchArm;
use App\Models\User;
use App\Services\Discovery\SourceStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The "Discovery sources" panel must reflect real recorded activity (runs, provider health, rests),
 * not a static configured/not-configured flag, and it must be pollable as JSON.
 */
class DiscoverySourceStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'is_active' => true]);
    }

    public function test_status_is_derived_from_runs_health_and_rests(): void
    {
        config(['leadpilot.sources.google_places.api_key' => null]);

        // OSM: a completed run 10 minutes ago and a rest until tomorrow → "rested", with the run numbers in the detail line.
        DiscoveryRun::create(['source_slug' => 'osm_overpass', 'status' => 'completed', 'total_found' => 5, 'new_leads' => 0, 'duplicates' => 5, 'started_at' => now()->subMinutes(11), 'finished_at' => now()->subMinutes(10)]);
        $icp = \App\Models\IcpProfile::create(['name' => 'Clinics', 'is_active' => true, 'cities' => ['Rawalpindi'], 'niches' => ['dental clinic'], 'sources' => ['osm_overpass']]);
        SearchArm::create(['icp_profile_id' => $icp->id, 'source_slug' => 'osm_overpass', 'runs' => 1, 'exhausted_until' => now()->addDay()]);
        // Find a Tender: a completed run with new leads → "live".
        DiscoveryRun::create(['source_slug' => 'uk_find_a_tender', 'status' => 'completed', 'total_found' => 44, 'new_leads' => 1, 'duplicates' => 1, 'finished_at' => now()->subHour()]);
        // TED: circuit breaker open → "down".
        ProviderHealth::create(['provider' => 'eu_ted', 'status' => 'disabled', 'consecutive_failures' => 5, 'auth_failures' => 0, 'total_calls' => 5, 'total_failures' => 5, 'disabled_until' => now()->addMinutes(30), 'last_error' => 'HTTP 503 from api.ted.europa.eu?api_key=SECRET123']);
        // Contracts Finder: degraded with a redactable error.
        ProviderHealth::create(['provider' => 'uk_contracts_finder', 'status' => 'degraded', 'consecutive_failures' => 1, 'auth_failures' => 0, 'total_calls' => 3, 'total_failures' => 1, 'last_error' => 'cURL error 28: Resolving timed out']);

        $all = app(SourceStatusService::class)->all();

        $this->assertSame('rested', $all['osm_overpass']['state']);
        $this->assertStringContainsString('5 found', $all['osm_overpass']['detail']);
        $this->assertStringContainsString('5 known', $all['osm_overpass']['detail']);
        $this->assertNotNull($all['osm_overpass']['rested_until']);

        $this->assertSame('live', $all['uk_find_a_tender']['state']);
        $this->assertSame(1, $all['uk_find_a_tender']['last_run']['new_leads']);

        $this->assertSame('down', $all['eu_ted']['state']);
        $this->assertStringNotContainsString('SECRET123', (string) $all['eu_ted']['error']);

        $this->assertSame('degraded', $all['uk_contracts_finder']['state']);
        $this->assertStringContainsString('Resolving timed out', $all['uk_contracts_finder']['error']);

        $this->assertSame('not_configured', $all['google_places']['state']);
        $this->assertSame('ready', $all['ats_watchlist']['state']); // configured, never run
    }

    public function test_page_and_json_endpoint_show_live_status(): void
    {
        DiscoveryRun::create(['source_slug' => 'osm_overpass', 'status' => 'completed', 'total_found' => 12, 'new_leads' => 12, 'finished_at' => now()->subMinutes(3)]);

        $admin = $this->admin();
        $this->actingAs($admin)->get(route('hunting.index'))
            ->assertOk()
            ->assertSee('Discovery sources')
            ->assertSee('12 found')
            ->assertSee('live ·');

        $json = $this->actingAs($admin)->getJson(route('hunting.sources'))->assertOk()->json();
        $this->assertNotEmpty($json['as_of']);
        $osm = collect($json['sources'])->firstWhere('slug', 'osm_overpass');
        $this->assertSame('live', $osm['state']);
        $this->assertSame(12, $osm['last_run']['found']);

        // The JSON route must not be swallowed by the /hunting/{icp} model binding.
        $this->assertSame('/hunting/sources.json', parse_url(route('hunting.sources'), PHP_URL_PATH));
    }
}
