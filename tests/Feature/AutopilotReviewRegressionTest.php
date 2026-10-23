<?php

namespace Tests\Feature;

use App\Models\ApprovalRequest;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Company;
use App\Models\IcpProfile;
use App\Models\InboundReply;
use App\Models\Lead;
use App\Models\OutreachSetting;
use App\Models\SpendBudget;
use App\Models\Suppression;
use App\Models\User;
use App\Services\Autopilot\ApprovalService;
use App\Services\Autopilot\AutonomyPolicyService;
use App\Services\Autopilot\WorkflowControlService;
use App\Services\Integrations\ProviderHealthService;
use App\Services\Mail\MimeMessage;
use App\Services\Mail\ReplySyncService;
use App\Services\Outreach\OutreachMailer;
use App\Services\Sales\ReplyOutcomeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** One test per finding of the independent v4 code review (each mirrors the reviewer's probe). */
class AutopilotReviewRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $sales;

    protected function setUp(): void
    {
        parent::setUp();
        config(['mail.default' => 'array', 'mail.from.address' => 'ismail@eziline.test',
            'leadpilot.autopilot.sending_hours' => [0, 24], 'leadpilot.autopilot.sending_days' => [1, 2, 3, 4, 5, 6, 7]]);
        $this->admin = User::factory()->create(['role' => 'admin', 'is_active' => true, 'email' => 'admin@eziline.test']);
        $this->sales = User::factory()->create(['role' => 'sales', 'is_active' => true, 'email' => 'rep@eziline.test']);
        OutreachSetting::current()->update(['mode' => 'live', 'emergency_stop' => false, 'require_verified_email' => false, 'approved_services' => array_keys(config('leadpilot.services'))]);
    }

    protected function prospectMails(): int
    {
        return count(array_filter(app('mail.manager')->mailer('array')->getSymfonyTransport()->messages()->all(),
            fn ($m) => !array_intersect(array_map(fn ($a) => $a->getAddress(), $m->getOriginalMessage()->getTo()), ['admin@eziline.test', 'rep@eziline.test'])));
    }

    protected function recipient(string $status, int $step = 1, ?int $parent = null): CampaignRecipient
    {
        $company = Company::firstOrCreate(['name' => 'Smile'], ['domain' => 'smile.test', 'country' => 'Pakistan']);
        $lead = Lead::firstOrCreate(['email' => 'ali@smile.test'], ['company_id' => $company->id, 'full_name' => 'Ali Raza', 'first_name' => 'Ali', 'contact_status' => 'identified',
            'job_title' => 'Owner', 'overall_score' => 85, 'country' => 'Pakistan', 'source_name' => 'OpenStreetMap']);
        $c = Campaign::firstOrCreate(['name' => 'Autopilot — test'], ['service_offered' => 'web_redesign', 'created_by' => $this->admin->id, 'status' => 'running', 'is_autopilot' => true, 'min_lead_score' => 65, 'daily_limit' => 50]);

        return CampaignRecipient::create(['campaign_id' => $c->id, 'lead_id' => $lead->id, 'status' => $status, 'idempotency_key' => uniqid('k', true), 'sequence_step' => $step,
            'parent_recipient_id' => $parent, 'personalized_subject' => 'Hello', 'personalized_body' => 'Hi Ali', 'sent_at' => $status === 'sent' ? now()->subDays(4) : null, 'rfc_message_id' => $status === 'sent' ? uniqid() . '@eziline.test' : null]);
    }

    public function test_1_old_cron_never_sends_autopilot_mail(): void
    {
        $rec = $this->recipient('approved');
        app(WorkflowControlService::class)->pause('outreach', 'test');
        OutreachSetting::current()->update(['mode' => 'dry_run']);
        $this->artisan('leadpilot:dispatch-campaigns')->assertSuccessful();
        $this->assertSame('approved', $rec->fresh()->status);
        $this->assertSame(0, $this->prospectMails());

        // and the manual admin dispatch endpoint refuses pending_approval
        $pending = $this->recipient('pending_approval');
        $this->assertFalse(app(\App\Services\CampaignDeliveryService::class)->dispatchRecipient($pending));
    }

    public function test_2_human_approval_does_not_bypass_pause_or_dry_run(): void
    {
        $rec = $this->recipient('pending_approval');
        $svc = app(ApprovalService::class);
        $req = $svc->request('outreach_message', 'p1', ['title' => 't', 'summary' => 's', 'payload' => ['subject' => 'a', 'body' => 'b']], $rec);
        app(WorkflowControlService::class)->pause('outreach', 'incident');
        $svc->decide($req, $this->admin, 'approve');
        $this->assertSame(0, $this->prospectMails());
        $this->assertSame('approved', $rec->fresh()->status, 'stays approved; sent later when resumed');
        $this->assertStringContainsString('paused', $req->fresh()->execution_result);

        OutreachSetting::current()->update(['mode' => 'dry_run']);
        app(WorkflowControlService::class)->resume('outreach');
        $res = app(\App\Services\Outreach\SequenceEngine::class)->dispatchDue();
        $this->assertSame(0, $res['sent']);
        $this->assertSame(0, $this->prospectMails());

        OutreachSetting::current()->update(['mode' => 'live']);
        $this->travel(2)->hours();
        $this->assertSame(1, app(\App\Services\Outreach\SequenceEngine::class)->dispatchDue()['sent']);

        // meeting/reply/proposal helpers are guarded too
        OutreachSetting::current()->update(['mode' => 'dry_run']);
        $this->expectException(\RuntimeException::class);
        app(OutreachMailer::class)->sendToLead($rec->lead, 'x', 'y');
    }

    public function test_3_cancelled_follow_up_cannot_be_resurrected_by_stale_approval(): void
    {
        $first = $this->recipient('sent');
        $child = $this->recipient('pending_approval', 2, $first->id);
        $req = app(ApprovalService::class)->request('follow_up', "followup:{$child->id}", ['title' => 'fu', 'summary' => 's', 'payload' => ['subject' => 'Re', 'body' => 'b']], $child);
        $reply = InboundReply::create(['lead_id' => $first->lead_id, 'from_email' => 'ali@smile.test', 'to_email' => 'x', 'subject' => 'Re', 'body_text' => 'Sounds good, can we schedule a call next Tuesday?', 'message_id' => 'm1', 'received_at' => now()]);
        app(ReplyOutcomeService::class)->handle($reply);

        $this->assertSame('cancelled', $child->fresh()->status);
        $this->assertSame('cancelled', $req->fresh()->status, 'pending approval cancelled with the message');
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(ApprovalService::class)->decide($req->fresh(), $this->admin, 'approve');
    }

    public function test_3b_executor_refuses_non_pending_message(): void
    {
        $rec = $this->recipient('pending_approval');
        $req = app(ApprovalService::class)->request('outreach_message', 'x3', ['title' => 't', 'summary' => 's'], $rec);
        $rec->update(['status' => 'cancelled']);
        app(ApprovalService::class)->decide($req, $this->admin, 'approve');
        $this->assertSame('failed', $req->fresh()->status);
        $this->assertSame(0, $this->prospectMails());
    }

    public function test_4_plus_address_one_click_unsubscribe(): void
    {
        $email = 'john+sales@acme.test';
        $this->post('/unsubscribe?' . http_build_query(['email' => $email, 'token' => OutreachMailer::unsubscribeToken($email)]))->assertOk();
        $this->assertTrue(Suppression::isSuppressed($email));
        $this->get(OutreachMailer::unsubscribeUrl('mary+x@acme.test'))->assertOk();
        $this->assertTrue(Suppression::isSuppressed('mary+x@acme.test'));
    }

    public function test_5_spoofed_bounce_and_domain_wide_suppression_are_prevented(): void
    {
        $rec = $this->recipient('sent');
        $boss = Lead::create(['full_name' => 'Boss', 'email' => 'boss@smile.test', 'contact_status' => 'identified']);
        $spoof = MimeMessage::parse("From: \"Postmaster fan\" <attacker@evil.test>\r\nSubject: x\r\nMessage-ID: <s1@evil.test>\r\n\r\nFinal-Recipient: rfc822; ali@smile.test\r\nStatus: 5.1.1\r\n");
        $this->assertFalse($spoof->isBounce);
        $svc = new ReplySyncService();
        $this->assertSame('ignored', $svc->ingest($spoof));

        // a real-looking DSN that does not reference a message WE sent is ignored
        $fakeDsn = MimeMessage::parse("From: mailer-daemon@evil.test\r\nContent-Type: multipart/report; report-type=delivery-status; boundary=z\r\nMessage-ID: <d1@evil.test>\r\n\r\n--z\r\nContent-Type: message/delivery-status\r\n\r\nFinal-Recipient: rfc822; ali@smile.test\r\nStatus: 5.1.1\r\n--z--\r\n");
        $this->assertSame('ignored', $svc->ingest($fakeDsn));
        $this->assertFalse(Suppression::isSuppressed('ali@smile.test'));

        // one person unsubscribing does not suppress colleagues or every gmail user
        Suppression::addSuppression('ali@smile.test', 'unsubscribe', 'test');
        Suppression::addSuppression('someone@gmail.com', 'unsubscribe', 'test');
        $this->assertFalse(Suppression::isSuppressed('boss@smile.test', 'smile.test'));
        $this->assertFalse(Suppression::isSuppressed('other@gmail.com', 'gmail.com'));
        Suppression::addDomainSuppression('competitor.test');
        $this->assertTrue(Suppression::isSuppressed('anyone@competitor.test', 'competitor.test'));

        // forged sender-only reply (auth failed) is ignored
        $forged = MimeMessage::parse("From: boss@smile.test\r\nAuthentication-Results: mx.google.com; dkim=fail; spf=fail\r\nMessage-ID: <f1@x>\r\nSubject: Re\r\n\r\nPlease remove me");
        CampaignRecipient::create(['campaign_id' => $rec->campaign_id, 'lead_id' => $boss->id, 'status' => 'sent', 'idempotency_key' => 'bb', 'sent_at' => now()->subDay()]);
        $this->assertSame('ignored', $svc->ingest($forged));
        $this->assertFalse(Suppression::isSuppressed('boss@smile.test'));
    }

    public function test_6_secrets_are_redacted_from_provider_errors(): void
    {
        config(['leadpilot.keys.gemini' => 'AIzaSySECRETSECRETSECRET123456']);
        app(ProviderHealthService::class)->failure('gemini', 'cURL error 6 for https://x/models?key=AIzaSySECRETSECRETSECRET123456&alt=json Bearer sk-abcdefghijk');
        $err = \App\Models\ProviderHealth::where('provider', 'gemini')->value('last_error');
        $this->assertStringNotContainsString('SECRETSECRET', $err);
        $this->assertStringNotContainsString('sk-abcdefghijk', $err);
        $this->actingAs($this->sales)->get(route('autopilot.index'))->assertForbidden();
    }

    public function test_7_spend_policy_and_spend_approval_actually_enable_the_search(): void
    {
        config(['leadpilot.sources.google_places.api_key' => 'k']);
        Http::fake(['places.googleapis.com/*' => Http::response(['places' => [['id' => 'gp1', 'displayName' => ['text' => 'Paid Clinic'], 'businessStatus' => 'OPERATIONAL']]], 200), '*' => Http::response('', 404)]);
        $icp = IcpProfile::create(['name' => 'Paid', 'cities' => ['Lahore'], 'countries' => ['Pakistan'], 'niches' => ['dentist'], 'sources' => ['google_places'], 'is_active' => true, 'website_requirement' => 'any', 'max_results_per_run' => 5]);
        $hunter = app(\App\Services\Hunting\LeadHuntService::class);

        // default spend.over_budget = prohibited -> no approval nagging with $0 budget
        $hunter->hunt($icp, null, false, 0, 'google_places');
        $this->assertSame(0, ApprovalRequest::count());

        app(AutonomyPolicyService::class)->set('spend.over_budget', 'approval');
        $hunter->hunt($icp, null, false, 0, 'google_places');
        $req = ApprovalRequest::where('type', 'spend_over_budget')->firstOrFail();
        app(ApprovalService::class)->decide($req, $this->admin, 'approve');
        $this->assertSame('executed', $req->fresh()->status, (string) $req->fresh()->execution_result);
        $this->assertTrue(SpendBudget::where('scope', 'global')->where('period', 'daily')->exists());
        $this->assertDatabaseHas('companies', ['name' => 'Paid Clinic']); // the approved search actually ran
    }

    public function test_8_9_sales_rep_sees_only_own_types_and_cannot_pause(): void
    {
        $svc = app(ApprovalService::class);
        foreach (range(1, 3) as $i) {
            $svc->request('integration_enable', "int{$i}", ['title' => "admin-only {$i}", 'summary' => 's', 'payload' => ['provider' => 'hunter']]);
        }
        $rec = $this->recipient('pending_approval');
        $mine = $svc->request('outreach_message', 'mine', ['title' => 'rep item', 'summary' => 's'], $rec);
        $this->actingAs($this->sales)->get(route('approvals.index'))->assertOk()->assertSee('rep item')->assertDontSee('admin-only 1');

        $this->actingAs($this->sales)->post(route('approvals.decide', $mine), ['action' => 'pause'])->assertForbidden();
        $this->assertFalse(app(WorkflowControlService::class)->isPaused('outreach'));
    }

    public function test_10_imap_limits_auto_replies_and_null_category(): void
    {
        $t = new \App\Services\Mail\SocketImapTransport();
        try {
            $t->read(2147483647);
            $this->fail('oversized literal accepted');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('too large', $e->getMessage());
        }

        $rec = $this->recipient('sent');
        $auto = MimeMessage::parse("From: ali@smile.test\r\nAuto-Submitted: auto-replied\r\nIn-Reply-To: <{$rec->rfc_message_id}>\r\nMessage-ID: <a1@smile.test>\r\nSubject: Automatic reply\r\n\r\nThanks, I am interested in hearing more when I'm back.");
        (new ReplySyncService())->ingest($auto);
        $this->assertSame('out_of_office', InboundReply::where('message_id', 'a1@smile.test')->value('category'), 'auto-replies are never a human answer');
        $this->assertNotSame('interested', $rec->lead->fresh()->lifecycle_stage);

        // a reply whose category is still NULL must stop follow-ups
        InboundReply::create(['lead_id' => $rec->lead_id, 'from_email' => 'ali@smile.test', 'to_email' => 'x', 'subject' => 'Re', 'body_text' => '?', 'category' => null, 'received_at' => now()]);
        $rec->lead->update(['last_contacted_at' => now()->subDays(5)]);
        $this->assertSame(0, app(\App\Services\Outreach\SequenceEngine::class)->planFollowUps()['created']);
    }
}
