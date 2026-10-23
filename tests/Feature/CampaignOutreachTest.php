<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Company;
use App\Models\EmailTemplate;
use App\Models\Lead;
use App\Models\Suppression;
use App\Models\User;
use App\Services\CampaignDeliveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class CampaignOutreachTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected EmailTemplate $template;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->template = EmailTemplate::create([
            'name' => 'Laravel Outreach Template',
            'slug' => 'test-laravel-template',
            'service_category' => 'laravel_php',
            'subject' => 'Scaling {{company_name}} engineering',
            'body_html' => '<p>Hi {{first_name}}, let\'s connect.</p>',
            'body_text' => "Hi {{first_name}}, let's connect.",
            'is_active' => true,
        ]);
    }

    public function test_campaign_builds_drafts_and_excludes_suppressed_prospects(): void
    {
        $comp = Company::create(['name' => 'Target Co', 'domain' => 'target.com', 'country' => 'United States']);

        $allowedLead = Lead::create([
            'company_id' => $comp->id,
            'full_name' => 'Alice Valid',
            'first_name' => 'Alice',
            'email' => 'alice@target.com',
            'email_verification_status' => 'verified',
            'country' => 'United States',
            'overall_score' => 80,
        ]);

        $suppressedLead = Lead::create([
            'company_id' => $comp->id,
            'full_name' => 'Bob Blocked',
            'first_name' => 'Bob',
            'email' => 'bob@suppressed.com',
            'email_verification_status' => 'verified',
            'country' => 'United States',
            'overall_score' => 85,
        ]);
        Suppression::create(['email' => 'bob@suppressed.com', 'reason' => 'unsubscribe']);

        $campaign = Campaign::create([
            'name' => 'US Enterprise Outreach',
            'service_offered' => 'Laravel and PHP development',
            'target_countries' => ['United States'],
            'min_lead_score' => 70,
            'email_template_id' => $this->template->id,
            'daily_limit' => 20,
            'require_approval' => true,
            'status' => 'draft',
            'created_by' => $this->admin->id,
        ]);

        $deliveryService = app(CampaignDeliveryService::class);
        $count = $deliveryService->buildCampaignDrafts($campaign);

        $this->assertEquals(1, $count);
        $this->assertDatabaseHas('campaign_recipients', ['campaign_id' => $campaign->id, 'lead_id' => $allowedLead->id]);
        $this->assertDatabaseMissing('campaign_recipients', ['campaign_id' => $campaign->id, 'lead_id' => $suppressedLead->id]);
    }

    public function test_campaign_approval_and_recipient_dispatch(): void
    {
        $campaign = Campaign::create([
            'name' => 'Approval Test Campaign',
            'service_offered' => 'Laravel and PHP development',
            'min_lead_score' => 50,
            'email_template_id' => $this->template->id,
            'daily_limit' => 10,
            'require_approval' => true,
            'status' => 'draft',
            'created_by' => $this->admin->id,
        ]);

        $lead = Lead::create([
            'full_name' => 'David Lee',
            'email' => 'david@example.com',
            'email_verification_status' => 'verified',
            'country' => 'United Kingdom',
            'overall_score' => 75,
        ]);

        $recipient = CampaignRecipient::create([
            'campaign_id' => $campaign->id,
            'lead_id' => $lead->id,
            'status' => 'draft',
            'idempotency_key' => 'unique_test_key_123',
            'personalized_subject' => 'Scaling David engineering',
            'personalized_body' => 'Hi David, let us connect.',
        ]);

        // Non-approved campaign cannot be dispatched
        $deliveryService = app(CampaignDeliveryService::class);
        $dispatched = $deliveryService->dispatchRecipient($recipient);
        $this->assertFalse($dispatched);

        // Approve campaign
        $this->actingAs($this->admin)->post("/campaigns/{$campaign->id}/approve");
        $campaign->refresh();
        $recipient->refresh();

        $this->assertEquals('running', $campaign->status);
        $this->assertEquals('approved', $recipient->status);

        // Phase 0: manual dispatch is blocked while the system is in DRY-RUN (the default mode)
        $this->assertFalse($deliveryService->dispatchRecipient($recipient));
        $this->assertStringContainsString('DRY-RUN', (string) $recipient->fresh()->error_message);
        \App\Models\OutreachSetting::current()->update(['mode' => 'live']);
        $recipient->update(['status' => 'approved', 'error_message' => null]);

        // Dispatch approved recipient
        $dispatched = $deliveryService->dispatchRecipient($recipient);
        $this->assertTrue($dispatched);
        $recipient->refresh();
        $this->assertEquals('sent', $recipient->status);
        $this->assertEquals(1, $campaign->fresh()->sent_today);
    }

    public function test_emergency_stop_halts_campaign_immediately(): void
    {
        $campaign = Campaign::create([
            'name' => 'Killswitch Test',
            'service_offered' => 'Custom software development',
            'min_lead_score' => 50,
            'daily_limit' => 10,
            'status' => 'running',
            'created_by' => $this->admin->id,
        ]);

        $response = $this->actingAs($this->admin)->post("/campaigns/{$campaign->id}/emergency-stop");
        $campaign->refresh();

        $this->assertTrue($campaign->emergency_stopped);
        $this->assertEquals('paused', $campaign->status);
    }

    public function test_test_email_sends_to_designated_admin_address(): void
    {
        $campaign = Campaign::create([
            'name' => 'Test Email Campaign',
            'service_offered' => 'Laravel and PHP development',
            'min_lead_score' => 50,
            'email_template_id' => $this->template->id,
            'daily_limit' => 10,
            'status' => 'draft',
            'created_by' => $this->admin->id,
        ]);

        $response = $this->actingAs($this->admin)->post("/campaigns/{$campaign->id}/test-email", [
            'test_email' => 'someone-else@example.com',
        ]);
        $response->assertSessionHasErrors('test_email'); // Phase 0: not an administrator address

        $response = $this->actingAs($this->admin)->post("/campaigns/{$campaign->id}/test-email", [
            'test_email' => $this->admin->email,
        ]);

        $response->assertSessionHas('success');
    }
}
