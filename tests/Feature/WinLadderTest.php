<?php

namespace Tests\Feature;

use App\Models\BuyingSignal;
use App\Models\CampaignRecipient;
use App\Models\Campaign;
use App\Models\Company;
use App\Models\InboundReply;
use App\Models\Lead;
use App\Models\OutreachSetting;
use App\Models\Suppression;
use App\Models\User;
use App\Services\Outreach\WinLadderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WinLadderTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['mail.default' => 'array', 'mail.from.address' => 'ismail@eziline.test']);
        $this->admin = User::factory()->create(['role' => 'admin', 'is_active' => true, 'email' => 'admin@eziline.test']);
        OutreachSetting::current()->update(['mode' => 'live', 'require_verified_email' => true]);
    }

    protected function clinic(string $name, int $opportunity = 70): Company
    {
        $c = Company::create(['name' => $name, 'website' => 'https://' . strtolower(str_replace(' ', '', $name)) . '.test', 'domain' => strtolower(str_replace(' ', '', $name)) . '.test',
            'country' => 'Pakistan', 'city' => 'Rawalpindi', 'industry' => 'Healthcare', 'website_opportunity_score' => $opportunity, 'website_audited_at' => now()->subDay(), 'google_review_count' => 40]);
        BuyingSignal::create(['company_id' => $c->id, 'signal_type' => 'website_gap', 'title' => 'No mobile viewport, no click-to-call', 'evidence_text' => 'Homepage has no viewport meta and no tel: link.', 'relevant_service' => 'web_redesign', 'confidence' => 85,
            'observed_date' => now()->subDay(), 'verification_status' => 'verified', 'metadata' => ['source' => 'website_audit']]);

        \App\Models\WebsiteAudit::create(['company_id' => $c->id, 'url' => $c->website, 'final_url' => $c->website, 'status' => 'completed', 'http_status' => 200, 'response_time_ms' => 900, 'opportunity_score' => $opportunity,
            'checks' => [['key' => 'viewport', 'label' => 'Mobile viewport configured', 'status' => 'fail', 'value' => null, 'evidence' => 'No viewport meta tag.'], ['key' => 'click_to_call', 'label' => 'Click-to-call link', 'status' => 'warn', 'value' => null, 'evidence' => 'No tel: link.']],
            'opportunities' => [['service' => 'web_redesign', 'label' => 'Website Redesign', 'weight' => 6, 'evidence' => ['No viewport meta tag.']]], 'audited_at' => now()->subDay()]);

        return $c;
    }

    protected function lead(Company $c, array $over = []): Lead
    {
        return Lead::create(array_merge(['company_id' => $c->id, 'first_name' => 'Ayesha', 'last_name' => 'Khan', 'job_title' => 'Owner', 'email' => 'owner@' . $c->domain,
            'email_verification_status' => 'verified', 'contact_status' => 'identified', 'country' => 'Pakistan', 'overall_score' => 78, 'source_name' => 'OpenStreetMap',
            'lifecycle_stage' => 'qualified', 'score_explanation' => 'audit evidence'], $over));
    }

    public function test_rungs_are_decided_top_down_and_ranked_by_evidence(): void
    {
        $ladder = app(WinLadderService::class);

        $a1 = $this->lead($this->clinic('Smile Dental', 80));
        $a2 = $this->lead($this->clinic('Glow Skin', 40), ['job_title' => 'Receptionist', 'overall_score' => 70]);
        $rfpCo = $this->clinic('Punjab Health Portal', 60);
        BuyingSignal::create(['company_id' => $rfpCo->id, 'signal_type' => 'procurement_rfp', 'title' => 'RFP: patient portal', 'evidence_text' => 'Published tender for a patient portal.', 'relevant_service' => 'custom_software', 'confidence' => 90, 'observed_date' => now(), 'verification_status' => 'verified']);
        $a0 = $this->lead($rfpCo, ['job_title' => 'Chief Technology Officer', 'overall_score' => 85]);

        $b = $this->lead($this->clinic('City Physio'), ['email_verification_status' => 'unverified']);
        $c = $this->lead($this->clinic('Nomail Clinic'), ['email' => null]);
        $cGeneric = $this->lead($this->clinic('Info Clinic'), ['contact_status' => 'generic_inbox', 'job_title' => null, 'first_name' => 'Info', 'last_name' => 'Inbox']);
        $d1 = $this->lead($this->clinic('Invalid Mail'), ['email_verification_status' => 'invalid']);
        $d2 = $this->lead($this->clinic('Opted Out'));
        Suppression::create(['email' => $d2->email, 'reason' => 'unsubscribe', 'source' => 'test']);
        $d3 = $this->lead($this->clinic('Abroad Clinic'), ['country' => 'France']);
        $r = $this->lead($this->clinic('Replied Clinic'));
        InboundReply::create(['lead_id' => $r->id, 'from_email' => $r->email, 'to_email' => 'ismail@eziline.test', 'subject' => 'Re', 'body_text' => 'Yes, let us talk', 'classification' => 'positive', 'category' => 'interested', 'received_at' => now()]);
        $declined = $this->lead($this->clinic('Declined Clinic'));
        InboundReply::create(['lead_id' => $declined->id, 'from_email' => $declined->email, 'to_email' => 'ismail@eziline.test', 'subject' => 'Re', 'body_text' => 'No thanks', 'classification' => 'negative', 'category' => 'not_interested', 'received_at' => now()]);
        $s = $this->lead($this->clinic('Pending Clinic'));
        $campaign = Campaign::create(['name' => 'AP', 'service_offered' => 'web_redesign', 'status' => 'running', 'created_by' => $this->admin->id, 'is_autopilot' => true]);
        CampaignRecipient::create(['campaign_id' => $campaign->id, 'lead_id' => $s->id, 'status' => 'pending_approval', 'sequence_step' => 1, 'idempotency_key' => 'k1']);
        $s2 = $this->lead($this->clinic('Recent Contact'), ['last_contacted_at' => now()->subDay()]);

        $counts = $ladder->refreshAll();
        $tier = fn (Lead $l) => $l->fresh()->win_tier;

        $this->assertSame('A', $tier($a0));
        $this->assertSame('A', $tier($a1));
        $this->assertSame('A', $tier($a2));
        $this->assertSame('B', $tier($b));
        $this->assertStringContainsString('Verify the email', implode(' ', $b->fresh()->win_blockers));
        $this->assertSame('C', $tier($c));
        $this->assertSame('B', $tier($cGeneric), 'shared inbox with evidence = send manually after review');
        $this->assertSame('D', $tier($d1));
        $this->assertSame('D', $tier($d2));
        $this->assertSame('D', $tier($d3));
        $this->assertSame('R', $tier($r));
        $this->assertSame('D', $tier($declined));
        $this->assertSame('S', $tier($s));
        $this->assertSame('S', $tier($s2));

        // ranking inside rung A: explicit request + CTO beats audit-only owner beats receptionist with weak audit
        $ranked = $ladder->ranked('A')->pluck('id')->all();
        $this->assertSame([$a0->id, $a1->id, $a2->id], $ranked);
        $this->assertSame(3, $counts['A']);
        $this->assertSame(['R' => 1, 'A' => 3, 'B' => 2, 'S' => 2, 'C' => 1, 'D' => 4], $counts);
    }

    public function test_in_sequence_says_whether_the_draft_needs_approval_or_is_just_scheduled(): void
    {
        $campaign = Campaign::create(['name' => 'AP', 'service_offered' => 'web_redesign', 'status' => 'running', 'created_by' => $this->admin->id, 'is_autopilot' => true]);
        $pending = $this->lead($this->clinic('Pending Clinic'));
        CampaignRecipient::create(['campaign_id' => $campaign->id, 'lead_id' => $pending->id, 'status' => 'pending_approval', 'sequence_step' => 1, 'idempotency_key' => 'k1']);
        $later = $this->lead($this->clinic('Later Clinic'));
        CampaignRecipient::create(['campaign_id' => $campaign->id, 'lead_id' => $later->id, 'status' => 'approved', 'sequence_step' => 1, 'idempotency_key' => 'k2', 'scheduled_at' => now()->addDays(9)]);

        app(WinLadderService::class)->refreshAll();

        $this->assertSame('S', $pending->fresh()->win_tier);
        $this->assertStringContainsString('waiting for approval', implode(' ', $pending->fresh()->win_blockers));
        $this->assertSame('S', $later->fresh()->win_tier);
        $this->assertStringContainsString('scheduled to send', implode(' ', $later->fresh()->win_blockers));
    }

    public function test_autopilot_first_touches_follow_the_ladder_order_and_page_renders(): void
    {
        $ladder = app(WinLadderService::class);
        $weak = $this->lead($this->clinic('Weak Evidence', 30), ['overall_score' => 70, 'job_title' => 'Receptionist']);
        $strong = $this->lead($this->clinic('Strong Evidence', 90), ['overall_score' => 70, 'job_title' => 'Owner']);
        $ladder->refreshAll();
        $this->assertGreaterThan($weak->fresh()->win_points, $strong->fresh()->win_points);

        // same score: the sequence engine must pick the stronger-evidence lead first
        $planned = app(\App\Services\Outreach\SequenceEngine::class)->planFirstTouches(1);
        $this->assertSame(1, $planned['planned'] + $planned['pending_approval'], json_encode($planned));
        $this->assertSame($strong->id, CampaignRecipient::first()->lead_id);

        $this->actingAs($this->admin);
        $this->get(route('leads.win-ladder'))->assertOk()->assertSee('Win Ladder')->assertSee('Strong Evidence');
        $this->get(route('leads.win-ladder', ['tier' => 'C']))->assertOk();
        $this->get(route('leads.win-ladder.export', ['tier' => 'A']))->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->post(route('leads.win-ladder.refresh'))->assertRedirect();
        $this->artisan('leadpilot:win-ladder', ['--tier' => 'all', '--limit' => 5])->assertExitCode(0);
    }
}
