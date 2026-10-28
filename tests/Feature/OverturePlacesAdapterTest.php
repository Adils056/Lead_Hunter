<?php

namespace Tests\Feature;

use App\Models\BuyingSignal;
use App\Models\Company;
use App\Models\IcpProfile;
use App\Models\Lead;
use App\Services\Discovery\Adapters\OverturePlacesAdapter;
use App\Services\Discovery\DiscoveryManager;
use App\Services\Discovery\SourceStatusService;
use App\Services\Hunting\IcpSearchConfigBuilder;
use App\Services\Integrations\ProviderRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Overture Maps Places (free, keyless, bundled DuckDB) — added 23 Oct 2026 in place of the Google Places key.
 * DuckDB itself is NOT run here: the adapter's process boundary (runSql) and the one-off S3 extract are faked,
 * so these tests pin the SQL that is generated and what is stored, without network or binary.
 */
class OverturePlacesAdapterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['leadpilot.sources.overture_places.duckdb_bin' => __FILE__, 'leadpilot.sources.overture_places.release' => '2026-10-21.0']);
    }

    protected function fake(array $rows): FakeOvertureAdapter
    {
        $a = new FakeOvertureAdapter();
        $a->rows = $rows;
        $dm = app(DiscoveryManager::class);
        $dm->registerAdapter($a);

        return $a;
    }

    public function test_searches_can_skip_cities_not_downloaded_yet_and_the_warm_command_downloads_them(): void
    {
        $a = $this->fake([['id' => 'x1', 'name' => 'Cafe One', 'category' => 'cafe']]);
        config(['leadpilot.sources.overture_places.extract_during_search' => false]);

        $r = $a->discover(['places' => ['Lahore, Pakistan'], 'niches' => ['cafe'], 'limit' => 5]);
        $this->assertStringContainsString('not downloaded yet', implode(' ', $r->errors));
        $this->assertSame([], $a->sqls, 'no query against a missing extract');

        IcpProfile::create(['name' => 'Lahore cafes', 'is_active' => true, 'countries' => ['Pakistan'], 'cities' => ['Lahore'], 'niches' => ['cafe'],
            'business_types' => ['local_business'], 'sources' => ['overture_places']]);
        $dm = app(DiscoveryManager::class);
        $dm->registerAdapter($a);
        $this->app->instance(DiscoveryManager::class, $dm); // the command resolves the same manager, with the fake
        $this->artisan('leadpilot:overture-warm')->expectsOutputToContain('Lahore, Pakistan: ready')->assertExitCode(0);
        $this->assertCount(2, $a->boxes, 'one lookup by the skipped search, one by the download');
    }

    public function test_not_configured_without_the_duckdb_binary_and_configured_with_it(): void
    {
        config(['leadpilot.sources.overture_places.duckdb_bin' => '/nonexistent/duckdb']);
        $this->assertFalse((new OverturePlacesAdapter())->isConfigured());
        $this->assertFalse(app(ProviderRegistry::class)->isConfigured('overture_places'));
        $this->assertStringContainsString('not configured', (new OverturePlacesAdapter())->discover(['places' => ['X'], 'niches' => ['y']])->errors[0]);

        config(['leadpilot.sources.overture_places.duckdb_bin' => __FILE__]);
        $this->assertTrue((new OverturePlacesAdapter())->isConfigured());
        $this->assertTrue(app(ProviderRegistry::class)->isConfigured('overture_places'));
        // free + configured => preferred local source, also for ICPs without an explicit source list
        $this->assertSame('overture_places', app(ProviderRegistry::class)->select('local_discovery')[0]);
        $icp = IcpProfile::create(['name' => 'Clinics', 'is_active' => true, 'countries' => ['Pakistan'], 'cities' => ['Rawalpindi'], 'niches' => ['dental clinic'], 'business_types' => ['local_business'], 'website_requirement' => 'any', 'max_results_per_run' => 20]);
        $cfg = app(IcpSearchConfigBuilder::class)->build($icp);
        $this->assertSame(['overture_places'], array_keys($cfg));
        $this->assertSame(['Rawalpindi, Pakistan'], $cfg['overture_places']['places']);
        $this->assertSame(['dental clinic'], $cfg['overture_places']['niches']);
    }

    public function test_niches_map_to_overture_categories_without_false_word_matches(): void
    {
        $a = new OverturePlacesAdapter();
        $sql = $a->categoryMatch(['dental clinic', 'skin care clinic', 'physiotherapy', 'Hospital']);
        $this->assertStringContainsString("category LIKE 'dental_clinic'", $sql);
        $this->assertStringContainsString("category LIKE 'dermatology'", $sql);
        $this->assertStringContainsString("category LIKE 'physical_therapy'", $sql);
        $this->assertStringContainsString("category LIKE 'hospital%'", $sql);
        $this->assertStringContainsString("LIKE '%,dental_clinic,%'", $sql, 'hierarchy levels are matched too');
        $this->assertStringNotContainsString('car_dealer', $sql, '"care" must not trigger the car niche');
        // unknown niche: name contains the word (no invented category)
        $sql2 = $a->categoryMatch(['hijama centre']);
        $this->assertStringContainsString("name ILIKE '%hijama centre%'", $sql2);
        $this->assertStringContainsString("category LIKE '%hijama_centre%'", $sql2);
        $this->assertSame('(1=0)', $a->categoryMatch(['', ' ']));
    }

    public function test_ingests_real_fields_only_skips_known_rows_and_respects_limit_and_icp_rules(): void
    {
        $icp = IcpProfile::create(['name' => 'Clinics', 'is_active' => true, 'countries' => ['Pakistan'], 'cities' => ['Islamabad'], 'niches' => ['dental clinic'],
            'business_types' => ['local_business'], 'website_requirement' => 'any', 'max_results_per_run' => 2, 'exclude_domains' => ['competitor.pk'], 'sources' => ['overture_places']]);
        Company::create(['name' => 'Already Known Dental', 'external_id' => 'overture:known1', 'country' => 'Pakistan']);
        $a = $this->fake([
            ['id' => 'known1', 'name' => 'Already Known Dental', 'category' => 'dental_clinic', 'hierarchy' => 'health_care,dental_clinic', 'confidence' => 99, 'website' => 'https://known.pk', 'email' => 'info@known.pk', 'phone' => '+92300', 'social' => null, 'address' => 'F-7', 'locality' => 'Islamabad', 'region' => null, 'country' => 'PK', 'postcode' => '44000', 'operating_status' => null, 'lon' => 73.05, 'lat' => 33.7, 'dataset' => 'meta', 'license' => 'CDLA-Permissive-2.0'],
            ['id' => 'c1', 'name' => 'Competitor Dental', 'category' => 'dental_clinic', 'hierarchy' => 'health_care,dental_clinic', 'confidence' => 98, 'website' => 'https://competitor.pk', 'email' => 'hello@competitor.pk', 'phone' => null, 'social' => null, 'address' => null, 'locality' => 'Islamabad', 'region' => null, 'country' => 'PK', 'postcode' => null, 'operating_status' => null, 'lon' => 73.05, 'lat' => 33.7, 'dataset' => 'meta', 'license' => null],
            ['id' => 'e1', 'name' => 'Elite Dental & Aesthetic Centre', 'category' => 'dental_clinic', 'hierarchy' => 'health_care,outpatient_care_facility,dental_clinic', 'confidence' => 99, 'website' => 'www.elitedentalcentre.co', 'email' => 'Elitedentalcentre08@gmail.com', 'phone' => '+923035080011', 'social' => 'https://www.facebook.com/100240225531916', 'address' => 'G 8 markaz doctors plaza', 'locality' => 'Islamabad', 'region' => null, 'country' => 'PK', 'postcode' => '44080', 'operating_status' => null, 'lon' => 73.05109, 'lat' => 33.69706, 'dataset' => 'meta', 'license' => 'CDLA-Permissive-2.0'],
            ['id' => 'm1', 'name' => 'Mehreen Dental Clinic', 'category' => 'dental_clinic', 'hierarchy' => 'health_care,outpatient_care_facility,dental_clinic', 'confidence' => 99, 'website' => 'http://www.instagram.com/mehreendental', 'email' => 'not-an-email', 'phone' => '+923331936193', 'social' => null, 'address' => 'Chaudhry Bostan Khan Rd', 'locality' => 'تحصیل چک لالہ کنٹونمنٹ', 'region' => null, 'country' => 'PK', 'postcode' => '46000', 'operating_status' => null, 'lon' => 73.10495, 'lat' => 33.57367, 'dataset' => 'meta', 'license' => null],
            ['id' => 'z1', 'name' => 'Never Reached Dental', 'category' => 'dental_clinic', 'hierarchy' => 'health_care,dental_clinic', 'confidence' => 90, 'website' => null, 'email' => null, 'phone' => null, 'social' => null, 'address' => null, 'locality' => null, 'region' => null, 'country' => 'PK', 'postcode' => null, 'operating_status' => null, 'lon' => 73.0, 'lat' => 33.6, 'dataset' => 'Microsoft', 'license' => null],
        ]);

        $opts = app(IcpSearchConfigBuilder::class)->build($icp)['overture_places'];
        $res = $a->discover($opts + ['icp' => $icp]);

        $this->assertSame([], $res->errors);
        $this->assertSame(1, $res->duplicatesCount, 'known Overture id skipped quietly');
        $this->assertSame(1, $res->excludedCount, 'competitor excluded by the ICP rule');
        $this->assertSame(2, $res->newCompaniesCount);
        $this->assertSame(2, $res->newLeadsCount);
        $this->assertNull(Company::where('name', 'Never Reached Dental')->first(), 'limit 2 respected');

        $elite = Company::where('external_id', 'overture:e1')->firstOrFail();
        $this->assertSame('https://www.elitedentalcentre.co', $elite->website);
        $this->assertSame('elitedentalcentre08@gmail.com', $elite->public_email);
        $this->assertSame('+923035080011', $elite->public_phone);
        $this->assertSame('Pakistan', $elite->country);
        $this->assertSame('Islamabad', $elite->city);
        $this->assertSame('Dental Clinic', $elite->industry);
        $this->assertSame('overture_places', $elite->source_provider);
        $this->assertStringStartsWith('https://explore.overturemaps.org/#16/33.69706/73.05109', $elite->source_url);
        $this->assertDatabaseHas('data_provenance', ['subject_id' => $elite->id, 'field' => 'public_email', 'source' => 'overture_places', 'confidence' => 99]);
        $lead = Lead::where('company_id', $elite->id)->firstOrFail();
        $this->assertSame('elitedentalcentre08@gmail.com', $lead->email);
        $this->assertSame('generic_inbox', $lead->contact_status, 'a business inbox, not a named person');
        $this->assertSame(['web_redesign'], $lead->relevant_services);
        $this->assertSame('Overture Maps Places', $lead->source_name);
        $this->assertSame($icp->id, $lead->icp_profile_id);

        $this->assertTrue((new OverturePlacesAdapter())->isSocialUrl('http://www.instagram.com/molarsandincisors'));
        $this->assertTrue((new OverturePlacesAdapter())->isSocialUrl('http://youtube.com/%40BSHRwp'));
        $this->assertFalse((new OverturePlacesAdapter())->isSocialUrl('https://www.elitedentalcentre.co'));

        $mehreen = Company::where('external_id', 'overture:m1')->firstOrFail();
        $this->assertNull($mehreen->website, 'an Instagram page is a social profile, not a website');
        $this->assertSame('http://www.instagram.com/mehreendental', collect($res->items)->firstWhere('company', 'Mehreen Dental Clinic')['social']);
        $this->assertNull($mehreen->public_email, 'invalid address is dropped, not stored');
        $this->assertSame('Islamabad', $mehreen->city, 'Urdu-script locality falls back to the ICP city');
        $this->assertSame('needs_enrichment', Lead::where('company_id', $mehreen->id)->value('contact_status'));
        $this->assertSame(0, BuyingSignal::count(), 'a missing website in Overture is never stored as evidence');

        // the generated SQL: confidence floor, closed places out, categories, contactable-first ordering
        $this->assertCount(1, $a->sqls, 'both leads came from the first page');
        $sql = $a->sqls[0];
        $this->assertStringContainsString('coalesce(confidence, 0) >= 0.60', $sql);
        $this->assertStringContainsString("<> 'permanently_closed'", $sql);
        $this->assertStringContainsString("category LIKE 'dental_clinic'", $sql);
        $this->assertStringContainsString('ORDER BY (email IS NOT NULL AND website IS NOT NULL) DESC', $sql);
        $this->assertStringContainsString('LIMIT 50 OFFSET 0', $sql);
        $this->assertSame([[33.5, 72.8, 33.9, 73.4]], $a->boxes, 'city resolved to a bounding box once');
        $this->assertStringContainsString('attribution', json_encode($res->items));
    }

    public function test_website_requirement_and_paging_past_known_rows(): void
    {
        $icp = IcpProfile::create(['name' => 'Weak sites', 'is_active' => true, 'countries' => ['Pakistan'], 'cities' => ['Islamabad'], 'niches' => ['hospital'],
            'business_types' => ['local_business'], 'website_requirement' => 'weak_website', 'max_results_per_run' => 1, 'sources' => ['overture_places']]);
        foreach (range(1, 50) as $i) {
            Company::create(['name' => "Known {$i}", 'external_id' => "overture:k{$i}", 'country' => 'Pakistan']);
        }
        $page1 = array_map(fn ($i) => ['id' => "k{$i}", 'name' => "Known {$i}", 'category' => 'hospital', 'hierarchy' => 'health_care,hospital', 'confidence' => 90, 'website' => "https://k{$i}.pk", 'email' => null, 'phone' => null, 'social' => null, 'address' => null, 'locality' => 'Islamabad', 'region' => null, 'country' => 'PK', 'postcode' => null, 'operating_status' => null, 'lon' => 73.0, 'lat' => 33.6, 'dataset' => 'meta', 'license' => null], range(1, 50));
        $a = $this->fake([]);
        $a->pages = [$page1, [['id' => 'new1', 'name' => 'New Hospital', 'category' => 'hospital', 'hierarchy' => 'health_care,hospital', 'confidence' => 90, 'website' => 'https://newhospital.pk', 'email' => null, 'phone' => null, 'social' => null, 'address' => null, 'locality' => 'Islamabad', 'region' => null, 'country' => 'PK', 'postcode' => null, 'operating_status' => null, 'lon' => 73.0, 'lat' => 33.6, 'dataset' => 'meta', 'license' => null]]];

        $res = $a->discover(app(IcpSearchConfigBuilder::class)->build($icp)['overture_places'] + ['icp' => $icp]);

        $this->assertSame(1, $res->newCompaniesCount);
        $this->assertSame(50, $res->duplicatesCount);
        $this->assertCount(2, $a->sqls);
        $this->assertStringContainsString('AND website IS NOT NULL', $a->sqls[0], 'weak_website => only auditable sites');
        $this->assertStringContainsString('OFFSET 50', $a->sqls[1]);
        $this->assertNotNull(Company::where('external_id', 'overture:new1')->first());
    }

    public function test_source_status_panel_reports_overture(): void
    {
        $all = app(SourceStatusService::class)->all();
        $this->assertSame('ready', $all['overture_places']['state']);
        $this->assertSame('Overture Maps Places', $all['overture_places']['name']);
        config(['leadpilot.sources.overture_places.duckdb_bin' => '/nonexistent/duckdb']);
        $this->assertSame('not_configured', app(SourceStatusService::class)->all()['overture_places']['state']);
    }
}

class FakeOvertureAdapter extends OverturePlacesAdapter
{
    public array $rows = [];
    /** @var array<int, array> explicit pages (overrides rows paging) */
    public array $pages = [];
    public array $sqls = [];
    public array $boxes = [];

    protected function resolveBox(string $place): ?array
    {
        $box = [33.5, 72.8, 33.9, 73.4];
        $this->boxes[] = $box;

        return $box;
    }

    protected function ensureExtract(array $box, string $release): string
    {
        return storage_path("app/overture/{$release}/fake.parquet");
    }

    protected function runSql(string $sql, int $timeout = 120): array
    {
        $this->sqls[] = $sql;
        if ($this->pages) {
            return $this->pages[count($this->sqls) - 1] ?? [];
        }
        preg_match('/LIMIT (\d+) OFFSET (\d+)/', $sql, $m);

        return array_slice($this->rows, (int) ($m[2] ?? 0), (int) ($m[1] ?? 50));
    }
}
