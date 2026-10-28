<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\IcpProfile;
use App\Models\SearchArm;
use App\Services\Discovery\Adapters\OsmOverpassAdapter;
use App\Services\Hunting\LeadHuntService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Regression tests for behaviour observed live on 22/23 Oct 2026 (Islamabad/Rawalpindi, this network):
 *  - Nominatim returns Rawalpindi only as a city NODE (no boundary relation) -> query by bounding box
 *  - overpass-api.de answers 504/502 first and 200 on retry
 *  - a mirror that times out is skipped for the next place instead of costing another full timeout
 *  - addr:city is in Urdu script while addr:city:en exists
 *  - a FAILED run (timeouts) must not rest the search arm for 3 days
 */
class OsmLiveBehaviourTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['leadpilot.osm.retry_pause_seconds' => 0]);
    }

    protected function nominatimNodeOnly(): array
    {
        // real shape returned for "Rawalpindi, Pakistan" on 2026-10-23 (boundingbox order: south, north, west, east)
        return [['osm_type' => 'node', 'osm_id' => 58744442, 'type' => 'city', 'boundingbox' => ['33.4315446', '33.7515446', '72.8937210', '73.2137210']]];
    }

    protected function element(int $id, string $name, array $extra = []): array
    {
        return ['type' => 'node', 'id' => $id, 'tags' => array_merge(['name' => $name, 'office' => 'it'], $extra)];
    }

    public function test_city_without_boundary_relation_is_queried_by_bounding_box_and_english_city_is_preferred(): void
    {
        Http::fake([
            'nominatim.openstreetmap.org/*' => Http::response($this->nominatimNodeOnly(), 200),
            'overpass.private.coffee/*' => Http::response(['elements' => [
                $this->element(1, 'Abacus IT', ['addr:city' => 'اسلام آباد', 'addr:city:en' => 'Islamabad', 'phone' => '+92 51 111', 'email' => 'info@abacus.test']),
                $this->element(2, 'Elixir Soft', ['addr:city' => 'راولپنڈی']),
            ]], 200),
            '*' => Http::response('', 404),
        ]);
        config(['leadpilot.osm.overpass_mirrors' => 'https://overpass.private.coffee/api/interpreter,https://overpass-api.de/api/interpreter']); // these tests exercise the breaker/retry logic in a fixed mirror order, independent of the default order
        $osm = new OsmOverpassAdapter();

        $area = $osm->resolveArea('Rawalpindi, Pakistan');
        $this->assertSame([33.4315446, 72.893721, 33.7515446, 73.213721], $area, 'south, west, north, east');
        $this->assertNull($osm->areaId('Rawalpindi, Pakistan'));
        $q = $osm->buildQuery($area, $osm->tagFilters(['software']), 10);
        $this->assertStringContainsString('(33.431545,72.893721,33.751545,73.213721)', $q);
        $this->assertStringNotContainsString('area(', $q);

        $r = $osm->discover(['places' => ['Rawalpindi, Pakistan'], 'niches' => ['software'], 'country' => 'Pakistan', 'limit' => 10]);
        $this->assertSame(2, $r->newCompaniesCount, json_encode($r->errors));
        $this->assertSame('Islamabad', Company::where('external_id', 'osm:node/1')->value('city'));
        $this->assertSame('راولپنڈی', Company::where('external_id', 'osm:node/2')->value('city'), 'no English tag: keep what OSM has');
        Http::assertSent(fn (Request $req) => str_contains($req->url(), 'overpass.private.coffee') && str_contains($req['data'] ?? '', '(33.431545,72.893721,33.751545,73.213721)'));
    }

    public function test_email_only_profiles_ask_the_map_for_places_with_a_website_or_email_and_skip_the_rest(): void
    {
        Http::fake([
            'nominatim.openstreetmap.org/*' => Http::response($this->nominatimNodeOnly(), 200),
            'overpass.private.coffee/*' => Http::response(['elements' => [
                $this->element(1, 'Has Site', ['website' => 'hassite.test']),
                $this->element(2, 'Has Mail', ['email' => 'hello@hasmail.test']),
                $this->element(3, 'Phone Only', ['phone' => '+92 51 111']),
            ]], 200),
            '*' => Http::response('', 404),
        ]);
        config(['leadpilot.osm.overpass_mirrors' => 'https://overpass.private.coffee/api/interpreter']);
        $osm = new OsmOverpassAdapter();

        $q = $osm->buildQuery([1, 2, 3, 4], $osm->tagFilters(['software']), 10, true);
        $this->assertStringContainsString('[~"^(website|contact:website|url|email|contact:email)$"~"."](', $q);
        $this->assertStringNotContainsString('contact:email', $osm->buildQuery([1, 2, 3, 4], $osm->tagFilters(['software']), 10));

        $r = $osm->discover(['places' => ['Rawalpindi, Pakistan'], 'niches' => ['software'], 'country' => 'Pakistan', 'limit' => 10, 'website_requirement' => 'contactable']);
        $this->assertEqualsCanonicalizing(['Has Site', 'Has Mail'], Company::pluck('name')->all());
        $this->assertSame(1, $r->excludedCount);

        $icp = IcpProfile::create(['name' => 'Mail only', 'is_active' => true, 'countries' => ['Pakistan'], 'cities' => ['Rawalpindi'], 'niches' => ['software'],
            'channels' => ['email'], 'website_requirement' => 'any', 'sources' => ['osm_overpass', 'overture_places', 'google_places']]);
        $cfg = app(\App\Services\Hunting\IcpSearchConfigBuilder::class)->build($icp);
        $this->assertSame(['contactable', 'contactable', 'has_website'], [$cfg['osm_overpass']['website_requirement'], $cfg['overture_places']['website_requirement'], $cfg['google_places']['website_requirement']]);
        $icp->update(['channels' => ['email', 'phone']]);
        $this->assertSame('any', app(\App\Services\Hunting\IcpSearchConfigBuilder::class)->build($icp)['osm_overpass']['website_requirement'], 'phone outreach still wants phone-only places');
    }

    public function test_overpass_5xx_is_retried_and_timed_out_mirror_is_skipped_for_the_next_place(): void
    {
        Http::fake([
            'nominatim.openstreetmap.org/*' => Http::response($this->nominatimNodeOnly(), 200),
            'overpass.private.coffee/*' => fn () => throw new ConnectionException('cURL error 28: Operation timed out after 120000 milliseconds'),
            'overpass-api.de/*' => Http::sequence()
                ->push('<html>Gateway Timeout</html>', 504)
                ->push(['elements' => [$this->element(11, 'Dar IT')]], 200)   // place 1 after retry
                ->push(['elements' => [$this->element(12, 'Msz Soft')]], 200), // place 2
            '*' => Http::response('', 404),
        ]);
        config(['leadpilot.osm.overpass_mirrors' => 'https://overpass.private.coffee/api/interpreter,https://overpass-api.de/api/interpreter']); // these tests exercise the breaker/retry logic in a fixed mirror order, independent of the default order
        $osm = new OsmOverpassAdapter();
        $r = $osm->discover(['places' => ['Rawalpindi, Pakistan', 'Islamabad, Pakistan'], 'niches' => ['software'], 'country' => 'Pakistan', 'limit' => 10]);

        $this->assertSame(2, $r->newCompaniesCount, json_encode($r->errors));
        $this->assertCount(1, array_filter($r->errors, fn ($e) => str_contains($e, 'private.coffee')), 'second place must not wait for the mirror that just timed out');
        $this->assertTrue(Cache::has('osm_overpass_down:' . md5('https://overpass.private.coffee/api/interpreter')));
        $this->assertTrue(Cache::has('osm_overpass_ok:' . md5('https://overpass-api.de/api/interpreter')));
        Http::assertSentCount(2 /* nominatim */ + 3 /* 504 + 200 + 200 */); // a faked request that throws is not recorded
    }

    public function test_relation_with_bounding_box_is_queried_by_box_and_soft_timeouts_are_retried(): void
    {
        Http::fake([
            // real shape for "Islamabad, Pakistan": node first, then relation 358002 with its box
            'nominatim.openstreetmap.org/*' => Http::response([
                ['osm_type' => 'node', 'osm_id' => 316440978, 'boundingbox' => ['33.5', '33.9', '72.9', '73.3']],
                ['osm_type' => 'relation', 'osm_id' => 358002, 'boundingbox' => ['33.4930', '33.8530', '72.8030', '73.4030']],
            ], 200),
            'overpass.private.coffee/*' => Http::sequence()
                ->push(['remark' => 'runtime error: Query timed out in "query" at line 3 after 60 seconds.', 'elements' => []], 200)
                ->push(['elements' => [$this->element(21, 'Ciklum')]], 200),
            '*' => Http::response('', 404),
        ]);
        config(['leadpilot.osm.overpass_mirrors' => 'https://overpass.private.coffee/api/interpreter,https://overpass-api.de/api/interpreter']); // these tests exercise the breaker/retry logic in a fixed mirror order, independent of the default order
        $osm = new OsmOverpassAdapter();
        $this->assertSame([33.493, 72.803, 33.853, 73.403], $osm->resolveArea('Islamabad, Pakistan'), 'relation box wins over area id');

        $r = $osm->discover(['places' => ['Islamabad, Pakistan'], 'niches' => ['software'], 'country' => 'Pakistan', 'limit' => 10]);
        $this->assertSame(1, $r->newCompaniesCount, json_encode($r->errors));
        $this->assertSame([], $r->errors);
    }

    public function test_connectivity_check_and_area_misses_are_not_cached_forever(): void
    {
        Http::fake([
            'nominatim.openstreetmap.org/*' => Http::sequence()->push([], 200)->push($this->nominatimNodeOnly(), 200),
            '*' => Http::response('', 404),
        ]);
        config(['leadpilot.osm.overpass_mirrors' => 'https://overpass.private.coffee/api/interpreter,https://overpass-api.de/api/interpreter']); // these tests exercise the breaker/retry logic in a fixed mirror order, independent of the default order
        $osm = new OsmOverpassAdapter();
        $this->assertNull($osm->resolveArea('Nowhere, Pakistan'));
        $this->assertSame('miss', Cache::get('osm_area2:' . md5('nowhere, pakistan')));
        Cache::forget('osm_area2:' . md5('nowhere, pakistan')); // after the 6 h TTL
        $this->assertIsArray($osm->resolveArea('Nowhere, Pakistan'), 'a later lookup can succeed');
    }

    public function test_failed_or_skipped_run_does_not_rest_the_search_arm(): void
    {
        Http::fake([
            'nominatim.openstreetmap.org/*' => Http::response($this->nominatimNodeOnly(), 200),
            'overpass.private.coffee/*' => fn () => throw new ConnectionException('cURL error 28: timed out'),
            'overpass-api.de/*' => fn () => throw new ConnectionException('cURL error 28: timed out'),
            '*' => Http::response('', 404),
        ]);
        $icp = IcpProfile::create(['name' => 'Twin cities IT', 'is_active' => true, 'countries' => ['Pakistan'], 'cities' => ['Rawalpindi'], 'niches' => ['software'],
            'business_types' => ['local_business'], 'website_requirement' => 'any', 'sources' => ['osm_overpass'], 'max_results_per_run' => 5]);
        $arm = SearchArm::create(['icp_profile_id' => $icp->id, 'source_slug' => 'osm_overpass', 'runs' => 1, 'leads' => 0, 'last_run_at' => now()->subDay()]);

        $runs = app(LeadHuntService::class)->hunt($icp, null, false, 0, 'osm_overpass');
        $this->assertSame('failed', collect($runs)->firstWhere('source_slug', 'osm_overpass')?->status);
        $this->assertNull($arm->fresh()->exhausted_until, 'a network failure says nothing about the arm');
        $this->assertSame(2, $arm->fresh()->runs);
    }
}
