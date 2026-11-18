<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Company;
use App\Models\InboundReply;
use App\Models\Lead;
use App\Models\NotificationDelivery;
use App\Models\OutreachSetting;
use App\Models\Task;
use App\Models\User;
use App\Services\Outreach\SequenceEngine;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 18 Nov 2026: new businesses keep getting first e-mails while follow-ups run, and unanswered replies are escalated. */
class ConversionSpeedTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Campaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();
        config(['mail.default' => 'array', 'leadpilot.timezone' => 'Asia/Karachi', 'leadpilot.autopilot.sending_hours' => [0, 24], 'leadpilot.autopilot.sending_days' => [1, 2, 3, 4, 5, 6, 7]]);
        $this->admin = User::factory()->create(['role' => 'admin', 'is_active' => true, 'email' => 'admin@eziline.test']);
        $this->campaign = Campaign::create(['name' => 'Autopilot — General', 'service_offered' => 'web_redesign', 'created_by' => $this->admin->id,
            'status' => 'running', 'daily_limit' => 25, 'is_autopilot' => true]);
        OutreachSetting::current()->update(['max_daily_sends' => 25, 'activated_at' => now()->subMonth()]);
    }

    protected function lead(string $name): Lead
    {
        $c = Company::create(['name' => $name, 'domain' => strtolower(str_replace(' ', '', $name)) . '.example', 'country' => 'Pakistan', 'city' => 'Lahore']);

        return Lead::create(['company_id' => $c->id, 'first_name' => 'Sam', 'last_name' => 'Hill', 'full_name' => 'Sam Hill', 'email' => 'sam@' . $c->domain,
            'contact_status' => 'identified', 'email_verification_status' => 'verified', 'country' => 'Pakistan']);
    }

    protected function queue(int $step, int $n, string $status = 'approved', ?Carbon $sentAt = null): void
    {
        foreach (range(1, $n) as $i) {
            $lead = $this->lead("Step{$step} {$status} {$i}");
            CampaignRecipient::create(['campaign_id' => $this->campaign->id, 'lead_id' => $lead->id, 'sequence_step' => $step, 'status' => $status,
                'idempotency_key' => "t_{$step}_{$status}_{$i}", 'scheduled_at' => now()->subHour(), 'sent_at' => $sentAt]);
        }
    }

    public function test_follow_ups_leave_room_for_first_emails_to_new_businesses(): void
    {
        $this->travelTo(Carbon::parse('2026-11-18 10:00', 'Asia/Karachi'));
        $this->queue(2, 30);
        $this->queue(1, 30);
        $engine = app(SequenceEngine::class);

        // before the fix the 30 follow-ups came first and filled the 25-a-day limit; now 15 (60%), then first e-mails
        $q = $engine->dueQueue(20);
        $this->assertSame(15, $q->where('sequence_step', 2)->count());
        $this->assertSame(1, $q[15]->sequence_step, 'first e-mails come right after the follow-ups share');

        // once 15 follow-ups went out today, only first e-mails are tried until the afternoon
        $this->queue(2, 15, 'sent', now()->subMinutes(30));
        $this->assertSame(0, $engine->dueQueue(20)->where('sequence_step', 2)->count());

        // in the afternoon the unused reserve is freed, so no daily send is wasted
        $this->travelTo(Carbon::parse('2026-11-18 15:30', 'Asia/Karachi'));
        $this->assertSame(30, $engine->dueQueue(20)->where('sequence_step', 2)->count());

        // and when no new business is waiting, follow-ups use the whole limit at any hour
        $this->travelTo(Carbon::parse('2026-11-18 10:00', 'Asia/Karachi'));
        CampaignRecipient::where('sequence_step', 1)->update(['status' => 'cancelled']);
        $this->assertSame(30, $engine->dueQueue(20)->where('sequence_step', 2)->count());
    }

    public function test_no_new_first_email_drafts_while_three_days_of_them_wait_to_be_sent(): void
    {
        $backlog = OutreachSetting::current()->firstTouchCap() * 3;
        $this->queue(1, $backlog);
        $r = app(SequenceEngine::class)->planFirstTouches(5);
        $this->assertSame(0, $r['planned'] + $r['pending_approval']);
        $this->assertStringContainsString("{$backlog} first e-mails are already waiting", implode(' ', $r['skipped']));
    }

    public function test_a_reply_left_24_hours_without_an_answer_is_escalated_once_to_the_ceo(): void
    {
        $ceo = User::factory()->create(['role' => 'sales', 'is_active' => true, 'email' => 'ceo@eziline.test', 'name' => 'CEO']);
        config(['leadpilot.alerts.escalate_to' => 'ceo@eziline.test']);
        $kre8 = $this->lead('Kre8tive Nails');
        $fresh = $this->lead('Fresh Reply');
        $opened = $this->lead('Report Reader');
        InboundReply::create(['lead_id' => $kre8->id, 'from_email' => $kre8->email, 'to_email' => 'us@x', 'subject' => 'Re', 'body_text' => 'Yes, interested',
            'category' => 'interested', 'received_at' => now()->subHours(30)]);
        InboundReply::create(['lead_id' => $fresh->id, 'from_email' => $fresh->email, 'to_email' => 'us@x', 'subject' => 'Re', 'body_text' => 'Send details',
            'category' => 'request_info', 'received_at' => now()->subHours(2)]);
        Task::create(['lead_id' => $opened->id, 'title' => 'Opened their website report: follow up today', 'status' => 'pending', 'due_date' => now()->subDays(3)->toDateString(),
            'assigned_to' => $this->admin->id, 'priority' => 'high']);
        Task::query()->update(['created_at' => now()->subDays(3)]);

        // only the reply older than 24 hours; a fresh reply and a report opening are not escalated
        $this->artisan('leadpilot:escalate-waiting')->expectsOutputToContain('1 prospect has waited over 24 hours')
            ->expectsOutputToContain('Kre8tive Nails: replied by e-mail (interested)')->expectsOutputToContain('Escalated to CEO')->assertSuccessful();
        $this->assertSame(1, NotificationDelivery::where('user_id', $ceo->id)->where('channel', 'database')->count());
        $this->assertSame(0, NotificationDelivery::where('user_id', $this->admin->id)->count(), 'goes to the person named, not every admin');

        // once only
        $this->artisan('leadpilot:escalate-waiting')->expectsOutputToContain('No reply has waited more than 24 hours')->assertSuccessful();

        // the fresh reply crosses the line a day later and is escalated on its own
        $this->travel(23)->hours();
        $this->artisan('leadpilot:escalate-waiting')->expectsOutputToContain('Fresh Reply: replied by e-mail (request info)')->assertSuccessful();
        $this->assertSame(2, NotificationDelivery::where('channel', 'escalation')->count());
    }

    public function test_escalation_goes_to_the_admins_when_nobody_is_named(): void
    {
        $lead = $this->lead('Sinag Dental');
        Task::create(['lead_id' => $lead->id, 'title' => 'Replied on WhatsApp: answer now', 'status' => 'pending', 'due_date' => now()->toDateString(),
            'assigned_to' => $this->admin->id, 'priority' => 'high']);
        Task::query()->update(['created_at' => now()->subDays(2)]);

        $this->artisan('leadpilot:escalate-waiting', ['--dry-run' => true])->expectsOutputToContain('Sinag Dental: replied on whatsapp')->assertSuccessful();
        $this->assertSame(0, NotificationDelivery::count(), 'a dry run sends nothing');
        $this->artisan('leadpilot:escalate-waiting')->expectsOutputToContain('Escalated to ' . $this->admin->name)->assertSuccessful();
    }
}
