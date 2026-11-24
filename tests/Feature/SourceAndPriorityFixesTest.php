<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Company;
use App\Models\IcpProfile;
use App\Models\Lead;
use App\Models\OutreachSetting;
use App\Models\ProviderHealth;
use App\Models\User;
use App\Services\Discovery\DiscoveryResult;
use App\Services\Discovery\ProspectIngestor;
use App\Services\Hunting\IcpSearchConfigBuilder;
use App\Services\Integrations\ProviderHealthService;
use App\Services\Outreach\SequenceEngine;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 24 Nov 2026 report: first e-mails by reply chance, over-long Overture values, a source down for days. */
class SourceAndPriorityFixesTest extends TestCase
{
    use RefreshDatabase;

    public function test_values_longer_than_their_column_are_fitted_not_refused(): void
    {
        $long = str_repeat('Clinic ', 50); // 350 characters
        $fit = ProspectIngestor::fit([
            'name' => $long,
            'public_phone' => '+92 300 1111111, +92 300 2222222, +92 300 3333333, +92 300 4444444',
            'website' => 'https://clinic.example/' . str_repeat('a', 40) . '?utm=' . str_repeat('x', 300),
            'source_url' => 'https://maps.example/' . str_repeat('p', 600),
            'city' => 'Lahore',
        ]);
        $this->assertLessThanOrEqual(255, mb_strlen($fit['name']));
        $this->assertStringStartsWith('Clinic Clinic', $fit['name']);
        $this->assertSame('+92 300 1111111', $fit['public_phone'], 'several numbers: the first one is kept');
        $this->assertSame('https://clinic.example/' . str_repeat('a', 40), $fit['website'], 'the tracking part is dropped, the link still works');
        $this->assertArrayNotHasKey('source_url', $fit, 'a URL that cannot fit is left out, never cut in half');
        $this->assertSame('Lahore', $fit['city']);

        // a lead for a company with a very long name gets a label that fits its column
        $ingestor = new ProspectIngestor('overture_places', 'Overture Maps Places');
        $result = new DiscoveryResult('overture_places', 'Overture Maps Places');
        $up = $ingestor->upsertCompany(['name' => $long, 'city' => 'Lahore', 'country' => 'Pakistan', 'external_id' => 'ovt:1'], $result);
        $lead = $ingestor->ensureLead($up['company'], [], $result);
        $this->assertLessThanOrEqual(255, mb_strlen($lead->full_name));
        $this->assertStringEndsWith('contact unknown', $lead->full_name);
    }

    public function test_a_source_failing_for_days_rests_longer_each_time_up_to_a_day(): void
    {
        $this->assertSame(60, ProviderHealthService::cooldownMinutes(5));
        $this->assertSame(120, ProviderHealthService::cooldownMinutes(10));
        $this->assertSame(240, ProviderHealthService::cooldownMinutes(15));
        $this->assertSame(1440, ProviderHealthService::cooldownMinutes(78), 'OpenStreetMap, 78 failures in a row: one try a day');

        $svc = app(ProviderHealthService::class);
        foreach (range(1, 10) as $i) {
            $svc->failure('osm_overpass', 'cURL error 28: Operation timed out');
        }
        $h = ProviderHealth::where('provider', 'osm_overpass')->first();
        $this->assertSame('disabled', $h->status);
        $this->assertEqualsWithDelta(120, now()->diffInMinutes($h->disabled_until), 1);
    }

    public function test_a_switched_off_free_source_is_replaced_by_a_working_one_of_the_same_kind(): void
    {
        config(['leadpilot.sources.overture_places.duckdb_bin' => __FILE__]); // Overture is installed
        $icp = IcpProfile::create(['name' => 'Lahore dentists', 'countries' => ['Pakistan'], 'cities' => ['Lahore'], 'niches' => ['dentist'],
            'sources' => ['osm_overpass'], 'target_services' => ['web_redesign'], 'max_results_per_run' => 10, 'is_active' => true]);

        $this->assertSame(['osm_overpass'], array_keys(app(IcpSearchConfigBuilder::class)->build($icp)), 'working: the chosen source is kept');

        ProviderHealth::create(['provider' => 'osm_overpass', 'status' => 'disabled', 'disabled_until' => now()->addDay(),
            'consecutive_failures' => 78, 'auth_failures' => 0, 'total_calls' => 78, 'total_failures' => 78]);
        $cfg = app(IcpSearchConfigBuilder::class)->build($icp);
        $this->assertSame(['overture_places'], array_keys($cfg));
        $this->assertSame(['Lahore, Pakistan'], $cfg['overture_places']['places'], 'same search, other source');
    }

    public function test_first_emails_with_the_best_chance_of_a_reply_go_first(): void
    {
        config(['mail.default' => 'array', 'leadpilot.timezone' => 'Asia/Karachi', 'leadpilot.autopilot.sending_hours' => [0, 24],
            'leadpilot.autopilot.sending_days' => [1, 2, 3, 4, 5, 6, 7]]);
        $this->travelTo(Carbon::parse('2026-11-24 10:00', 'Asia/Karachi'));
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $campaign = Campaign::create(['name' => 'Autopilot — General', 'service_offered' => 'web_redesign', 'created_by' => $admin->id,
            'status' => 'running', 'daily_limit' => 25, 'is_autopilot' => true]);
        OutreachSetting::current()->update(['max_daily_sends' => 25, 'activated_at' => now()->subMonth()]);

        // drafted oldest first: small website gap, then no evidence, then a business that is hiring
        foreach ([['Small Gap', 2.5, 3], ['Nothing Known', null, 2], ['Hiring Co', 8.0, 1]] as $i => [$name, $chance, $hoursAgo]) {
            $c = Company::create(['name' => $name, 'domain' => strtolower(str_replace(' ', '', $name)) . '.example', 'country' => 'Pakistan']);
            $lead = Lead::create(['company_id' => $c->id, 'first_name' => 'Sam', 'full_name' => 'Sam Hill', 'email' => "sam{$i}@{$c->domain}",
                'contact_status' => 'identified', 'email_verification_status' => 'verified', 'country' => 'Pakistan']);
            $lead->forceFill(['win_reply_chance' => $chance])->save(); // set by the Win Ladder refresh
            CampaignRecipient::create(['campaign_id' => $campaign->id, 'lead_id' => $lead->id, 'sequence_step' => 1, 'status' => 'approved',
                'idempotency_key' => "t_{$i}", 'scheduled_at' => now()->subHours($hoursAgo)]);
        }

        $order = app(SequenceEngine::class)->dueQueue(20)->map(fn ($r) => $r->lead->company->name)->all();
        $this->assertSame(['Hiring Co', 'Small Gap', 'Nothing Known'], $order);
    }

    public function test_no_cold_email_to_a_colleague_while_the_company_is_in_a_conversation(): void
    {
        $c = Company::create(['name' => 'Sidekick Labs', 'domain' => 'sidekick.example', 'country' => 'United States']);
        $replied = Lead::create(['company_id' => $c->id, 'first_name' => 'Ana', 'last_name' => 'Ruiz', 'full_name' => 'Ana Ruiz', 'email' => 'ana@sidekick.example',
            'contact_status' => 'identified', 'email_verification_status' => 'verified', 'lifecycle_stage' => 'replied']);
        $colleague = Lead::create(['company_id' => $c->id, 'first_name' => 'Ben', 'full_name' => 'Ben Ode', 'email' => 'ben@sidekick.example',
            'contact_status' => 'identified', 'email_verification_status' => 'verified', 'lifecycle_stage' => 'new']);
        $gate = app(\App\Services\EligibilityGateService::class);

        $r = $gate->evaluate($colleague->fresh());
        $this->assertArrayHasKey('company_conversation', $r->failedChecks);
        $this->assertStringContainsString('Ana Ruiz', $r->failedChecks['company_conversation']);

        // once that conversation is not active any more (e.g. moved back to nurture), colleagues are not blocked by it
        $replied->update(['lifecycle_stage' => 'nurture']);
        $this->assertArrayNotHasKey('company_conversation', $gate->evaluate($colleague->fresh())->failedChecks);
    }
}
