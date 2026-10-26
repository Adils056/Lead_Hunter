<?php

namespace Tests\Feature;

use App\Models\IcpProfile;
use App\Models\OutreachSetting;
use App\Services\Autopilot\AutonomyPolicyService;
use App\Services\Hunting\AutoMarketService;
use App\Services\Hunting\IcpSearchConfigBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Auto Market: the autopilot creates its own target profiles and one command turns on autonomous outreach. */
class AutoMarketTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_builds_country_by_segment_profiles_once_and_respects_owner_changes(): void
    {
        OutreachSetting::current()->update(['allowed_countries' => ['Pakistan', 'United Kingdom']]);
        $svc = app(AutoMarketService::class);
        $segments = count(config('leadpilot.auto_market.segments'));

        $first = $svc->sync();
        // tenders apply to the UK only
        $this->assertSame(($segments - 1) + $segments, $first['created']);
        $this->assertSame(0, $svc->sync()['created'], 're-sync never duplicates');

        $clinics = IcpProfile::where('auto_key', AutoMarketService::key('Pakistan', 'clinics'))->firstOrFail();
        $this->assertSame(['Pakistan'], $clinics->countries);
        $this->assertContains('Lahore', $clinics->cities);
        $this->assertSame(['osm_overpass'], array_keys(app(IcpSearchConfigBuilder::class)->build($clinics)), 'free local source, no keys needed');
        $tenders = IcpProfile::where('auto_key', AutoMarketService::key('United Kingdom', 'tenders'))->firstOrFail();
        $this->assertContains('uk_contracts_finder', array_keys(app(IcpSearchConfigBuilder::class)->build($tenders)));
        $this->assertNull(IcpProfile::where('auto_key', AutoMarketService::key('Pakistan', 'tenders'))->first());

        // owner edits and deletions stick
        $clinics->update(['niches' => ['dentist'], 'is_active' => false]);
        IcpProfile::where('auto_key', AutoMarketService::key('Pakistan', 'food'))->first()->delete();
        $svc->sync();
        $this->assertSame(['dentist'], $clinics->fresh()->niches);
        $this->assertNull(IcpProfile::where('auto_key', AutoMarketService::key('Pakistan', 'food'))->first());

        // a country removed from the outreach list is switched off, not deleted
        OutreachSetting::current()->update(['allowed_countries' => ['Pakistan']]);
        $this->assertGreaterThan(0, $svc->sync()['deactivated']);
        $this->assertFalse((bool) IcpProfile::where('auto_key', AutoMarketService::key('United Kingdom', 'clinics'))->value('is_active'));
    }

    public function test_go_autonomous_turns_on_automatic_first_touch_with_guardrails(): void
    {
        $this->artisan('leadpilot:go-autonomous')->expectsOutputToContain('Mode is still dry_run')->assertExitCode(0);

        $s = OutreachSetting::current()->fresh();
        $this->assertSame('dry_run', $s->mode, 'never goes live without --live');
        $this->assertSame(array_keys(config('leadpilot.auto_market.countries')), $s->allowed_countries);
        $this->assertNotContains('Canada', $s->allowed_countries);
        $this->assertTrue($s->require_verified_email);
        $this->assertFalse($s->allow_mx_only);
        $this->assertTrue(app(AutonomyPolicyService::class)->isAutomatic('outreach.first_touch'));
        $this->assertGreaterThan(0, IcpProfile::whereNotNull('auto_key')->where('is_active', true)->count());

        $this->artisan('leadpilot:go-autonomous --live')->assertExitCode(0);
        $this->assertSame('live', OutreachSetting::current()->fresh()->mode);
    }

    public function test_autopilot_cycle_builds_the_market_when_enabled(): void
    {
        config(['leadpilot.auto_market.enabled' => true, 'leadpilot.auto_market.countries' => ['Pakistan' => ['Lahore']]]);
        OutreachSetting::current()->update(['allowed_countries' => ['Pakistan']]);
        \Illuminate\Support\Facades\Http::fake(); // OSM answers empty: the cycle still creates the profiles first
        app(\App\Services\Autopilot\AutopilotRunner::class)->run();

        $this->assertGreaterThan(0, IcpProfile::where('auto_key', 'like', 'pakistan:%')->count());
    }

    public function test_status_command_fails_on_a_stalled_cron_and_passes_when_everything_beats(): void
    {
        $this->artisan('leadpilot:status')->expectsOutputToContain('FAILING')->assertExitCode(1);

        foreach (['scheduler', 'queue_worker'] as $c) {
            \App\Models\SystemHeartbeat::create(['component' => $c, 'beat_at' => now(), 'meta' => []]);
        }
        \App\Models\SystemHeartbeat::create(['component' => 'autopilot', 'beat_at' => now(), 'meta' => ['ok' => true]]);
        $this->artisan('leadpilot:status')->expectsOutputToContain('No failures')->assertExitCode(0);
    }

    public function test_a_lock_left_by_a_killed_cycle_is_reported_and_can_be_cleared(): void
    {
        \Illuminate\Support\Facades\Http::fake();
        \Illuminate\Support\Facades\Cache::lock(\App\Services\Autopilot\AutopilotRunner::LOCK, 900)->get(); // a run the host killed
        $this->assertArrayHasKey('skipped', app(\App\Services\Autopilot\AutopilotRunner::class)->run());

        $this->artisan('leadpilot:autopilot --unlock')->expectsOutputToContain('Cycle lock cleared')->expectsOutputToContain('"enrichment"')->assertExitCode(0);
    }

    public function test_audits_stop_at_the_time_budget(): void
    {
        \Illuminate\Support\Facades\Http::fake();
        config(['leadpilot.autopilot.audit_seconds' => -1]);
        $c = \App\Models\Company::create(['name' => 'Slow Site', 'website' => 'https://slow.example', 'domain' => 'slow.example', 'country' => 'Pakistan']);
        \App\Models\Lead::create(['company_id' => $c->id, 'first_name' => 'A', 'email' => 'a@slow.example', 'country' => 'Pakistan']);

        $out = app(\App\Services\Autopilot\AutopilotRunner::class)->run();

        $this->assertTrue($out['steps']['enrichment']['result']['audit_time_budget_reached']);
        $this->assertSame(0, $out['steps']['enrichment']['result']['audited']);
    }

    public function test_an_audited_local_business_is_not_held_down_by_growth_data_it_can_never_have(): void
    {
        $icp = IcpProfile::create(['name' => 'Lahore clinics', 'is_active' => true, 'countries' => ['Pakistan'], 'cities' => ['Lahore'], 'niches' => ['dentist'],
            'business_types' => ['local_business'], 'target_services' => ['web_redesign'], 'website_requirement' => 'any', 'max_results_per_run' => 10]);
        $make = function (?int $opportunity) use ($icp) {
            $c = \App\Models\Company::create(['name' => 'Smile Dental ' . uniqid(), 'website' => 'https://smile' . uniqid() . '.pk', 'country' => 'Pakistan', 'city' => 'Lahore',
                'industry' => 'Dentist', 'public_phone' => '+92 42 1234567', 'source_provider' => 'osm_overpass',
                'website_opportunity_score' => $opportunity, 'website_audited_at' => $opportunity === null ? null : now()]);
            if ($opportunity !== null && $opportunity >= 25) { // what WebsiteAuditService::syncSignal records for a real gap
                \App\Models\BuyingSignal::create(['company_id' => $c->id, 'signal_type' => 'website_issues', 'title' => "Website audit: opportunity {$opportunity}/100",
                    'evidence_text' => 'No viewport meta tag.', 'relevant_service' => 'web_redesign', 'confidence' => 80, 'observed_date' => now()->toDateString(),
                    'verification_status' => 'verified', 'metadata' => ['source' => 'website_audit']]);
            }
            $l = \App\Models\Lead::create(['company_id' => $c->id, 'icp_profile_id' => $icp->id, 'first_name' => 'Info', 'email' => 'info@' . parse_url($c->website, PHP_URL_HOST),
                'email_verification_status' => 'verified', 'contact_status' => 'generic_inbox', 'country' => 'Pakistan', 'source_name' => 'OpenStreetMap (Overpass)']);

            return app(\App\Services\LeadScoringService::class)->evaluateLead($l)->overall_score;
        };

        $weakSite = $make(60);
        $goodSite = $make(15);
        $minorGaps = $make(20);
        $notAudited = $make(null);

        $this->assertGreaterThanOrEqual(\App\Support\ScoreBands::qualified(), $weakSite, 'audited weak website + verified inbox qualifies');
        $this->assertLessThan(\App\Support\ScoreBands::qualified(), $goodSite, 'a good website is still not a winning lead');
        $this->assertLessThan(\App\Support\ScoreBands::qualified(), $minorGaps, 'gaps too small to record a website-issues signal give no lift');
        $this->assertLessThan(\App\Support\ScoreBands::qualified(), $notAudited, 'no evidence, no lift');
    }

    public function test_public_sector_bodies_are_never_cold_emailed(): void
    {
        foreach (['bidwill-p.school@det.nsw.edu.au', 'info@council.gov.uk', 'a@moh.gov.ae', 'x@trust.nhs.uk', 'office@school.sch.uk', 'dean@uni.ac.uk', 'https://www.punjab.gov.pk', 'info@nyc.gov'] as $v) {
            $this->assertTrue(\App\Support\PublicSector::matches($v), $v);
        }
        foreach (['info@smiledental.pk', 'hello@govtech-solutions.com', 'info@education-first.co.uk', 'https://edutech.ae'] as $v) {
            $this->assertFalse(\App\Support\PublicSector::matches($v), $v);
        }

        $c = \App\Models\Company::create(['name' => 'Bidwill Public School', 'website' => 'https://bidwill-p.schools.nsw.gov.au', 'country' => 'Australia']);
        $lead = \App\Models\Lead::create(['company_id' => $c->id, 'first_name' => 'Info', 'email' => 'bidwill-p.school@det.nsw.edu.au', 'email_verification_status' => 'verified',
            'contact_status' => 'generic_inbox', 'country' => 'Australia', 'overall_score' => 80]);
        $this->assertSame('D', app(\App\Services\Outreach\WinLadderService::class)->evaluate($lead)['tier']);
        $this->assertArrayHasKey('public_sector', app(\App\Services\EligibilityGateService::class)->evaluate($lead)->failedChecks);
    }

    public function test_companies_with_a_confirmed_inbox_are_audited_first(): void
    {
        \Illuminate\Support\Facades\Http::fake();
        config(['leadpilot.autopilot.audits_per_cycle' => 1]);
        $mk = function (string $name, string $status) {
            $c = \App\Models\Company::create(['name' => $name, 'website' => 'https://' . strtolower(str_replace(' ', '', $name)) . '.example', 'country' => 'Pakistan']);
            \App\Models\Lead::create(['company_id' => $c->id, 'first_name' => 'Info', 'email' => 'info@x' . uniqid() . '.example', 'email_verification_status' => $status, 'country' => 'Pakistan']);

            return $c;
        };
        $mk('No Email Yet', 'unverified');
        $ready = $mk('Confirmed Inbox', 'verified');
        $audited = [];
        $this->app->instance(\App\Services\Audit\WebsiteAuditService::class, new class($audited) extends \App\Services\Audit\WebsiteAuditService {
            public function __construct(public array &$seen) {}

            public function audit(\App\Models\Company $company): \App\Models\WebsiteAudit
            {
                $this->seen[] = $company->id;

                return \App\Models\WebsiteAudit::create(['company_id' => $company->id, 'url' => $company->website, 'status' => 'completed', 'audited_at' => now()]);
            }
        });

        app(\App\Services\Autopilot\AutopilotRunner::class)->run();

        $this->assertSame([$ready->id], $audited);
    }

    public function test_a_website_that_is_not_the_emails_business_blocks_outreach(): void
    {
        $mk = fn (string $site, string $email) => \App\Models\Lead::create(['company_id' => \App\Models\Company::create(['name' => 'Bold ' . uniqid(), 'website' => $site, 'country' => 'United Kingdom'])->id,
            'first_name' => 'Info', 'email' => $email, 'email_verification_status' => 'verified', 'country' => 'United Kingdom', 'overall_score' => 80]);

        $this->assertNotNull(\App\Services\EligibilityGateService::websiteMismatch($mk('https://www.thehouselondon.co.uk/', 'foodandbar@boldcafe.co.uk')));
        $this->assertNull(\App\Services\EligibilityGateService::websiteMismatch($mk('https://www.premierinn.com/', 'res@mena.premierinn.com')), 'subdomain of the same site');
        $this->assertNull(\App\Services\EligibilityGateService::websiteMismatch($mk('https://smile.pk', 'smiledental@gmail.com')), 'free mail proves nothing either way');
        $this->assertArrayHasKey('website_match', app(\App\Services\EligibilityGateService::class)->evaluate($mk('https://other.co.uk', 'info@bold.co.uk'))->failedChecks);
    }

    public function test_one_failed_page_load_is_not_evidence(): void
    {
        config(['leadpilot.sources.website_crawler.dns_overrides' => ['down-cafe.example' => ['93.184.216.34']]]); // let the SSRF guard pass a fake host
        $c = \App\Models\Company::create(['name' => 'Down Cafe', 'website' => 'https://down-cafe.example', 'country' => 'United Kingdom']);
        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response('', 404)]);
        $svc = app(\App\Services\Audit\WebsiteAuditService::class);

        $first = $svc->audit($c);
        $this->assertSame('unreachable', $first->status);
        $this->assertSame([0, 0], [$first->opportunity_score, \App\Models\BuyingSignal::where('company_id', $c->id)->count()], 'first failure: no claim');

        $this->travel(2)->hours();
        $second = $svc->audit($c);
        $this->assertSame(40, $second->opportunity_score, 'confirmed an hour later: modest evidence');
        $this->assertSame(1, \App\Models\BuyingSignal::where('company_id', $c->id)->count());
    }

    public function test_first_touch_cap_warms_up_after_going_live(): void
    {
        config(['leadpilot.autopilot.max_first_touch_per_day' => 25, 'leadpilot.autopilot.ramp' => ['days' => 3, 'cap' => 10]]);
        $s = OutreachSetting::current();

        $s->update(['activated_at' => now()->subDay()]);
        $this->assertSame(10, $s->fresh()->firstTouchCap());
        $s->update(['activated_at' => now()->subDays(4)]);
        $this->assertSame(25, $s->fresh()->firstTouchCap());
    }
}
