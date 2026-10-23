<?php

namespace Tests\Feature;

use App\Models\ApprovalRequest;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Company;
use App\Models\Incident;
use App\Models\InboundReply;
use App\Models\Lead;
use App\Models\Meeting;
use App\Models\NotificationDelivery;
use App\Models\OutreachSetting;
use App\Models\ProviderHealth;
use App\Models\SpendBudget;
use App\Models\Suppression;
use App\Models\SystemHeartbeat;
use App\Models\User;
use App\Services\Autopilot\ApprovalService;
use App\Services\Autopilot\AutonomyPolicyService;
use App\Services\Autopilot\HealthService;
use App\Services\Autopilot\IncidentService;
use App\Services\Autopilot\LeadStateService;
use App\Services\Autopilot\WorkflowControlService;
use App\Services\Integrations\BudgetGuard;
use App\Services\Integrations\CostLedger;
use App\Services\Integrations\ProviderHealthService;
use App\Services\Integrations\ProviderRegistry;
use App\Services\Outreach\OutreachMailer;
use App\Services\Sales\MeetingSlotService;
use App\Services\Sales\ProposalDraftService;
use App\Services\Sales\ReplyOutcomeService;
use App\Support\ScoreBands;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AutopilotCoreTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $sales;

    protected function setUp(): void
    {
        parent::setUp();
        config(['mail.default' => 'array', 'mail.from.address' => 'ismail@eziline.test', 'leadpilot.timezone' => 'Asia/Karachi']);
        $this->admin = User::factory()->create(['role' => 'admin', 'is_active' => true, 'email' => 'admin@eziline.test']);
        $this->sales = User::factory()->create(['role' => 'sales', 'is_active' => true, 'email' => 'sales@eziline.test']);
    }

    /** Emails sent to prospects (excludes internal notification emails to our own users). */
    protected function sentMessages(): array
    {
        $internal = User::pluck('email')->map(fn ($e) => strtolower($e))->all();

        return array_values(array_filter(app('mail.manager')->mailer('array')->getSymfonyTransport()->messages()->all(),
            fn ($m) => !array_intersect(array_map(fn ($a) => strtolower($a->getAddress()), $m->getOriginalMessage()->getTo()), $internal)));
    }

    // ---------------------------------------------------------------- thresholds

    public function test_score_thresholds_come_from_one_place_everywhere(): void
    {
        $t = ScoreBands::thresholds();
        $this->assertSame([80, 65, 45], [$t['high'], $t['qualified'], $t['nurture']]);
        $this->assertSame('qualified', ScoreBands::band(65));
        $this->assertSame('nurture', ScoreBands::band(64));
        Lead::create(['full_name' => 'A', 'overall_score' => 66]);
        Lead::create(['full_name' => 'B', 'overall_score' => 61]);
        $this->assertSame(1, Lead::qualified()->count(), 'scopeQualified used 60 before v4');
        $this->assertSame('Qualified', Lead::where('full_name', 'A')->first()->priority_badge);

        \App\Models\ScoringSetting::create(['weights' => config('leadpilot.scoring.weights'), 'thresholds' => ['high' => 90, 'qualified' => 70, 'nurture' => 50]]);
        $this->assertSame(70, ScoreBands::qualified());
        $this->assertSame(0, Lead::qualified()->count());
    }

    // ---------------------------------------------------------------- registry, health, budgets

    public function test_registry_prefers_free_providers_and_needs_budget_for_paid(): void
    {
        config(['leadpilot.sources.google_places.api_key' => 'k']);
        $reg = app(ProviderRegistry::class);
        $this->assertSame(['osm_overpass'], $reg->select('local_discovery'), 'paid Google Places excluded with zero budget');

        config(['leadpilot.budgets.global_daily' => 500, 'leadpilot.budgets.global_monthly' => 5000]);
        $this->assertSame(['osm_overpass', 'google_places'], $reg->select('local_discovery'));

        app(CostLedger::class)->record('google_places', 'local_discovery', 499, true);
        $this->assertFalse(app(BudgetGuard::class)->allows('google_places', 4));
        $this->assertStringContainsString('daily budget reached', app(BudgetGuard::class)->check('google_places', 4)['reason']);

        SpendBudget::create(['scope' => 'provider', 'scope_ref' => 'google_places', 'period' => 'monthly', 'limit_cents' => 100, 'is_active' => true]);
        config(['leadpilot.budgets.global_daily' => 100000]);
        $this->assertFalse(app(BudgetGuard::class)->allows('google_places', 4), 'provider monthly budget also enforced');
    }

    public function test_circuit_breaker_and_repeated_auth_failures_raise_incident(): void
    {
        $h = app(ProviderHealthService::class);
        foreach (range(1, 5) as $i) {
            $h->failure('osm_overpass', 'timeout');
        }
        $this->assertFalse($h->isAvailable('osm_overpass'));
        $this->assertNotContains('osm_overpass', app(ProviderRegistry::class)->select('local_discovery'));

        foreach (range(1, 3) as $i) {
            $h->failure('hunter', 'invalid key', 401);
        }
        $this->assertSame('disabled', ProviderHealth::where('provider', 'hunter')->value('status'));
        $this->assertDatabaseHas('incidents', ['type' => 'auth_failures', 'status' => 'open']);

        $h->success('osm_overpass', true);
        $this->assertTrue($h->isAvailable('osm_overpass'));
        $this->assertNotNull(ProviderHealth::where('provider', 'osm_overpass')->value('last_verified_at'));
    }

    // ---------------------------------------------------------------- policy, incidents, notifications

    public function test_autonomy_policy_defaults_are_safe_and_commercial_actions_cannot_be_automatic(): void
    {
        $p = app(AutonomyPolicyService::class);
        $this->assertSame('approval', $p->mode('outreach.first_touch'));
        $this->assertSame('prohibited', $p->mode('reply.auto_respond'));
        $this->assertSame('approval', $p->mode('some.unknown.action'));

        $this->actingAs($this->admin)->put(route('autopilot.policy.update'), ['modes' => ['proposal.send' => 'automatic', 'outreach.follow_up' => 'approval']])->assertRedirect();
        AutonomyPolicyService::flush();
        $this->assertSame('approval', $p->mode('proposal.send'), 'commercial commitment stays non-automatic');
        $this->assertSame('approval', $p->mode('outreach.follow_up'));

        $this->actingAs($this->sales)->put(route('autopilot.policy.update'), ['modes' => ['outreach.first_touch' => 'automatic']])->assertForbidden();
    }

    public function test_incident_pauses_only_affected_workflows_notifies_admins_and_resolves(): void
    {
        $inc = app(IncidentService::class)->raise('bounce_spike', 'Bounce rate too high', '9% hard bounces', 'outreach', ['rate' => 0.09]);
        $wf = app(WorkflowControlService::class);
        $this->assertTrue($wf->isPaused('outreach'));
        $this->assertTrue($wf->isPaused('follow_ups'));
        $this->assertFalse($wf->isPaused('discovery'), 'unrelated workflow keeps running');
        $this->assertSame(1, $this->admin->unreadNotifications()->count());
        $this->assertSame(0, $this->sales->unreadNotifications()->count());
        $this->assertDatabaseHas('notification_deliveries', ['user_id' => $this->admin->id, 'channel' => 'mail', 'status' => 'logged_only']);
        $this->assertDatabaseMissing('notification_deliveries', ['status' => 'delivered']);

        $again = app(IncidentService::class)->raise('bounce_spike', 'Bounce rate too high', '10%', 'outreach');
        $this->assertSame($inc->id, $again->id, 'deduplicated');

        app(IncidentService::class)->resolve($inc, $this->admin->id);
        $this->assertFalse($wf->isPaused('outreach'));
    }

    public function test_monitor_detects_bounce_spike_and_stale_workers(): void
    {
        $lead = Lead::create(['full_name' => 'x', 'email' => 'x@a.test']);
        $c = Campaign::create(['name' => 'c', 'service_offered' => 'web_dev', 'created_by' => $this->admin->id, 'status' => 'running']);
        foreach (range(1, 20) as $i) {
            CampaignRecipient::create(['campaign_id' => $c->id, 'lead_id' => $lead->id, 'status' => $i <= 3 ? 'bounced' : 'sent', 'bounce_type' => $i <= 3 ? 'hard' : null,
                'idempotency_key' => "k{$i}", 'sent_at' => now()->subMinutes($i)]);
        }
        SystemHeartbeat::create(['component' => 'queue_worker', 'beat_at' => now()->subHour()]);
        $titles = collect(app(HealthService::class)->monitor())->pluck('type')->all();
        $this->assertContains('bounce_spike', $titles);
        $this->assertContains('queue_stalled', $titles);
    }

    // ---------------------------------------------------------------- approvals

    protected function outreachFixture(string $status = 'pending_approval'): CampaignRecipient
    {
        OutreachSetting::current()->update(['mode' => 'live', 'emergency_stop' => false, 'require_verified_email' => false]);
        $company = Company::create(['name' => 'Smile Dental', 'domain' => 'smile.test', 'website' => 'https://smile.test', 'country' => 'Pakistan']);
        $lead = Lead::create(['company_id' => $company->id, 'full_name' => 'Ali Raza', 'first_name' => 'Ali', 'email' => 'ali@smile.test', 'contact_status' => 'identified',
            'job_title' => 'Owner', 'overall_score' => 80, 'country' => 'Pakistan', 'source_name' => 'OpenStreetMap', 'relevant_services' => ['web_redesign']]);
        $campaign = Campaign::create(['name' => 'Autopilot', 'service_offered' => 'web_redesign', 'created_by' => $this->admin->id, 'status' => 'running', 'is_autopilot' => true, 'min_lead_score' => 65, 'daily_limit' => 10]);

        return CampaignRecipient::create(['campaign_id' => $campaign->id, 'lead_id' => $lead->id, 'status' => $status, 'idempotency_key' => 'x1', 'sequence_step' => 1,
            'personalized_subject' => 'Smile Dental on mobile', 'personalized_body' => "Hi Ali,\n\nI noticed smile.test has no viewport tag.\n\nIsmail"]);
    }

    public function test_approval_flow_sends_only_after_a_human_approves_with_edits(): void
    {
        config(['leadpilot.autopilot.sending_hours' => [0, 24], 'leadpilot.autopilot.sending_days' => [1, 2, 3, 4, 5, 6, 7]]);
        $rec = $this->outreachFixture();
        $svc = app(ApprovalService::class);
        $req = $svc->request('outreach_message', "outreach:{$rec->id}", ['title' => 'Approve first email', 'summary' => 'x', 'payload' => ['subject' => $rec->personalized_subject, 'body' => $rec->personalized_body]], $rec);
        $this->assertSame($req->id, $svc->request('outreach_message', "outreach:{$rec->id}", ['title' => 'dup', 'summary' => 'dup'], $rec)->id, 'idempotent');
        $this->assertSame('pending', $req->status);
        $this->assertCount(0, $this->sentMessages(), 'nothing sent before approval');

        // a sales rep for whom the lead is unassigned may decide sales-type approvals
        $this->assertTrue($svc->canDecide($this->sales, $req));
        $other = User::factory()->create(['role' => 'sales', 'is_active' => true]);
        $rec->lead->update(['assigned_user_id' => $other->id]);
        $this->assertFalse($svc->canDecide($this->sales, $req->fresh()));
        $this->actingAs($this->sales)->post(route('approvals.decide', $req), ['action' => 'approve'])->assertForbidden();

        $this->actingAs($this->admin)->post(route('approvals.decide', $req), ['action' => 'edit_approve', 'payload' => ['subject' => 'Edited subject', 'body' => "Hi Ali,\n\nEdited body.\n\nIsmail"]])->assertRedirect();
        $req->refresh();
        $this->assertSame('executed', $req->status, (string) $req->execution_result);
        $this->assertSame($this->admin->id, $req->decided_by);
        $rec->refresh();
        $this->assertSame('sent', $rec->status);
        $this->assertSame('Edited subject', $rec->personalized_subject);

        $msgs = $this->sentMessages();
        $this->assertCount(1, $msgs);
        $email = $msgs[0]->getOriginalMessage();
        $this->assertSame('Edited subject', $email->getSubject());
        $h = $email->getHeaders();
        $this->assertSame('List-Unsubscribe=One-Click', $h->get('List-Unsubscribe-Post')->getBodyAsString());
        $this->assertStringContainsString('/unsubscribe?', $h->get('List-Unsubscribe')->getBodyAsString());
        $this->assertSame($rec->rfc_message_id, $h->get('Message-ID')->getId(), 'recorded Message-ID is the real header');
        $this->assertStringContainsString('Unsubscribe:', $email->getTextBody());
        $this->assertContains('approved', collect($req->events)->pluck('event')->map(fn ($e) => $e === 'edited_approved' ? 'approved' : $e)->all());
    }

    public function test_reject_cancels_and_escalation_moves_overdue_requests_up_the_chain(): void
    {
        $rec = $this->outreachFixture();
        $svc = app(ApprovalService::class);
        $req = $svc->request('outreach_message', 'o1', ['title' => 't', 'summary' => 's', 'payload' => ['subject' => 'a', 'body' => 'b']], $rec);
        $svc->decide($req, $this->admin, 'reject', ['note' => 'tone']);
        $this->assertSame('rejected', $rec->fresh()->status);
        $this->assertCount(0, $this->sentMessages());

        $req2 = $svc->request('outreach_message', 'o2', ['title' => 't2', 'summary' => 's'], $rec);
        $this->assertSame('sales', $req2->assigned_role);
        $req2->update(['due_at' => now()->subHour()]);
        $res = $svc->processOverdue();
        $this->assertSame(1, $res['escalated']);
        $req2->refresh();
        $this->assertSame(1, $req2->escalation_level);
        $this->assertSame('admin', $req2->assigned_role);
        $this->assertSame('pending', $req2->status, 'still paused — never auto-approved');
    }

    public function test_approval_cannot_be_decided_twice_and_admin_only_types_are_protected(): void
    {
        $svc = app(ApprovalService::class);
        $req = $svc->request('integration_enable', 'int:hunter', ['title' => 'Enable Hunter', 'summary' => 's', 'payload' => ['provider' => 'hunter']]);
        $this->assertFalse($svc->canDecide($this->sales, $req));
        $svc->decide($req, $this->admin, 'approve');
        $this->assertSame('executed', $req->fresh()->status);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $svc->decide($req->fresh(), $this->admin, 'approve');
    }

    // ---------------------------------------------------------------- unsubscribe

    public function test_unsubscribe_requires_valid_token_and_supports_one_click_post(): void
    {
        $this->get('/unsubscribe?email=victim@a.test&token=wrong')->assertStatus(400);
        $this->assertFalse(Suppression::isSuppressed('victim@a.test'));

        $url = OutreachMailer::unsubscribeUrl('Ali@Smile.test');
        $this->get($url)->assertOk();
        $this->assertTrue(Suppression::isSuppressed('ali@smile.test'));

        $this->post('/unsubscribe', ['email' => 'b@c.test', 'token' => OutreachMailer::unsubscribeToken('b@c.test'), 'List-Unsubscribe' => 'One-Click'])->assertOk();
        $this->assertTrue(Suppression::isSuppressed('b@c.test'));

        // links sent by v1-v3 used md5(raw-case email . key)
        $this->get('/unsubscribe?email=' . urlencode('Old@Case.test') . '&token=' . md5('Old@Case.test' . config('app.key')))->assertOk();
        $this->assertTrue(Suppression::isSuppressed('old@case.test'));
    }

    // ---------------------------------------------------------------- replies

    public function test_reply_outcomes_stop_sequences_and_route_humans(): void
    {
        $rec = $this->outreachFixture('sent');
        $rec->update(['sent_at' => now()->subDays(2), 'rfc_message_id' => 'abc@eziline.test']);
        $pending = CampaignRecipient::create(['campaign_id' => $rec->campaign_id, 'lead_id' => $rec->lead_id, 'status' => 'approved', 'idempotency_key' => 'f2', 'sequence_step' => 2, 'parent_recipient_id' => $rec->id]);
        $reply = InboundReply::create(['lead_id' => $rec->lead_id, 'campaign_recipient_id' => $rec->id, 'from_email' => 'ali@smile.test', 'to_email' => 'ismail@eziline.test',
            'subject' => 'Re: Smile Dental on mobile', 'body_text' => 'Sounds good, can we schedule a call next Tuesday?', 'message_id' => 'r1@smile.test', 'received_at' => now()]);
        app(ReplyOutcomeService::class)->handle($reply);

        $this->assertSame('cancelled', $pending->fresh()->status, 'follow-up cancelled on reply');
        $this->assertSame('interested', $rec->lead->fresh()->lifecycle_stage);
        $this->assertSame('engaged', $rec->lead->fresh()->pipeline_state);
        $meeting = ApprovalRequest::where('type', 'meeting_slots')->first();
        $this->assertNotNull($meeting, 'meeting times proposed for approval, never auto-sent');
        $this->assertStringNotContainsString('Tuesday 11am', $meeting->payload['body']);
        $this->assertMatchesRegularExpression('/\d{1,2}:\d{2} (AM|PM) \(Asia\/Karachi/', $meeting->payload['body']);
        $this->assertDatabaseHas('tasks', ['lead_id' => $rec->lead_id, 'priority' => 'high']);

        $amb = InboundReply::create(['lead_id' => $rec->lead_id, 'from_email' => 'ali@smile.test', 'to_email' => 'x', 'subject' => 'Re', 'body_text' => 'Thanks for reaching out.', 'message_id' => 'r2', 'received_at' => now()]);
        app(ReplyOutcomeService::class)->handle($amb);
        $this->assertDatabaseHas('approval_requests', ['type' => 'low_confidence_reply', 'subject_id' => $amb->id]);

        $uns = InboundReply::create(['lead_id' => $rec->lead_id, 'from_email' => 'ali@smile.test', 'to_email' => 'x', 'subject' => 'Re', 'body_text' => 'Please remove me from your list', 'message_id' => 'r3', 'received_at' => now()]);
        app(ReplyOutcomeService::class)->handle($uns);
        $this->assertTrue(Suppression::isSuppressed('ali@smile.test'));
        $this->assertSame('suppressed', $rec->lead->fresh()->pipeline_state);
    }

    public function test_low_confidence_reply_resumes_after_human_classification(): void
    {
        $rec = $this->outreachFixture('sent');
        $reply = InboundReply::create(['lead_id' => $rec->lead_id, 'from_email' => 'ali@smile.test', 'to_email' => 'x', 'subject' => 'Re', 'body_text' => 'Hmm, maybe.', 'message_id' => 'q1', 'received_at' => now()]);
        app(ReplyOutcomeService::class)->handle($reply);
        $req = ApprovalRequest::where('type', 'low_confidence_reply')->firstOrFail();
        $this->assertNotSame('lost', $rec->lead->fresh()->lifecycle_stage, 'no CRM change before a human classifies');

        app(ApprovalService::class)->decide($req, $this->admin, 'edit_approve', ['payload' => ['category' => 'not_interested']]);
        $this->assertSame('executed', $req->fresh()->status);
        $this->assertSame('lost', $rec->lead->fresh()->lifecycle_stage);
        $this->assertSame('not_interested', $reply->fresh()->category);
    }

    // ---------------------------------------------------------------- meetings, proposals, states

    public function test_meeting_slots_are_real_free_working_hours_on_distinct_weekdays(): void
    {
        $from = Carbon::parse('2026-10-23 10:00', 'Asia/Karachi'); // Friday
        Meeting::create(['lead_id' => Lead::create(['full_name' => 'm'])->id, 'host_user_id' => $this->admin->id, 'title' => 'busy',
            'scheduled_start' => Carbon::parse('2026-10-26 10:00', 'Asia/Karachi')->utc(), 'scheduled_end' => Carbon::parse('2026-10-26 10:30', 'Asia/Karachi')->utc(), 'status' => 'scheduled']);
        $slots = app(MeetingSlotService::class)->slots(3, $from->copy()->utc());
        $this->assertCount(3, $slots);
        foreach ($slots as $s) {
            $this->assertTrue($s->isWeekday());
            $this->assertGreaterThanOrEqual(10, $s->hour);
            $this->assertLessThan(17, $s->hour);
            $this->assertFalse($s->eq(Carbon::parse('2026-10-26 10:00', 'Asia/Karachi')), 'busy slot skipped');
        }
        $this->assertCount(3, collect($slots)->map->toDateString()->unique());
    }

    public function test_proposal_is_non_binding_and_requires_approval_to_send(): void
    {
        $rec = $this->outreachFixture('sent');
        \App\Models\BuyingSignal::create(['company_id' => $rec->lead->company_id, 'signal_type' => 'website_issues', 'title' => 'No website listed', 'evidence_text' => 'x',
            'observed_date' => now()->toDateString(), 'confidence' => 90, 'relevant_service' => 'web_dev', 'verification_status' => 'verified', 'metadata' => ['evidence_key' => 'no_website']]);
        $draft = app(ProposalDraftService::class)->draft($rec->lead->fresh());
        $this->assertNotNull($draft);
        $this->assertStringContainsString('not an offer capable of acceptance', $draft->body);
        $this->assertStringContainsString('estimate, not a quotation', $draft->body);
        $this->assertSame('draft', $draft->status);
        $this->assertCount(0, $this->sentMessages());
    }

    public function test_pipeline_states_are_separate_from_score(): void
    {
        $s = app(LeadStateService::class);
        $l = Lead::create(['full_name' => 'x', 'overall_score' => 90, 'contact_status' => 'needs_enrichment']);
        $this->assertSame('enrichment_required', $l->pipeline_state);
        $l->update(['contact_status' => 'identified', 'email' => 'x@y.test']);
        $this->assertSame('qualified', $l->fresh()->pipeline_state, 'high score alone is not eligibility');
        $l->update(['outreach_eligible_at' => now()]);
        $this->assertSame('eligible_for_outreach', $l->fresh()->pipeline_state);
        $l->update(['last_contacted_at' => now(), 'lifecycle_stage' => 'contacted']);
        $this->assertSame('contacted', $l->fresh()->pipeline_state);
        $l->update(['lifecycle_stage' => 'won']);
        $this->assertSame('won', $l->fresh()->pipeline_state);
        $this->assertCount(12, LeadStateService::STATES);
    }

    // ---------------------------------------------------------------- UI

    public function test_autopilot_pages_render_and_are_role_gated(): void
    {
        app(IncidentService::class)->raise('queue_stalled', 'Queue worker is not running', 'no heartbeat');
        $req = app(ApprovalService::class)->request('system_test', 't1', ['title' => 'Test approval', 'summary' => 's']);
        $icp = \App\Models\IcpProfile::create(['name' => 'Arm ICP', 'is_active' => true, 'website_requirement' => 'any']);
        \App\Models\SearchArm::create(['icp_profile_id' => $icp->id, 'source_slug' => 'osm_overpass', 'runs' => 2, 'leads' => 5, 'qualified' => 2]);
        $this->actingAs($this->admin);
        foreach (['autopilot.index', 'autopilot.policy', 'autopilot.setup', 'autopilot.performance', 'approvals.index', 'notifications.index'] as $r) {
            $this->get(route($r))->assertOk();
        }
        $this->get(route('approvals.show', $req))->assertOk()->assertSee('Test approval');
        $this->get(route('autopilot.setup'))->assertSee('Reply-To address');
        $this->get(route('autopilot.index'))->assertSee('Queue worker is not running')->assertSee('Arm ICP');

        $this->actingAs($this->sales);
        $this->get(route('approvals.index'))->assertOk();
        foreach (['autopilot.index', 'autopilot.performance', 'autopilot.policy', 'autopilot.setup'] as $r) {
            $this->get(route($r))->assertForbidden();
        }
        $this->post(route('autopilot.run'))->assertForbidden();
        $this->get(route('approvals.show', $req))->assertForbidden();
    }
}
