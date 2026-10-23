<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Company;
use App\Models\EmailTemplate;
use App\Models\InboundReply;
use App\Models\Lead;
use App\Models\Suppression;
use App\Models\User;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebhookAndReplyTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected Company $company;
    protected Lead $lead;
    protected Campaign $campaign;
    protected CampaignRecipient $recipient;

    protected function setUp(): void
    {
        parent::setUp();
        config(['leadpilot.webhooks.secret' => 'test-secret']);

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->company = Company::create([
            'name' => 'Acme Cloud Dynamics',
            'domain' => 'acmeclouddynamics.com',
            'country' => 'United States',
        ]);

        $this->lead = Lead::create([
            'company_id' => $this->company->id,
            'full_name' => 'Sarah Connor',
            'email' => 'sconnor@acmeclouddynamics.com',
            'email_verification_status' => 'verified',
            'lifecycle_stage' => 'contacted',
            'country' => 'United States',
        ]);

        $template = EmailTemplate::create([
            'name' => 'Test Template',
            'slug' => 'test-tmpl',
            'service_category' => 'web_dev',
            'subject' => 'Quick question',
            'body_html' => '<p>Hello</p>',
            'body_text' => 'Hello',
        ]);

        $this->campaign = Campaign::create([
            'name' => 'Q4 Enterprise Cloud',
            'service_offered' => 'Web application development',
            'status' => 'running',
            'email_template_id' => $template->id,
            'created_by' => $this->admin->id,
        ]);

        $this->recipient = CampaignRecipient::create([
            'campaign_id' => $this->campaign->id,
            'lead_id' => $this->lead->id,
            'status' => 'sent',
            'sent_at' => now(),
            'provider_message_id' => 'msg_resend_999888777',
            'personalized_subject' => 'Quick question for Sarah',
            'personalized_body' => 'Hi Sarah, are you exploring new cloud apps?',
            'idempotency_key' => 'key_send_999888777',
        ]);
    }

    public function test_delivery_webhook_marks_recipient_delivered(): void
    {
        $payload = [
            'type' => 'delivered',
            'idempotency_key' => 'evt_delivery_1001',
            'message_id' => 'msg_resend_999888777',
            'email' => 'sconnor@acmeclouddynamics.com',
        ];

        $response = $this->postJson('/webhooks/resend', $payload, $this->sig($payload));

        $response->assertStatus(200);
        $response->assertJson(['status' => 'success']);

        $this->recipient->refresh();
        $this->assertEquals('delivered', $this->recipient->status);
        $this->assertNotNull($this->recipient->delivered_at);
        $this->assertDatabaseHas('webhook_events', [
            'idempotency_key' => 'resend:evt_delivery_1001',
            'is_processed' => true,
        ]);
    }

    public function test_duplicate_webhook_is_handled_idempotently(): void
    {
        $payload = [
            'type' => 'delivered',
            'idempotency_key' => 'evt_unique_dedup_1002',
            'message_id' => 'msg_resend_999888777',
            'email' => 'sconnor@acmeclouddynamics.com',
        ];

        // First send
        $first = $this->postJson('/webhooks/resend', $payload, $this->sig($payload));
        $first->assertStatus(200);
        $first->assertJson(['status' => 'success']);

        // Duplicate second send
        $second = $this->postJson('/webhooks/resend', $payload, $this->sig($payload));
        $second->assertStatus(200);
        $second->assertJson(['status' => 'duplicate']);

        $this->assertEquals(1, WebhookEvent::where('idempotency_key', 'resend:evt_unique_dedup_1002')->count());
    }

    public function test_hard_bounce_webhook_auto_suppresses_email_and_invalidates_lead(): void
    {
        $payload = [
            'type' => 'hard_bounce',
            'idempotency_key' => 'evt_bounce_1003',
            'message_id' => 'msg_resend_999888777',
            'email' => 'sconnor@acmeclouddynamics.com',
            'reason' => 'Mailbox does not exist (550 User Unknown)',
        ];

        $response = $this->postJson('/webhooks/resend', $payload, $this->sig($payload));

        $response->assertStatus(200);

        $this->recipient->refresh();
        $this->assertEquals('bounced', $this->recipient->status);
        $this->assertEquals('hard', $this->recipient->bounce_type);

        $this->lead->refresh();
        $this->assertEquals('invalid', $this->lead->email_verification_status);

        // Strict assertion: Email must now be suppressed
        $this->assertTrue(Suppression::isSuppressed('sconnor@acmeclouddynamics.com'));
        $this->assertDatabaseHas('suppressions', [
            'email' => 'sconnor@acmeclouddynamics.com',
            'reason' => 'hard_bounce',
        ]);
    }

    public function test_unsubscribe_webhook_marks_suppressed_and_updates_lifecycle(): void
    {
        $payload = [
            'type' => 'unsubscribe',
            'idempotency_key' => 'evt_unsub_1004',
            'email' => 'sconnor@acmeclouddynamics.com',
        ];

        $response = $this->postJson('/webhooks/resend', $payload, $this->sig($payload));
        $response->assertStatus(200);

        $this->assertTrue(Suppression::isSuppressed('sconnor@acmeclouddynamics.com'));

        $this->lead->refresh();
        $this->assertEquals('not_a_fit', $this->lead->lifecycle_stage);
    }

    public function test_inbound_reply_classifies_and_updates_crm_stage(): void
    {
        $payload = [
            'type' => 'reply',
            'idempotency_key' => 'evt_reply_1005',
            'message_id' => 'msg_resend_999888777',
            'email' => 'sconnor@acmeclouddynamics.com',
            'subject' => 'Re: Quick question for Sarah',
            'body' => 'Hi, thanks for reaching out. Yes, we are actively looking for Laravel developers to assist our upcoming release. Can we schedule a call next Tuesday at 2 PM EST?',
        ];

        $response = $this->postJson('/webhooks/resend', $payload, $this->sig($payload));
        $response->assertStatus(200);

        $this->recipient->refresh();
        $this->assertEquals('replied', $this->recipient->status);

        $this->lead->refresh();
        $this->assertEquals('interested', $this->lead->lifecycle_stage);

        $this->assertDatabaseHas('inbound_replies', [
            'lead_id' => $this->lead->id,
            'campaign_recipient_id' => $this->recipient->id,
            'classification' => 'meeting_request',
        ]);
    }

    /** HMAC-SHA256 of the JSON body, as a real provider would send it. */
    protected function sig(array $payload): array
    {
        return ['X-Webhook-Signature' => hash_hmac('sha256', json_encode($payload), 'test-secret')];
    }
}
