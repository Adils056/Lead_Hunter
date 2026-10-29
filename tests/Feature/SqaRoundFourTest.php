<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Company;
use App\Models\EmailTemplate;
use App\Models\IcpProfile;
use App\Models\Lead;
use App\Models\OutreachSetting;
use App\Models\User;
use App\Services\Audit\WebsiteAuditService;
use App\Services\Discovery\Adapters\UkContractsFinderAdapter;
use App\Services\Discovery\DiscoveryResult;
use App\Services\Discovery\ProspectIngestor;
use App\Services\Outreach\SequenceEngine;
use App\Support\Industry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** SQA report 29 Oct 2026, round 4: manual campaigns, clean industries and countries, layout, decision-makers. */
class SqaRoundFourTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
    }

    public function test_bug_13_manual_campaign_form_follows_settings_and_templates_can_be_managed(): void
    {
        OutreachSetting::current()->update(['allowed_countries' => ['Pakistan', 'United Kingdom'], 'max_daily_sends' => 25,
            'approved_services' => ['web_dev', 'web_redesign', 'seo_technical', 'maintenance_support', 'maintenance', 'mobile_app']]);

        $page = $this->actingAs($this->admin)->get(route('campaigns.create'))->assertOk();
        $page->assertSee('New Website')->assertSee('Mobile App')->assertSee('value="25"', false);
        $page->assertDontSee('Germany')->assertDontSee('Canada');
        $this->assertGreaterThan(0, EmailTemplate::count(), 'starter templates exist on a fresh install');
        $this->assertSame(1, substr_count($page->getContent(), 'value="maintenance"'), 'maintenance listed once');

        $tpl = EmailTemplate::first();
        $form = ['name' => 'UK clinics', 'service_offered' => 'web_redesign', 'target_countries' => ['United Kingdom'], 'min_lead_score' => 65,
            'daily_limit' => 20, 'email_template_id' => $tpl->id, 'timezone' => 'Europe/London'];
        $this->post(route('campaigns.store'), ['target_countries' => ['Germany']] + $form)->assertSessionHasErrors('target_countries.0');
        $this->post(route('campaigns.store'), ['service_offered' => 'Laravel and PHP development'] + $form)->assertSessionHasErrors('service_offered');
        $this->post(route('campaigns.store'), ['daily_limit' => 30] + $form)->assertSessionHasErrors('daily_limit');
        $this->post(route('campaigns.store'), $form)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('campaigns', ['name' => 'UK clinics', 'service_offered' => 'web_redesign']);

        $this->get(route('templates.index'))->assertOk()->assertSee($tpl->name);
        $this->post(route('templates.store'), ['name' => 'Clinic redesign', 'service_category' => 'web_redesign', 'subject' => 'Quick idea for {{company_name}}',
            'body_text' => "Hi {{first_name}},\n\nSaw your site.", 'is_active' => '1'])->assertRedirect(route('templates.index'));
        $new = EmailTemplate::where('name', 'Clinic redesign')->firstOrFail();
        $this->assertStringContainsString('{{opt_out_link}}', $new->body_text, 'opt-out link always added');
        $this->put(route('templates.update', $new), ['name' => 'Clinic redesign v2', 'service_category' => 'web_redesign', 'subject' => 'S', 'body_text' => 'B {{opt_out_link}}'])
            ->assertRedirect();
        $this->assertFalse($new->fresh()->is_active);

        $sales = User::factory()->create(['role' => 'sales', 'is_active' => true]);
        $this->actingAs($sales)->get(route('templates.create'))->assertForbidden();
        $this->get(route('templates.index'))->assertOk();
    }

    public function test_bug_17_autopilot_campaign_targets_its_icp_countries(): void
    {
        $icp = IcpProfile::create(['name' => 'Retail Pakistan', 'countries' => ['Pakistan'], 'is_active' => true]);
        $campaign = app(SequenceEngine::class)->campaignFor($icp, 'ecommerce');
        $this->assertSame(['Pakistan'], $campaign->target_countries);

        $old = Campaign::create(['name' => 'Autopilot — Old', 'service_offered' => 'web_dev', 'is_autopilot' => true, 'icp_profile_id' => IcpProfile::create(['name' => 'UK', 'countries' => ['United Kingdom'], 'is_active' => true])->id,
            'created_by' => $this->admin->id, 'status' => 'running', 'target_countries' => []]);
        (require base_path('database/migrations/2026_10_29_000004_sqa_round_four.php'))->up();
        $this->assertSame(['United Kingdom'], $old->fresh()->target_countries);
    }

    public function test_bug_23_industries_collapse_into_one_list_and_vacant_is_skipped(): void
    {
        $this->assertSame([Industry::group('Accountant'), Industry::group('Logistics company'), Industry::group('Builder'), Industry::group('Dentist')],
            [Industry::group('Accountants'), Industry::group('Logistics'), Industry::group('Construction Company'), Industry::group('Dental Surgery')]);

        foreach (['Accountant', 'Accountants', 'Logistics company', 'Atm'] as $i => $ind) {
            Company::create(['name' => "Co {$i}", 'industry' => $ind, 'country' => 'Pakistan']);
        }
        $html = $this->actingAs($this->admin)->get(route('companies.index'))->getContent();
        $this->assertSame(1, substr_count($html, '>Accounting &amp; Finance</option>'));
        $this->assertSame(3, preg_match_all('/Co \d/', $this->get(route('companies.index', ['industry' => 'Accounting & Finance']))->getContent()));

        $r = new DiscoveryResult('test', 'Test');
        $out = (new ProspectIngestor('test', 'Test'))->upsertCompany(['name' => 'Corner Unit', 'industry' => 'Vacant', 'country' => 'Pakistan'], $r);
        $this->assertNull($out['company']);
    }

    public function test_bug_24_tender_buyer_country_comes_from_its_address(): void
    {
        $rel = fn (array $address, string $name) => ['ocid' => 'x-' . $name, 'date' => now()->toIso8601String(),
            'tender' => ['title' => 'Website and software development for programme portal', 'description' => 'Bespoke web application development and hosting',
                'classification' => ['id' => '72000000'], 'tenderPeriod' => ['endDate' => now()->addDays(20)->toIso8601String()]],
            'buyer' => ['name' => $name], 'parties' => [['name' => $name, 'roles' => ['buyer'], 'address' => $address, 'contactPoint' => ['email' => 'bids@' . strtolower($name) . '.example']]]];
        $ingest = new \ReflectionMethod(UkContractsFinderAdapter::class, 'ingestOcdsRelease');
        $adapter = new UkContractsFinderAdapter();
        $ingest->invoke($adapter, $rel(['locality' => 'Kyiv', 'countryName' => 'Ukraine'], 'Chemonics'), ['software'], new ProspectIngestor('uk_contracts_finder', 'UK'), new DiscoveryResult('t', 'T'));
        $ingest->invoke($adapter, $rel(['locality' => 'Kyiv'], 'Aidco'), ['software'], new ProspectIngestor('uk_contracts_finder', 'UK'), new DiscoveryResult('t', 'T'));
        $ingest->invoke($adapter, $rel(['locality' => 'Leeds', 'countryName' => 'England'], 'Leedscc'), ['software'], new ProspectIngestor('uk_contracts_finder', 'UK'), new DiscoveryResult('t', 'T'));

        $this->assertSame(['Ukraine', 'Ukraine', 'United Kingdom'], [Company::where('name', 'Chemonics')->value('country'), Company::where('name', 'Aidco')->value('country'), Company::where('name', 'Leedscc')->value('country')]);

        $stale = Company::create(['name' => 'Old Buyer', 'city' => 'Kyiv', 'country' => 'United Kingdom', 'industry' => 'Public Sector']);
        $lead = Lead::create(['company_id' => $stale->id, 'full_name' => 'x', 'country' => 'United Kingdom']);
        $paris = Company::create(['name' => 'Paris Texas Dental', 'city' => 'Paris', 'country' => 'United States', 'industry' => 'Dentist']);
        (require base_path('database/migrations/2026_10_29_000004_sqa_round_four.php'))->up();
        $this->assertSame(['Ukraine', 'Ukraine', 'United States'], [$stale->fresh()->country, $lead->fresh()->country, $paris->fresh()->country]);
    }

    public function test_bug_30_content_area_cannot_widen_the_page_under_the_sidebar(): void
    {
        $html = $this->actingAs($this->admin)->get(route('dashboard'))->getContent();
        $this->assertMatchesRegularExpression('/#main-content \{[^}]*min-width: 0;[^}]*overflow-x: clip;/s', $html);
    }

    public function test_item_21_decision_maker_named_on_the_site_becomes_a_contact_without_guessing(): void
    {
        $team = '<html><body><h2>Meet the team</h2><div><h3>Sarah Malik</h3><p>Managing Director</p></div><div><h3>Omar Khan</h3><p>Practice Manager</p></div>'
            . '<a href="mailto:sarah.malik@brightsmile.test">Email Sarah</a><a href="mailto:info@brightsmile.test">General</a></body></html>';
        config(['leadpilot.sources.pagespeed.enabled' => false, 'leadpilot.sources.website_crawler.dns_overrides' => ['brightsmile.test' => ['93.184.216.34']]]);
        Http::fake([
            'brightsmile.test/robots.txt' => Http::response("User-agent: *\nDisallow:\n", 200),
            'brightsmile.test/team' => Http::response($team, 200, ['Content-Type' => 'text/html']),
            'brightsmile.test/contact*' => Http::response('', 404),
            'brightsmile.test/about*' => Http::response('', 404),
            'brightsmile.test/our-team' => Http::response('', 404),
            'brightsmile.test*' => Http::response('<html><head><title>Bright Smile</title></head><body><h1>Bright Smile Dental</h1><a href="mailto:info@brightsmile.test">mail</a></body></html>', 200, ['Content-Type' => 'text/html']),
            '*' => Http::response('', 404),
        ]);
        $c = Company::create(['name' => 'Bright Smile Dental', 'website' => 'https://brightsmile.test/', 'domain' => 'brightsmile.test', 'country' => 'United Kingdom']);
        Lead::create(['company_id' => $c->id, 'full_name' => 'Bright Smile Dental — contact unknown', 'contact_status' => 'needs_enrichment']);

        $audit = app(WebsiteAuditService::class)->audit($c);

        $people = collect($audit->contacts_found['people'])->keyBy('name');
        $this->assertSame('sarah.malik@brightsmile.test', $people['Sarah Malik']['email']);
        $this->assertNull($people['Omar Khan']['email'], 'no address is guessed for a person the site gives no email for');

        $sarah = Lead::where('email', 'sarah.malik@brightsmile.test')->firstOrFail();
        $this->assertSame(['Sarah', 'Malik', 'Managing Director', 'identified', 'unverified'], [$sarah->first_name, $sarah->last_name, $sarah->job_title, $sarah->contact_status, $sarah->email_verification_status]);
        $this->assertSame(0, Lead::where('email', 'like', 'omar%')->count());
        $this->assertSame('info@brightsmile.test', Lead::where('company_id', $c->id)->where('contact_status', 'generic_inbox')->value('email'));

        $this->actingAs($this->admin)->get(route('companies.show', $c))->assertOk()->assertSee('Decision-makers on their website')->assertSee('Omar Khan');
    }
}
