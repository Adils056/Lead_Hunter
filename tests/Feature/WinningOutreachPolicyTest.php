<?php

namespace Tests\Feature;

use App\Models\BuyingSignal;
use App\Models\Company;
use App\Models\Lead;
use App\Models\OutreachSetting;
use App\Models\User;
use App\Models\WebsiteAudit;
use App\Services\EligibilityGateService;
use App\Services\Outreach\OutreachGuard;
use App\Services\Outreach\SequenceEngine;
use App\Services\Outreach\WinLadderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Only winning buyers get an email" rules added 23 Oct 2026:
 *  - a local business's own published inbox may be emailed when the owner allows it; tender contacts never;
 *  - in live mode nothing goes to a prospect while APP_URL is local (unsubscribe links would be dead);
 *  - the autopilot first touch takes rung A only.
 */
class WinningOutreachPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['mail.default' => 'array', 'app.url' => 'https://leads.eziline.example']);
        User::factory()->create(['role' => 'admin', 'is_active' => true, 'email' => 'admin@eziline.test']);
        OutreachSetting::current()->update(['mode' => 'live', 'require_verified_email' => true, 'min_lead_score' => 65, 'controlled_pilot_recipient' => 'owner@eziline.test']);
    }

    protected function business(string $name, string $provider = 'osm_overpass', string $country = 'Pakistan'): Company
    {
        $domain = strtolower(str_replace(' ', '', $name)) . '.pk';
        $c = Company::create(['name' => $name, 'website' => "https://{$domain}", 'domain' => $domain, 'country' => $country, 'city' => 'Rawalpindi', 'industry' => 'Clinic',
            'source_provider' => $provider, 'website_opportunity_score' => 70, 'website_audited_at' => now()->subDay(), 'public_email' => "info@{$domain}"]);
        BuyingSignal::create(['company_id' => $c->id, 'signal_type' => 'website_gap', 'title' => 'No mobile viewport', 'evidence_text' => 'Homepage has no viewport meta.', 'relevant_service' => 'web_redesign', 'confidence' => 85,
            'observed_date' => now()->subDay(), 'verification_status' => 'verified', 'metadata' => ['source' => 'website_audit']]);
        WebsiteAudit::create(['company_id' => $c->id, 'url' => $c->website, 'final_url' => $c->website, 'status' => 'completed', 'http_status' => 200, 'response_time_ms' => 900, 'opportunity_score' => 70,
            'checks' => [['key' => 'viewport', 'label' => 'Mobile viewport configured', 'status' => 'fail', 'value' => null, 'evidence' => 'No viewport meta tag.']],
            'opportunities' => [['service' => 'web_redesign', 'label' => 'Website Redesign', 'weight' => 6, 'evidence' => ['No viewport meta tag.']]], 'audited_at' => now()->subDay()]);

        return $c;
    }

    protected function sharedInboxLead(Company $c, array $over = []): Lead
    {
        return Lead::create(array_merge(['company_id' => $c->id, 'first_name' => 'Info', 'last_name' => 'Inbox', 'job_title' => null, 'email' => $c->public_email,
            'email_verification_status' => 'verified', 'contact_status' => 'generic_inbox', 'country' => $c->country, 'overall_score' => 72, 'source_name' => 'OpenStreetMap (Overpass)',
            'lifecycle_stage' => 'qualified', 'pipeline_state' => 'qualified', 'score_explanation' => 'audit evidence'], $over));
    }

    public function test_shared_inbox_is_blocked_by_default_and_allowed_by_the_owner_switch_except_for_tenders(): void
    {
        $gate = app(EligibilityGateService::class);
        $ladder = app(WinLadderService::class);
        $clinic = $this->sharedInboxLead($this->business('Fatima Clinic'));
        $tender = $this->sharedInboxLead($this->business('Borough Council', 'uk_contracts_finder', 'United Kingdom'), ['job_title' => 'Tender contact (published)']);

        $this->assertFalse($gate->evaluate($clinic)->isEligible);
        $this->assertSame('B', $ladder->evaluate($clinic)['tier'], 'shared inbox is the single blocker');

        OutreachSetting::current()->update(['allow_shared_inbox' => true]);

        $this->assertTrue($gate->evaluate($clinic->fresh())->isEligible, json_encode($gate->evaluate($clinic->fresh())->failedChecks));
        $this->assertSame('A', $ladder->evaluate($clinic->fresh())['tier']);
        $this->assertFalse($gate->evaluate($tender->fresh())->isEligible, 'published tender contacts are never cold-emailed');
        $this->assertArrayHasKey('contact_identity', $gate->evaluate($tender->fresh())->failedChecks);
    }

    public function test_live_mode_with_local_app_url_blocks_prospects_but_not_the_owner_pilot(): void
    {
        OutreachSetting::current()->update(['allow_shared_inbox' => true]);
        $lead = $this->sharedInboxLead($this->business('Dar Medical'));
        $gate = app(EligibilityGateService::class);
        $this->assertTrue($gate->evaluate($lead)->isEligible);

        config(['app.url' => 'http://localhost:8000']);
        $r = $gate->evaluate($lead->fresh());
        $this->assertFalse($r->isEligible);
        $this->assertArrayHasKey('public_url', $r->failedChecks);
        $this->assertSame('B', app(WinLadderService::class)->evaluate($lead->fresh())['tier']);
        $this->assertStringContainsString('APP_URL', (string) OutreachGuard::blockReason('outreach', 'outreach.first_touch', $lead->email));
        $this->assertNull(OutreachGuard::blockReason('outreach', 'outreach.first_touch', 'owner@eziline.test'), 'the owner pilot address is exempt');

        config(['app.url' => 'https://leads.eziline.example']);
        $this->assertNull(OutreachGuard::blockReason('outreach', 'outreach.first_touch', $lead->email));
    }

    public function test_autopilot_first_touch_plans_rung_a_only(): void
    {
        OutreachSetting::current()->update(['allow_shared_inbox' => true]);
        $a = $this->sharedInboxLead($this->business('Aziz Medical'));
        $b = $this->sharedInboxLead($this->business('Heart Care'), ['email_verification_status' => 'unverified']);
        $c = $this->sharedInboxLead($this->business('Nomail Hospital'), ['email' => null, 'contact_status' => 'needs_enrichment']);
        app(WinLadderService::class)->refreshAll();
        $this->assertSame(['A', 'B', 'C'], [$a->fresh()->win_tier, $b->fresh()->win_tier, $c->fresh()->win_tier]);

        $out = app(SequenceEngine::class)->planFirstTouches(10);

        $this->assertSame(1, $out['planned'] + $out['pending_approval'], json_encode($out));
        $this->assertSame(1, $a->fresh()->campaignRecipients()->count());
        $this->assertSame(0, $b->fresh()->campaignRecipients()->count());
    }
}
