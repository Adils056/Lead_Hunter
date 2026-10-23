<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Lead;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\Outreach\OutreachMailer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Phase 0 hotfix (audit 23 Oct 2026): the live-site exposures.
 *  P0-1 public REST API · P0-2 unsigned webhooks · P1-10 References header · P1-11 test-e-mail relay ·
 *  P1-14 CRM / buying-signal IDOR · P1-15 login throttling.
 */
class Phase0HotfixTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_v1_requires_a_logged_in_user(): void
    {
        $this->getJson('/api/v1/leads')->assertStatus(401);
        $this->getJson('/api/v1/dashboard')->assertStatus(401);
        $this->postJson('/api/v1/ai/research', ['company_id' => 1])->assertStatus(401);

        $this->actingAs(User::factory()->create(['role' => 'sales', 'is_active' => true]));
        $this->getJson('/api/v1/leads')->assertOk();
    }

    public function test_webhooks_are_rejected_unless_signed_with_the_configured_secret(): void
    {
        $payload = ['event_type' => 'delivered', 'event_id' => 'evt-1', 'email' => 'x@example.com', 'message_id' => 'none'];

        config(['leadpilot.webhooks.secret' => null]);
        $this->postJson('/webhooks/resend', $payload)->assertStatus(503);

        config(['leadpilot.webhooks.secret' => 's3cret']);
        $this->postJson('/webhooks/resend', $payload)->assertStatus(401);
        $this->postJson('/webhooks/resend', $payload, ['X-Webhook-Signature' => 'deadbeef'])->assertStatus(401);
        $this->postJson('/webhooks/not-a-provider', $payload, ['X-Webhook-Signature' => hash_hmac('sha256', json_encode($payload), 's3cret')])->assertStatus(404);
        $this->assertSame(0, WebhookEvent::count(), 'nothing is stored before the signature is verified');

        $good = ['X-Webhook-Signature' => 'sha256=' . hash_hmac('sha256', json_encode($payload), 's3cret')];
        $this->postJson('/webhooks/resend', $payload, $good)->assertStatus(200);
        $this->assertSame(1, WebhookEvent::count());
        $this->assertSame('resend:evt-1', WebhookEvent::first()->idempotency_key, 'idempotency key is provider-scoped');
    }

    public function test_login_is_throttled_after_five_attempts(): void
    {
        User::factory()->create(['email' => 'owner@eziline.test', 'role' => 'admin', 'is_active' => true]);
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => 'owner@eziline.test', 'password' => 'wrong-' . $i])->assertSessionHasErrors('email');
        }
        $this->post('/login', ['email' => 'owner@eziline.test', 'password' => 'wrong-6'])->assertStatus(429);
    }

    public function test_sales_users_cannot_touch_other_reps_leads_or_companies_in_the_crm(): void
    {
        $rep = User::factory()->create(['role' => 'sales', 'is_active' => true]);
        $other = User::factory()->create(['role' => 'sales', 'is_active' => true]);
        $company = Company::create(['name' => 'Theirs Ltd', 'assigned_user_id' => $other->id, 'country' => 'Pakistan']);
        $theirs = Lead::create(['company_id' => $company->id, 'full_name' => 'Not Mine', 'email' => 'nm@theirs.pk', 'assigned_user_id' => $other->id, 'lifecycle_stage' => 'new']);
        $mine = Lead::create(['full_name' => 'Mine', 'email' => 'm@mine.pk', 'assigned_user_id' => $rep->id, 'lifecycle_stage' => 'new']);

        $this->actingAs($rep)->post("/crm/pipeline/{$theirs->id}/stage", ['lifecycle_stage' => 'won'])->assertForbidden();
        $this->assertSame('new', $theirs->fresh()->lifecycle_stage);
        $this->actingAs($rep)->post("/crm/pipeline/{$mine->id}/stage", ['lifecycle_stage' => 'not-a-stage'])->assertSessionHasErrors('lifecycle_stage');
        $this->actingAs($rep)->post("/crm/pipeline/{$mine->id}/stage", ['lifecycle_stage' => 'qualified'])->assertRedirect();
        $this->assertSame('qualified', $mine->fresh()->lifecycle_stage);

        $this->actingAs($rep)->post('/crm/deals', ['company_id' => $company->id, 'owner_id' => $rep->id, 'title' => 'Fake win', 'value' => 99999, 'currency' => 'USD', 'stage' => 'won', 'probability' => 100])->assertForbidden();
        $this->actingAs($rep)->post('/buying-signals', ['company_id' => $company->id, 'signal_type' => 'rfp', 'title' => 'Fake RFP', 'evidence_text' => 'x', 'observed_date' => now()->toDateString(), 'confidence' => 99, 'relevant_service' => 'web_redesign', 'verification_status' => 'verified'])->assertForbidden();
        $this->assertDatabaseMissing('deals', ['title' => 'Fake win']);
        $this->assertDatabaseMissing('buying_signals', ['title' => 'Fake RFP']);
    }

    public function test_references_header_with_several_ancestors_builds_a_valid_message(): void
    {
        $this->assertSame(['a1@x.com', 'b2@x.com', 'c3@x.com'], OutreachMailer::messageIds('<a1@x.com> <b2@x.com>', '<c3@x.com>'));
        $this->assertSame(['a1@x.com'], OutreachMailer::messageIds('a1@x.com', '<a1@x.com>'), 'duplicates collapse');
        $this->assertSame(['c3@x.com'], OutreachMailer::messageIds('', 'c3@x.com'));

        // real Symfony build through the array transport (what used to throw RfcComplianceException on touch 3)
        config(['mail.default' => 'array']);
        $transport = app('mailer')->getSymfonyTransport();
        $id = (new OutreachMailer())->send('prospect@example.com', 'P', 'Re: test', 'body', '<c3@x.com>', '<a1@x.com> <b2@x.com>');
        $this->assertStringContainsString('@', $id);
        $sent = $transport->messages();
        $this->assertCount(1, $sent);
        $refs = $sent->first()->getOriginalMessage()->getHeaders()->get('References')->getBodyAsString();
        $this->assertSame('<a1@x.com> <b2@x.com> <c3@x.com>', $refs);
    }
}
