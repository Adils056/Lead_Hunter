<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Company;
use App\Models\Incident;
use App\Models\Lead;
use App\Models\OutreachSetting;
use App\Models\User;
use App\Services\Autopilot\HealthService;
use App\Services\CsvImportService;
use App\Services\WebhookProcessorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Audit fixes (low): emergency stop respected, complaints detected, safe website links, outbox access, ladder refresh. */
class LowFindingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_go_autonomous_never_lifts_an_emergency_stop(): void
    {
        OutreachSetting::current()->update(['emergency_stop' => true, 'emergency_stop_reason' => 'owner pressed stop']);

        $this->artisan('leadpilot:go-autonomous')->expectsOutputToContain('emergency stop is ON and stays on')->assertExitCode(0);

        $this->assertTrue(OutreachSetting::current()->fresh()->isEmergencyStopped());
    }

    public function test_spam_complaints_reach_the_complaint_spike_monitor(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $c = Campaign::create(['name' => 'C', 'service_offered' => 'web_dev', 'created_by' => $admin->id, 'status' => 'running']);
        foreach (range(1, 20) as $i) {
            $lead = Lead::create(['full_name' => "L{$i}", 'email' => "l{$i}@example{$i}.com"]);
            CampaignRecipient::create(['campaign_id' => $c->id, 'lead_id' => $lead->id, 'status' => 'delivered', 'idempotency_key' => "k{$i}",
                'sent_at' => now()->subMinutes($i), 'provider_message_id' => "msg-{$i}"]);
        }

        app(WebhookProcessorService::class)->processWebhook('resend', 'complaint', 'resend:c1', ['email' => 'l1@example1.com', 'message_id' => 'msg-1']);

        $this->assertSame('complaint', CampaignRecipient::where('provider_message_id', 'msg-1')->value('bounce_type'));
        app(HealthService::class)->monitor();
        $this->assertTrue(Incident::where('type', 'complaint_spike')->exists(), '1 complaint in 20 sends is far above 0.3%');
    }

    public function test_a_website_is_only_ever_an_http_link(): void
    {
        $this->assertNull(Company::create(['name' => 'Evil', 'website' => 'javascript:alert(1)'])->website);
        $this->assertNull(Company::create(['name' => 'Data', 'website' => 'data:text/html,hi'])->website);
        $this->assertSame('https://bare.example', Company::create(['name' => 'Bare', 'website' => 'bare.example'])->website);
        $this->assertSame('http://plain.example/about', Company::create(['name' => 'Plain', 'website' => 'http://plain.example/about'])->website);

        // the CSV import path goes through the same rule
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        app(CsvImportService::class)->import([['name' => 'X Y', 'email' => 'x@csv.example', 'company' => 'Csv Co', 'web' => 'javascript:alert(document.cookie)']],
            ['full_name' => 'name', 'email' => 'email', 'company_name' => 'company', 'website' => 'web'], $admin->id);
        $this->assertNull(Company::where('name', 'Csv Co')->value('website'));
    }

    public function test_a_sales_user_cannot_read_the_conversation_of_someone_elses_lead(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $owner = User::factory()->create(['role' => 'sales', 'is_active' => true]);
        $other = User::factory()->create(['role' => 'sales', 'is_active' => true]);
        $lead = Lead::create(['full_name' => 'Private', 'email' => 'p@example.com', 'assigned_user_id' => $owner->id]);
        $c = Campaign::create(['name' => 'C', 'service_offered' => 'web_dev', 'created_by' => $admin->id, 'status' => 'running']);
        $rec = CampaignRecipient::create(['campaign_id' => $c->id, 'lead_id' => $lead->id, 'status' => 'sent', 'idempotency_key' => 'k', 'sent_at' => now(), 'personalized_subject' => 'Hi']);

        $this->actingAs($other)->get(route('outbox.show', $rec))->assertForbidden();
        $this->actingAs($owner)->get(route('outbox.show', $rec))->assertOk();
        $this->actingAs($admin)->get(route('outbox.show', $rec))->assertOk();
    }

    public function test_recompute_ladder_only_touches_changed_leads(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $fresh = Lead::create(['full_name' => 'Ranked', 'email' => 'r@example.com']);
        \Illuminate\Support\Facades\DB::table('leads')->where('id', $fresh->id)->update(['win_tier' => 'C', 'win_computed_at' => now()->addMinute()]);

        $this->actingAs($admin)->post(route('leads.win-ladder.refresh'))->assertSessionHas('success', 'The ladder is already up to date.');
    }
}
