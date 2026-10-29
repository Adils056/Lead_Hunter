<?php

namespace Tests\Feature;

use App\Models\ApprovalRequest;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Company;
use App\Models\InboundReply;
use App\Models\Lead;
use App\Models\User;
use App\Services\Autopilot\ApprovalService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** SQA report 29 Oct 2026, re-audit: the parts of bugs 5 and 7 that were still open. */
class SqaRoundFiveTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['mail.default' => 'array', 'leadpilot.autopilot.sending_hours' => [9, 17], 'leadpilot.autopilot.sending_days' => [1, 2, 3, 4, 5]]);
        $this->admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
    }

    protected function ukLead(string $name): Lead
    {
        $c = Company::create(['name' => $name, 'domain' => strtolower($name) . '.example', 'country' => 'United Kingdom', 'city' => 'London']);

        return Lead::create(['company_id' => $c->id, 'first_name' => 'Sam', 'last_name' => 'Hill', 'full_name' => 'Sam Hill', 'email' => 'sam@' . $c->domain,
            'contact_status' => 'identified', 'email_verification_status' => 'verified', 'country' => 'United Kingdom']);
    }

    public function test_bug_5_manual_campaigns_wait_for_the_prospects_hours_and_queued_mail_is_not_stranded(): void
    {
        $this->travelTo(Carbon::parse('2026-10-27 10:00', 'Asia/Karachi')); // Tuesday, 05:00 in London
        $camp = Campaign::create(['name' => 'Manual', 'service_offered' => 'web_redesign', 'created_by' => $this->admin->id, 'status' => 'running', 'daily_limit' => 10]);
        $night = CampaignRecipient::create(['campaign_id' => $camp->id, 'lead_id' => $this->ukLead('Night')->id, 'status' => 'approved', 'idempotency_key' => 'm1',
            'sequence_step' => 1, 'personalized_subject' => 'Hi', 'personalized_body' => 'Body']);

        $this->artisan('leadpilot:dispatch-campaigns')->assertSuccessful();
        $this->assertSame('approved', $night->fresh()->status, 'not sent at 05:00 London time');
        $this->assertNull($night->fresh()->sent_at);

        // 11:00 London: a quota-queued first email to a company emailed two days ago is picked up and held for the company gap
        $this->travelTo(Carbon::parse('2026-10-27 11:00', 'Europe/London'));
        $lead = $this->ukLead('Gap');
        $other = Lead::create(['company_id' => $lead->company_id, 'full_name' => 'Info', 'email' => 'info@gap.example', 'contact_status' => 'generic_inbox']);
        CampaignRecipient::create(['campaign_id' => $camp->id, 'lead_id' => $other->id, 'status' => 'sent', 'idempotency_key' => 'm2', 'sequence_step' => 1, 'sent_at' => now()->subDays(2)]);
        $queued = CampaignRecipient::create(['campaign_id' => $camp->id, 'lead_id' => $lead->id, 'status' => 'queued', 'idempotency_key' => 'm3',
            'sequence_step' => 1, 'personalized_subject' => 'Hi', 'personalized_body' => 'Body']);
        $night->update(['status' => 'cancelled']);

        $this->artisan('leadpilot:dispatch-campaigns')->assertSuccessful();
        $this->assertNull($queued->fresh()->sent_at);
        $this->assertTrue($queued->fresh()->scheduled_at->gt(now()->addDays(7)), 'one conversation per company: waits for the 10-day gap');
    }

    public function test_bug_7_a_reminder_replaces_the_bell_entry_and_approving_uncertain_reads_the_reply_again(): void
    {
        $lead = $this->ukLead('Sidekick');
        $reply = InboundReply::create(['lead_id' => $lead->id, 'from_email' => $lead->email, 'to_email' => 'me@x', 'subject' => 'Re',
            'body_text' => "Hi Ismail, We're not working with external recruiters, staffing agencies, or contract teams for these roles. Eric",
            'category' => 'uncertain', 'message_id' => 'r1', 'received_at' => now()]);
        $svc = app(ApprovalService::class);
        $req = $svc->request('low_confidence_reply', "reply-classify:{$reply->id}", ['title' => 'Classify a prospect reply: Sidekick', 'summary' => 'uncertain, 50%',
            'priority' => 'high', 'payload' => ['suggested_category' => 'uncertain', 'category' => 'uncertain'], 'options' => ['approve', 'edit_approve', 'reject']], $reply);

        $this->travel(13)->hours();
        $svc->processOverdue();
        $this->travel(13)->hours();
        $svc->processOverdue();
        $this->assertSame(1, $this->admin->fresh()->unreadNotifications()->count(), 'one reply = one entry in the bell, however many reminders');

        $svc->decide($req, $this->admin, 'approve');
        $this->assertSame('executed', $req->fresh()->status);
        $this->assertSame('not_interested', $reply->fresh()->category, 'approved "uncertain" is read again, not left unlabelled');
        $this->assertSame(0, $this->admin->fresh()->unreadNotifications()->count(), 'decided: the bell stops asking');
        $this->assertSame(1, ApprovalRequest::count());
    }
}
