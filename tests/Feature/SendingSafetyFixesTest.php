<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Deal;
use App\Models\EmailTemplate;
use App\Models\Lead;
use App\Models\OutreachSetting;
use App\Models\User;
use App\Services\Autopilot\PerformanceReport;
use App\Services\CampaignDeliveryService;
use App\Services\Integrations\ProviderHealthService;
use App\Services\Outreach\SequenceEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Audit fixes: campaign daily limit, global cap defers, admin-only start, follow-ups need reply sync, won counting. */
class SendingSafetyFixesTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $sales;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $this->sales = User::factory()->create(['role' => 'sales', 'is_active' => true]);
        OutreachSetting::current()->update(['mode' => 'live', 'emergency_stop' => false]);
    }

    protected function campaign(array $attrs = []): Campaign
    {
        $template = EmailTemplate::create(['name' => 'T', 'slug' => 't-' . uniqid(), 'service_category' => 'laravel_php', 'subject' => 'Hi', 'body_html' => '<p>Hi</p>', 'body_text' => 'Hi', 'is_active' => true]);

        return Campaign::create($attrs + ['name' => 'Manual', 'service_offered' => 'Laravel and PHP development', 'min_lead_score' => 50,
            'email_template_id' => $template->id, 'daily_limit' => 10, 'require_approval' => true, 'status' => 'running',
            'created_by' => $this->admin->id, 'approved_by' => $this->admin->id]);
    }

    protected function recipient(Campaign $c, int $i, string $stage = 'qualified'): CampaignRecipient
    {
        $lead = Lead::create(['full_name' => "Lead {$i}", 'email' => "lead{$i}@example{$i}.com", 'email_verification_status' => 'verified',
            'country' => 'United Kingdom', 'overall_score' => 75, 'lifecycle_stage' => $stage]);

        return CampaignRecipient::create(['campaign_id' => $c->id, 'lead_id' => $lead->id, 'status' => 'approved', 'idempotency_key' => "k{$i}",
            'personalized_subject' => 'Hello', 'personalized_body' => 'Hi there.']);
    }

    public function test_campaign_daily_limit_holds_and_the_rest_wait_for_tomorrow(): void
    {
        $c = $this->campaign(['daily_limit' => 2]);
        $recs = collect(range(1, 3))->map(fn ($i) => $this->recipient($c, $i));
        $svc = app(CampaignDeliveryService::class);

        $sent = $recs->filter(fn ($r) => $svc->dispatchRecipient($r))->count();

        $this->assertSame(2, $sent, 'the campaign limit of 2 per day is enforced');
        $this->assertSame(2, $c->fresh()->sent_today);
        $this->assertFalse($c->fresh()->canSendToday());
        $this->assertSame('queued', $recs[2]->fresh()->status, 'held for the next day, not dropped');

        $this->travel(1)->days();
        $this->assertTrue($c->fresh()->canSendToday(), 'a new day resets the limit');
        $this->assertTrue($svc->dispatchRecipient($recs[2]->fresh()));
    }

    public function test_global_daily_cap_defers_approved_mail_without_touching_the_lead(): void
    {
        OutreachSetting::current()->update(['max_daily_sends' => 1]);
        $c = $this->campaign();
        $first = $this->recipient($c, 1);
        $second = $this->recipient($c, 2, 'qualified');
        $svc = app(CampaignDeliveryService::class);

        $this->assertTrue($svc->dispatchRecipient($first));
        $this->assertFalse($svc->dispatchRecipient($second));

        $this->assertSame('queued', $second->fresh()->status, 'approved work waits; it used to be marked skipped for good');
        $this->assertSame('qualified', $second->lead->fresh()->lifecycle_stage, 'a full quota is not a reason to move the lead');
    }

    public function test_a_campaign_only_starts_after_an_admin_approves_it(): void
    {
        $c = $this->campaign(['status' => 'draft', 'require_approval' => false, 'approved_by' => null, 'created_by' => $this->sales->id]);

        $this->actingAs($this->sales)->post(route('campaigns.resume', $c))->assertSessionHas('error');
        $this->assertSame('draft', $c->fresh()->status, 'a sales user cannot start sending on their own');

        $this->actingAs($this->admin)->post(route('campaigns.resume', $c))->assertSessionHas('success');
        $this->assertSame('running', $c->fresh()->status);
        $this->assertSame($this->admin->id, $c->fresh()->approved_by, 'the admin who started it is recorded');

        $stopped = $this->campaign(['status' => 'draft', 'emergency_stopped' => true, 'created_by' => $this->sales->id]);
        $this->actingAs($this->sales)->post(route('campaigns.resume', $stopped))->assertSessionHas('error');
        $this->assertTrue($stopped->fresh()->emergency_stopped, 'only an admin lifts an emergency stop');
    }

    public function test_follow_ups_are_held_while_replies_are_not_being_read(): void
    {
        config(['leadpilot.autopilot.sending_hours' => [0, 24], 'leadpilot.autopilot.sending_days' => [1, 2, 3, 4, 5, 6, 7]]);
        $c = $this->campaign(['is_autopilot' => true]);
        $first = $this->recipient($c, 1);
        $first->update(['status' => 'sent', 'sent_at' => now()->subDays(5), 'sequence_step' => 1]);
        $follow = CampaignRecipient::create(['campaign_id' => $c->id, 'lead_id' => $first->lead_id, 'status' => 'approved', 'idempotency_key' => 'fu',
            'sequence_step' => 2, 'parent_recipient_id' => $first->id, 'personalized_subject' => 'Re: Hello', 'personalized_body' => 'Following up.']);
        $engine = app(SequenceEngine::class);

        // IMAP not set up
        $this->assertStringContainsString('not set up', (string) $engine->replySyncProblem());
        $this->assertStringContainsString('follow-up held', implode(' ', $engine->dispatchDue()['not_sent']));
        $this->assertSame('approved', $follow->fresh()->status, 'kept, not dropped');

        // IMAP set up but the last successful read is stale
        config(['leadpilot.imap.enabled' => true, 'leadpilot.imap.username' => 'u', 'leadpilot.imap.password' => 'p', 'leadpilot.imap.host' => 'h']);
        app(ProviderHealthService::class)->success('imap', true);
        $this->travel(4)->hours();
        $this->assertStringContainsString('have not been read', (string) $engine->replySyncProblem());
        $this->assertSame('approved', $follow->fresh()->status);

        // replies read again: the follow-up may go
        app(ProviderHealthService::class)->success('imap', true);
        $this->assertNull($engine->replySyncProblem());
    }

    public function test_won_is_counted_from_deals_and_crm_stage(): void
    {
        $company = \App\Models\Company::create(['name' => 'Won Co', 'domain' => 'won.example']);
        $lead = Lead::create(['company_id' => $company->id, 'full_name' => 'Won Lead', 'email' => 'won@example.com']);
        $deal = Deal::create(['lead_id' => $lead->id, 'company_id' => $company->id, 'owner_id' => $this->admin->id, 'title' => 'Website', 'value' => 5000, 'stage' => 'negotiation']);
        $this->assertNull($deal->closed_at);

        $deal->update(['stage' => 'won']);
        $this->assertNotNull($deal->fresh()->closed_at, 'closing a deal stamps closed_at');
        $other = Lead::create(['full_name' => 'Crm Won', 'email' => 'crm@example.com', 'lifecycle_stage' => 'won']);

        $totals = app(PerformanceReport::class)->build(90)['totals'];
        $this->assertSame(2, $totals['won'], 'a won deal and a lead marked won in the CRM both count');

        $deal->update(['stage' => 'negotiation']);
        $this->assertNull($deal->fresh()->closed_at, 'reopening clears it');
    }
}
