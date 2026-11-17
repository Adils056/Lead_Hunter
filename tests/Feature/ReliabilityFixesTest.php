<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Company;
use App\Models\InboundReply;
use App\Models\Lead;
use App\Models\NotificationDelivery;
use App\Models\Task;
use App\Models\User;
use App\Services\Mail\MimeMessage;
use App\Services\Sales\ReplyClassifier;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Reliability review, 17 Nov 2026: defects found in the code and in the live system's numbers. */
class ReliabilityFixesTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['mail.default' => 'array', 'leadpilot.autopilot.sending_hours' => [0, 24], 'leadpilot.autopilot.sending_days' => [1, 2, 3, 4, 5, 6, 7]]);
        $this->admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
    }

    protected function lead(string $name, string $country = 'United Kingdom'): Lead
    {
        $c = Company::create(['name' => $name, 'domain' => strtolower(str_replace(' ', '', $name)) . '.example', 'country' => $country, 'city' => 'London']);

        return Lead::create(['company_id' => $c->id, 'first_name' => 'Sam', 'last_name' => 'Hill', 'full_name' => 'Sam Hill', 'email' => 'sam@' . $c->domain,
            'contact_status' => 'identified', 'email_verification_status' => 'verified', 'country' => $country]);
    }

    public function test_a_person_who_mentions_being_away_is_not_filed_as_an_out_of_office_notice(): void
    {
        $c = app(ReplyClassifier::class);
        $this->assertSame('interested', $c->classify('Yes, interested! I am on leave this week, book a call when I am back in the office')['category']);
        $this->assertSame('not_interested', $c->classify('Not interested, I am back in the office Monday but please stop')['category']);
        $this->assertSame('request_info', $c->classify('Please send pricing, I have limited access to my email')['category']);
        // a real notice still is one
        $this->assertSame('out_of_office', $c->classify('I am out of the office until 24 November with limited access to email.')['category']);
    }

    public function test_a_reply_in_a_charset_php_does_not_know_is_still_read(): void
    {
        $raw = "From: Kim <kim@korea.example>\r\nSubject: Re: website\r\nMessage-ID: <k1@korea.example>\r\nContent-Type: text/plain; charset=ks_c_5601-1987\r\n\r\nYes please send the price\r\n";
        $m = MimeMessage::parse($raw);
        $this->assertStringContainsString('send the price', $m->text);
    }

    public function test_a_campaign_that_hit_its_limit_yesterday_sends_again_today(): void
    {
        $camp = Campaign::create(['name' => 'Manual', 'service_offered' => 'web_redesign', 'created_by' => $this->admin->id, 'status' => 'running', 'daily_limit' => 10]);
        $camp->forceFill(['sent_today' => 10, 'last_sent_date' => now()->subDay()->toDateString()])->save();
        $rec = CampaignRecipient::create(['campaign_id' => $camp->id, 'lead_id' => $this->lead('Acme')->id, 'status' => 'approved', 'idempotency_key' => 'c1',
            'sequence_step' => 1, 'personalized_subject' => 'Hi', 'personalized_body' => 'Body']);

        $this->artisan('leadpilot:dispatch-campaigns')->expectsOutputToContain('Recipient #' . $rec->id)->assertSuccessful();
        $this->assertNotNull($rec->fresh()->error_message, 'the recipient is picked up on a new day (the limit used to stay at 0); here the gate holds it in dry-run');
    }

    public function test_messages_lost_to_a_passing_fault_get_one_more_try(): void
    {
        $camp = Campaign::create(['name' => 'Auto', 'service_offered' => 'web_redesign', 'created_by' => $this->admin->id, 'status' => 'running', 'is_autopilot' => true]);
        $timeout = CampaignRecipient::create(['campaign_id' => $camp->id, 'lead_id' => $this->lead('Timeout Co')->id, 'status' => 'failed', 'idempotency_key' => 'r1',
            'sequence_step' => 1, 'error_message' => 'Connection to "ssl://mail.eziline.net:465" timed out.']);
        $rejected = CampaignRecipient::create(['campaign_id' => $camp->id, 'lead_id' => $this->lead('Rejected Co')->id, 'status' => 'failed', 'idempotency_key' => 'r2',
            'sequence_step' => 1, 'error_message' => 'Expected response code "250" but got code "550", with message "550 mailbox unavailable"']);

        $this->artisan('leadpilot:retry-failed')->assertSuccessful();
        $this->assertSame('approved', $timeout->fresh()->status);
        $this->assertStringStartsWith('[retried] ', $timeout->fresh()->error_message);
        $this->assertSame('failed', $rejected->fresh()->status, 'a refused address is not retried');

        // once only
        $timeout->fresh()->update(['status' => 'failed']);
        $this->artisan('leadpilot:retry-failed')->assertSuccessful();
        $this->assertSame('failed', $timeout->fresh()->status);
    }

    public function test_the_morning_digest_lists_who_is_waiting_for_an_answer(): void
    {
        $kre8 = $this->lead('Kre8tive Nails');
        InboundReply::create(['lead_id' => $kre8->id, 'from_email' => $kre8->email, 'to_email' => 'us@x.example', 'subject' => 'Re: hi', 'body_text' => 'Interested!',
            'category' => 'interested', 'message_id' => 'k1', 'received_at' => now()->subDays(11)]);
        $school = $this->lead('New Crescent');
        Task::create(['lead_id' => $school->id, 'company_id' => $school->company_id, 'assigned_to' => $this->admin->id, 'title' => 'Replied on WhatsApp: answer now',
            'status' => 'pending', 'priority' => 'high', 'due_date' => now()->subDays(13)->toDateString()]);

        $this->artisan('leadpilot:waiting-digest')->expectsOutputToContain('2 prospects are waiting for your answer')
            ->expectsOutputToContain('Kre8tive Nails: replied by e-mail (interested)')->assertSuccessful();
        $this->assertSame(1, NotificationDelivery::where('dedupe_key', 'waiting-digest:' . now()->format('Ymd'))->where('channel', 'database')->count());

        // answering by e-mail takes Kre8tive Nails off the list
        \App\Models\EmailLog::create(['lead_id' => $kre8->id, 'direction' => 'outbound', 'provider' => 'smtp', 'message_id' => 'a1', 'sender_email' => 'us@x.example',
            'recipient_email' => $kre8->email, 'subject' => 'Re', 'status' => 'sent', 'occurred_at' => now()]);
        Task::query()->update(['status' => 'completed']);
        $this->artisan('leadpilot:waiting-digest')->expectsOutputToContain('Nobody is waiting')->assertSuccessful();
    }
}
