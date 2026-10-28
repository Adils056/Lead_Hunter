<?php

namespace Tests\Feature;

use App\Models\IcpProfile;
use App\Models\SystemHeartbeat;
use App\Services\Autopilot\AutopilotRunner;
use App\Services\Discovery\Adapters\OsmOverpassAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** A cycle the host kills half-way must still have sent the day's e-mails, and say where it stopped. */
class CycleResilienceTest extends TestCase
{
    use RefreshDatabase;

    public function test_sending_runs_before_the_slow_steps_and_each_step_is_recorded(): void
    {
        config(['leadpilot.autopilot.discovery_seconds' => 0]);
        IcpProfile::create(['name' => 'Cafes', 'is_active' => true, 'countries' => ['Pakistan'], 'cities' => ['Lahore'], 'niches' => ['cafe'],
            'business_types' => ['local_business'], 'sources' => ['osm_overpass'], 'channels' => ['email']]);

        $out = app(AutopilotRunner::class)->run(true);

        $order = array_keys($out['steps']);
        $this->assertLessThan(array_search('discovery', $order, true), array_search('outreach', $order, true), implode(' > ', $order));
        $this->assertLessThan(array_search('enrichment', $order, true), array_search('outreach', $order, true));
        $this->assertSame('outreach', array_values(array_intersect($order, ['outreach', 'discovery', 'enrichment', 'scoring']))[0]);
        $this->assertStringContainsString('time budget', implode(' ', (array) $out['steps']['discovery']['result']));
        $this->assertSame(end($order), SystemHeartbeat::where('component', 'autopilot_step')->first()->meta['step']);
    }

    public function test_osm_search_gives_up_cleanly_when_its_time_budget_is_spent(): void
    {
        Http::fake();
        config(['leadpilot.osm.run_seconds' => 0]);

        $r = (new OsmOverpassAdapter())->discover(['places' => ['Lahore, Pakistan'], 'niches' => ['cafe'], 'country' => 'Pakistan', 'limit' => 5]);

        $this->assertStringContainsString('time budget', implode(' ', $r->errors));
        Http::assertNothingSent();
    }
}
