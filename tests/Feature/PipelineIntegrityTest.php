<?php

namespace Tests\Feature;

use App\Models\ApprovalRequest;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Company;
use App\Models\Deal;
use App\Models\EmailTemplate;
use App\Models\InboundReply;
use App\Models\Lead;
use App\Models\Meeting;
use App\Models\OutreachSetting;
use App\Models\User;
use App\Services\Autopilot\ApprovalService;
use App\Services\CampaignDeliveryService;
use App\Services\Sales\ReplyClassifier;
use App\Services\Sales\ReplyOutcomeService;
use App\Support\OutreachMetrics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Audit fixes (medium): no cold e-mail to people in a conversation, stages never go backwards, honest metrics. */
class PipelineIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        OutreachSetting::current()->update(['mode' => 'live', 'emergency_stop' => false]);
    }

    protected function campaign(): Campaign
    {
        $t = EmailTemplate::create(['name' => 'T', 'slug' => 't-' . uniqid(), 'service_category' => 'laravel_php', 'subject' => 'Hi {{first_name}}', 'body_html' => '<p>Hi</p>', 'body_text' => 'Hi', 'is_active' => true]);

        return Campaign::create(['name' => 'Manual', 'service_offered' => 'Laravel and PHP development', 'min_lead_score' => 50, 'email_template_id' => $t->id,
            'daily_limit' => 10, 'require_approval' => true, 'status' => 'running', 'created_by' => $this->admin->id, 'approved_by' => $this->admin->id]);
    }

    protected function lead(string $stage, int $i): Lead
    {
        return Lead::create(['full_name' => "Lead {$i}", 'first_name' => "Lead{$i}", 'email' => "lead{$i}@example{$i}.com", 'email_verification_status' => 'verified',
            'country' => 'United Kingdom', 'overall_score' => 80, 'lifecycle_stage' => $stage]);
    }

    public function test_campaigns_never_cold_email_clients_or_people_in_a_conversation(): void
    {
        $fresh = $this->lead('qualified', 1);
        $won = $this->lead('won', 2);
        $talking = $this->lead('interested', 3);
        $c = $this->campaign();

        $this->assertSame(1, app(CampaignDeliveryService::class)->buildCampaignDrafts($c), 'only the fresh lead gets a draft');
        $this->assertSame([$fresh->id], $c->recipients()->pluck('lead_id')->all());

        // a recipient that already existed for a lead who has since replied with interest is not sent
        $rec = CampaignRecipient::create(['campaign_id' => $c->id, 'lead_id' => $talking->id, 'status' => 'approved', 'idempotency_key' => 'k', 'personalized_subject' => 'Hi', 'personalized_body' => 'Hi']);
        $this->assertFalse(app(CampaignDeliveryService::class)->dispatchRecipient($rec));
        $this->assertStringContainsString('cold e-mail stops here', (string) $rec->fresh()->error_message);
        $this->assertSame('interested', $talking->fresh()->lifecycle_stage, 'the rejection does not move the lead');
        $this->assertSame('won', $won->fresh()->lifecycle_stage);
        Mail::assertNothingSent();
    }

    public function test_a_lead_stage_never_moves_backwards(): void
    {
        $won = $this->lead('won', 1);
        $this->assertFalse($won->moveStage('replied'));
        $this->assertFalse($won->moveStage('lost'), 'a client is never closed as lost by a reply');
        $proposal = $this->lead('proposal_sent', 2);
        $this->assertFalse($proposal->moveStage('interested'));
        $this->assertTrue($proposal->moveStage('lost'), 'closing still applies');
        $contacted = $this->lead('contacted', 3);
        $this->assertTrue($contacted->moveStage('interested'));
        $this->assertFalse($this->lead('lost', 4)->moveStage('ready_for_review'), 'a send attempt does not re-open a closed lead');

        // a confident reply from a client keeps the client a client
        $reply = InboundReply::create(['lead_id' => $won->id, 'from_email' => $won->email, 'to_email' => 'leads@eziline.test', 'subject' => 'Re: hi',
            'body_text' => 'Thanks, all good.', 'message_id' => 'm-won', 'received_at' => now()]);
        app(ReplyOutcomeService::class)->apply($reply, 'request_info', 100, 'test');
        $this->assertSame('won', $won->fresh()->lifecycle_stage);
    }

    public function test_an_unconfirmed_ai_guess_is_not_counted_as_a_positive_reply(): void
    {
        $c = $this->campaign();
        $lead = $this->lead('contacted', 1);
        CampaignRecipient::create(['campaign_id' => $c->id, 'lead_id' => $lead->id, 'status' => 'sent', 'sent_at' => now()->subDay(), 'idempotency_key' => 's',
            'personalized_subject' => 'Hi', 'personalized_body' => 'Hi']);
        $this->mock(ReplyClassifier::class, fn ($m) => $m->shouldReceive('classify')->andReturn(['category' => 'interested', 'confidence' => 60, 'reasoning' => 'maybe', 'subtype' => null]));
        $reply = InboundReply::create(['lead_id' => $lead->id, 'from_email' => $lead->email, 'to_email' => 'leads@eziline.test', 'subject' => 'Re: Hi',
            'body_text' => 'Hmm, maybe later?', 'message_id' => 'm1', 'received_at' => now()]);

        app(ReplyOutcomeService::class)->handle($reply);

        $this->assertSame('uncertain', $reply->fresh()->category, 'stored as a guess-in-review, not as "interested"');
        $this->assertSame(1, OutreachMetrics::summary()['replied']);
        $this->assertSame(0, OutreachMetrics::summary()['positive'], 'not positive before a human confirms');
        $this->assertSame('contacted', $lead->fresh()->lifecycle_stage);

        $req = ApprovalRequest::where('type', 'low_confidence_reply')->firstOrFail();
        app(ApprovalService::class)->decide($req, $this->admin, 'approve');
        $this->assertSame('interested', $reply->fresh()->category);
        $this->assertSame(1, OutreachMetrics::summary()['positive'], 'counted once confirmed');
    }

    public function test_dashboard_crm_numbers_follow_the_selected_period(): void
    {
        $company = Company::create(['name' => 'Co', 'domain' => 'co.example']);
        $lead = $this->lead('meeting_booked', 1);
        $lead->update(['company_id' => $company->id]);
        $old = Meeting::create(['lead_id' => $lead->id, 'host_user_id' => $this->admin->id, 'title' => 'Old', 'scheduled_start' => now()->subDays(60), 'status' => 'completed']);
        Meeting::whereKey($old->id)->update(['created_at' => now()->subDays(60)]);
        Meeting::create(['lead_id' => $lead->id, 'host_user_id' => $this->admin->id, 'title' => 'New', 'scheduled_start' => now()->addDay(), 'status' => 'scheduled']);
        Meeting::create(['lead_id' => $lead->id, 'host_user_id' => $this->admin->id, 'title' => 'Off', 'scheduled_start' => now()->addDay(), 'status' => 'cancelled']);
        Deal::create(['lead_id' => $lead->id, 'company_id' => $company->id, 'owner_id' => $this->admin->id, 'title' => 'Old win', 'value' => 900, 'stage' => 'won', 'closed_at' => now()->subDays(60)]);

        $this->actingAs($this->admin)->get(route('dashboard', ['range' => 30]))->assertOk()
            ->assertViewHas('meetingsBooked', 1)
            ->assertViewHas('dealsWon', 0)
            ->assertViewHas('leadToMeetingRate', fn ($r) => $r <= 100);
        $this->actingAs($this->admin)->get(route('dashboard', ['range' => 'all']))->assertOk()
            ->assertViewHas('meetingsBooked', 2)
            ->assertViewHas('dealsWon', 1);
    }

    public function test_dry_run_holds_approved_mail_instead_of_dropping_it(): void
    {
        $c = $this->campaign();
        $rec = CampaignRecipient::create(['campaign_id' => $c->id, 'lead_id' => $this->lead('qualified', 1)->id, 'status' => 'approved', 'idempotency_key' => 'h',
            'personalized_subject' => 'Hi', 'personalized_body' => 'Hi']);
        $svc = app(CampaignDeliveryService::class);

        OutreachSetting::current()->update(['mode' => 'dry_run']);
        $this->assertFalse($svc->dispatchRecipient($rec));
        $this->assertSame('approved', $rec->fresh()->status, 'kept, not skipped');
        $this->assertStringStartsWith('Held:', (string) $rec->fresh()->error_message);

        OutreachSetting::current()->update(['mode' => 'live']);
        $this->assertTrue($svc->dispatchRecipient($rec->fresh()), 'sent once live again');
    }
}
