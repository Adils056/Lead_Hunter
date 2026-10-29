<?php

namespace Tests\Feature;

use App\Models\BuyingSignal;
use App\Models\Company;
use App\Models\Lead;
use App\Models\OutreachSetting;
use App\Models\WebsiteAudit;
use App\Services\Audit\WebsiteAuditService;
use App\Services\Sales\ConversionAssistantService;
use App\Services\Sales\ReplyClassifier;
use App\Support\CompanyName;
use App\Support\TenderRelevance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** SQA report 29 Oct 2026, critical bugs 1-4 and 7: e-mails that must never go out. */
class SqaRoundOneTest extends TestCase
{
    use RefreshDatabase;

    public function test_bug1_a_site_that_refuses_our_server_is_not_down(): void
    {
        config(['leadpilot.sources.website_crawler.dns_overrides' => ['lawfirm.example' => ['93.184.216.34']], 'leadpilot.sources.pagespeed.enabled' => false]);
        Http::fake(['*' => Http::response('<html><head><title>403 Forbidden</title></head><body>nginx</body></html>', 403)]);
        $c = Company::create(['name' => 'Simmons and Fletcher, P.C.', 'website' => 'https://lawfirm.example', 'domain' => 'lawfirm.example', 'country' => 'United States']);
        $svc = app(WebsiteAuditService::class);

        $first = $svc->audit($c);
        $this->travel(2)->hours();
        $second = $svc->audit($c->fresh());

        $this->assertSame(['bot_protected', 'bot_protected'], [$first->status, $second->status]);
        $this->assertSame(0, (int) $second->opportunity_score);
        $lead = Lead::create(['company_id' => $c->id, 'full_name' => 'x', 'email' => 'info@lawfirm.example', 'country' => 'United States']);
        $this->assertSame([], collect(app(ConversionAssistantService::class)->facts($lead->fresh()))->where('check', 'reachable')->all(), 'no "didn\'t load" claim');
    }

    public function test_bug2_only_software_work_counts_as_a_tender_we_can_answer(): void
    {
        foreach (['Engineering & Climate-Resilient Infrastructure Advisory digital', 'Schools Microsoft Enterprise Licensing Agreement software', 'Handheld 3D SLAM scanner with backpack mount and software',
            'Manchester Airport Phase 2 - Overbridge Civils portal', 'ITT for Training Providers digital skills'] as $t) {
            $this->assertFalse(TenderRelevance::isSoftwareWork($t, '', ['software', 'digital', 'portal']), $t);
        }
        $this->assertFalse(TenderRelevance::isSoftwareWork('Microsoft Enterprise Licensing Agreement', '48000000'), 'licence resale is not our work even under a software CPV');
        $this->assertTrue(TenderRelevance::isSoftwareWork('Redesign of the council website', ''));
        $this->assertTrue(TenderRelevance::isSoftwareWork('Case management platform', '72200000'));
    }

    public function test_bug3_the_offer_answers_the_observation_and_role_inboxes_are_blocked(): void
    {
        OutreachSetting::current()->update(['approved_services' => array_keys(config('leadpilot.services'))]);
        $c = Company::create(['name' => 'Sidekick Labs', 'website' => 'https://sidekicklabs.example', 'domain' => 'sidekicklabs.example', 'country' => 'United States']);
        WebsiteAudit::create(['company_id' => $c->id, 'url' => $c->website, 'status' => 'completed', 'http_status' => 200, 'opportunity_score' => 20, 'pagespeed' => ['performance_score' => 61],
            'checks' => [['key' => 'pagespeed_mobile', 'label' => 'Mobile performance', 'status' => 'warn', 'value' => 61, 'evidence' => 'PageSpeed 61/100', 'evidence_keys' => ['slow_mobile_performance']]],
            'opportunities' => [['service' => 'web_redesign', 'label' => 'Website Redesign', 'weight' => 3, 'evidence' => ['PageSpeed 61/100']]], 'audited_at' => now()]);
        // a hiring post whose text mentioned stock and accounts: it used to win the offer slot with an ERP pitch
        BuyingSignal::create(['company_id' => $c->id, 'signal_type' => 'developer_hiring', 'title' => 'Hiring developers (Hacker News)', 'confidence' => 85, 'relevant_service' => 'erp_pos',
            'source_url' => 'https://news.ycombinator.com/item?id=1', 'evidence_text' => 'Senior engineer for our sales, stock and accounts platform', 'observed_date' => now()->toDateString()]);
        $lead = Lead::create(['company_id' => $c->id, 'full_name' => 'Sidekick Labs — general inbox', 'email' => 'hello@sidekicklabs.example', 'contact_status' => 'generic_inbox', 'country' => 'United States']);

        $d = app(ConversionAssistantService::class)->draftFirstEmail($lead->fresh(), false);

        $this->assertTrue($d['ok'], $d['reason'] ?? '');
        $this->assertStringNotContainsString('one system for sales, stock and accounts', $d['draft']['body']);
        if ($d['facts_used'][0]['source'] === 'website_audit') {
            $this->assertSame('web_redesign', $d['offer']['service']);
            $this->assertStringContainsString('3 fixes', $d['draft']['body']);
        } else {
            $this->assertSame('laravel_php', $d['offer']['service']);
            $this->assertStringNotContainsString('3 fixes', $d['draft']['body'], 'no "fixes" promise without a website finding');
        }

        foreach (['hiring@x.com', 'recruiting@x.com', 'underwriting@x.com', 'jobs+eng@x.com', 'noreply@x.com'] as $e) {
            $lead->update(['email' => $e]);
            $this->assertArrayHasKey('role_inbox', app(\App\Services\EligibilityGateService::class)->evaluate($lead->fresh(), null, null, false)->failedChecks, $e);
        }
        $lead->update(['email' => 'hello@x.com']);
        $this->assertArrayNotHasKey('role_inbox', app(\App\Services\EligibilityGateService::class)->evaluate($lead->fresh(), null, null, false)->failedChecks);
    }

    public function test_bug4_names_in_emails_are_clean(): void
    {
        $this->assertSame('Simmons and Fletcher', CompanyName::forEmail('Simmons and Fletcher, P.C., Injury & Accident Lawyers'));
        $this->assertSame('Snout', CompanyName::forEmail('Snout https://snout.com/'));
        $this->assertSame('Acme Dental', CompanyName::forEmail('Acme Dental Ltd'));
        $this->assertSame('Bright Smile', CompanyName::forEmail('Bright Smile — Family Dentist in Leeds'));
        $this->assertNull(CompanyName::forEmail('روياٹ لگژری ہوٹل'), 'Urdu script in an English e-mail: no name');
        $this->assertSame('Smith, Jones & Partners', CompanyName::forEmail('Smith, Jones & Partners'), 'commas alone are kept');

        $c = Company::create(['name' => 'روياٹ لگژری ہوٹل', 'website' => 'https://royauteluxury.example', 'domain' => 'royauteluxury.example', 'country' => 'Pakistan']);
        WebsiteAudit::create(['company_id' => $c->id, 'url' => $c->website, 'status' => 'completed', 'http_status' => 200, 'opportunity_score' => 40,
            'checks' => [['key' => 'viewport', 'label' => 'Viewport', 'status' => 'fail', 'evidence' => 'No viewport', 'evidence_keys' => ['no_viewport']]],
            'opportunities' => [['service' => 'web_redesign', 'label' => 'Website Redesign', 'weight' => 6, 'evidence' => ['No viewport']]], 'audited_at' => now()]);
        $lead = Lead::create(['company_id' => $c->id, 'full_name' => 'x', 'email' => 'info@royauteluxury.example', 'contact_status' => 'generic_inbox', 'country' => 'Pakistan']);
        $d = app(ConversionAssistantService::class)->draftFirstEmail($lead->fresh(), false);

        $this->assertStringStartsWith('Hi there,', $d['draft']['body']);
        $this->assertSame('royauteluxury.example on mobile', $d['draft']['subject']);
    }

    public function test_bug7_a_polite_no_to_agencies_is_a_decline(): void
    {
        $c = app(ReplyClassifier::class);
        foreach (["Hi Ismail, We're not working with external recruiters, staffing agencies, or contract teams for these roles. Eric",
            'We do not work with agencies.', 'No agencies please.', 'Please remove me from your list.'] as $t) {
            $this->assertContains($c->classify($t)['category'], ['not_interested', 'unsubscribe'], $t);
        }
    }

    public function test_bug18_sent_today_counts_the_business_day_and_the_repair_migration_cleans_old_data(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-10-29 02:00', 'Asia/Karachi')); // 28 Oct 21:00 UTC
        $admin = \App\Models\User::factory()->create(['role' => 'admin']);
        $camp = \App\Models\Campaign::create(['name' => 'A', 'service_offered' => 'web_redesign', 'created_by' => $admin->id, 'status' => 'running']);
        $lead = Lead::create(['full_name' => 'x', 'email' => 'a@b.example']);
        foreach (['2026-10-29 01:00' => 'k1', '2026-10-28 23:00' => 'k2'] as $at => $k) {
            \App\Models\CampaignRecipient::create(['campaign_id' => $camp->id, 'lead_id' => $lead->id, 'status' => 'sent', 'idempotency_key' => $k, 'sent_at' => \Carbon\Carbon::parse($at, 'Asia/Karachi')->utc()]);
        }
        $this->actingAs($admin)->get(route('outbox.index'))->assertOk()->assertViewHas('stats', fn ($s) => $s['today'] === 1);

        $c = Company::create(['name' => 'Blocked Co', 'website' => 'https://blocked.example', 'website_opportunity_score' => 40]);
        $a = WebsiteAudit::create(['company_id' => $c->id, 'url' => $c->website, 'status' => 'unreachable', 'http_status' => 403, 'opportunity_score' => 40, 'audited_at' => now()]);
        BuyingSignal::create(['company_id' => $c->id, 'signal_type' => 'website_issues', 'title' => 'down', 'evidence_text' => 'x', 'observed_date' => now()->toDateString(), 'confidence' => 70, 'relevant_service' => 'web_dev', 'metadata' => ['source' => 'website_audit', 'audit_id' => $a->id]]);
        $civils = BuyingSignal::create(['company_id' => $c->id, 'signal_type' => 'procurement_rfp', 'title' => 'Tender: Manchester Airport Phase 2 - Overbridge Civils', 'evidence_text' => 'Civils works', 'observed_date' => now()->toDateString(), 'confidence' => 95, 'relevant_service' => 'custom_software', 'expiry_date' => now()->addMonth()->toDateString()]);
        $web = BuyingSignal::create(['company_id' => $c->id, 'signal_type' => 'procurement_rfp', 'title' => 'Tender: New council website', 'evidence_text' => 'Website rebuild', 'observed_date' => now()->toDateString(), 'confidence' => 95, 'relevant_service' => 'web_dev', 'expiry_date' => now()->addMonth()->toDateString()]);

        (require base_path('database/migrations/2026_10_29_000001_repair_blocked_audits_and_non_software_tenders.php'))->up();

        $this->assertSame('bot_protected', $a->fresh()->status);
        $this->assertSame(0, (int) $c->fresh()->website_opportunity_score);
        $this->assertSame(0, BuyingSignal::where('signal_type', 'website_issues')->count());
        $this->assertTrue($civils->fresh()->expiry_date->isPast());
        $this->assertFalse($web->fresh()->expiry_date->isPast());
    }
}
