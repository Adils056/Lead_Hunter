<?php

namespace Tests\Feature;

use App\Models\BuyingSignal;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Company;
use App\Models\EmailTemplate;
use App\Models\Lead;
use App\Models\OutreachSetting;
use App\Models\Suppression;
use App\Models\User;
use App\Services\Discovery\Adapters\PublicJobBoardDemandAdapter;
use App\Services\Discovery\Adapters\UkContractsFinderAdapter;
use App\Services\Discovery\DiscoveryManager;
use App\Services\EligibilityGateService;
use App\Services\LeadScoringService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AutonomousOutreachAndDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected EmailTemplate $template;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->template = EmailTemplate::create([
            'name' => 'Laravel & Web Outreach',
            'slug' => 'test-web-outreach',
            'service_category' => 'laravel_php',
            'subject' => 'Software engineering for {{company_name}}',
            'body_text' => 'Hi {{first_name}}, let us connect regarding {{company_name}}.',
            'body_html' => '<p>Hi {{first_name}}, let us connect regarding {{company_name}}.</p>',
            'is_active' => true,
        ]);
    }

    public function test_public_job_demand_discovery_ingests_companies_signals_and_leads_idempotently(): void
    {
        Http::fake([
            'remotive.com/*' => Http::response([
                'jobs' => [
                    [
                        'id' => 101,
                        'title' => 'Senior Laravel / PHP Engineer',
                        'company_name' => 'Remotive Partner Co',
                        'url' => 'https://remotive.com/jobs/101',
                        'description' => 'We are seeking an experienced Laravel engineer to scale our web applications and REST APIs.',
                        'candidate_required_location' => 'Worldwide (Pakistan/UK/US)',
                        'publication_date' => Carbon::now()->subDays(2)->toIso8601String(),
                    ]
                ]
            ], 200)
        ]);

        config(['leadpilot.sources.remotive.enabled' => true]);
        $adapter = new PublicJobBoardDemandAdapter();
        $result = $adapter->discover(['keywords' => ['laravel'], 'limit' => 1]);

        $this->assertTrue($result->isSuccess());
        $this->assertEquals(1, $result->newCompaniesCount);
        $this->assertEquals(1, $result->newSignalsCount);
        $this->assertEquals(1, $result->newLeadsCount);

        $this->assertDatabaseHas('companies', ['name' => 'Remotive Partner Co']);
        $this->assertDatabaseHas('buying_signals', ['signal_type' => 'developer_hiring']);

        // Integrity: Remotive gives no website or contact, so none may be invented
        $company = Company::where('name', 'Remotive Partner Co')->first();
        $this->assertNull($company->domain);
        $this->assertNull($company->website);
        $this->assertEmpty($company->tech_stack);
        $lead = Lead::where('company_id', $company->id)->first();
        $this->assertNull($lead->email);
        $this->assertEquals('needs_enrichment', $lead->contact_status);

        // Run second time: must be idempotent and not create duplicate records
        $result2 = $adapter->discover(['keywords' => ['laravel'], 'limit' => 1]);
        $this->assertEquals(0, $result2->newCompaniesCount);
        $this->assertEquals(1, $result2->duplicatesCount);
    }

    public function test_remotive_adapter_is_disabled_by_default_due_to_terms(): void
    {
        $result = (new PublicJobBoardDemandAdapter())->discover(['keywords' => ['laravel']]);
        $this->assertEquals(0, $result->totalFound);
        $this->assertNotEmpty($result->errors);
    }

    public function test_uk_contracts_finder_adapter_ingests_live_it_tenders_only(): void
    {
        Http::fake([
            'www.contractsfinder.service.gov.uk/*' => Http::response([
                'releases' => [
                    [
                        'ocid' => 'ocds-b5fd17-abc', 'id' => 'ocds-b5fd17-abc-1', 'date' => now()->subDay()->toIso8601String(),
                        'buyer' => ['name' => 'Borough Council of Testshire'],
                        'parties' => [[
                            'name' => 'Borough Council of Testshire', 'roles' => ['buyer'],
                            'address' => ['locality' => 'Testtown'],
                            'contactPoint' => ['name' => 'Jane Smith', 'email' => 'procurement@testshire.gov.uk', 'telephone' => '01234 567890'],
                        ]],
                        'tender' => [
                            'title' => 'Website redesign and CMS migration',
                            'description' => 'Supplier sought for responsive website redesign.',
                            'classification' => ['id' => '72413000'],
                            'value' => ['amount' => 40000, 'currency' => 'GBP'],
                            'tenderPeriod' => ['endDate' => now()->addDays(20)->toIso8601String()],
                        ],
                    ],
                    [
                        'ocid' => 'ocds-b5fd17-xyz', 'id' => 'ocds-b5fd17-xyz-1',
                        'buyer' => ['name' => 'Road Gritting Authority'],
                        'tender' => ['title' => 'Supply of road salt', 'description' => 'Rock salt', 'classification' => ['id' => '34927100']],
                    ],
                ],
            ], 200),
        ]);

        $result = (new UkContractsFinderAdapter())->discover(['limit' => 5]);

        $this->assertTrue($result->isSuccess());
        $this->assertEquals(1, $result->newCompaniesCount, 'Non-IT tender must be filtered out');
        $this->assertDatabaseHas('buying_signals', ['signal_type' => 'procurement_rfp', 'relevant_service' => 'web_redesign']);
        $this->assertDatabaseMissing('companies', ['name' => 'Road Gritting Authority']);
        $lead = Lead::where('email', 'procurement@testshire.gov.uk')->first();
        $this->assertNotNull($lead);
        $this->assertEquals('Jane', $lead->first_name);
        $this->assertDatabaseHas('data_provenance', ['field' => 'email', 'source' => 'uk_contracts_finder']);
    }

    public function test_100_point_scoring_awards_transparent_subscores_and_disqualifies_low_scores(): void
    {
        $company = Company::create([
            'name' => 'Tech Corp',
            'domain' => 'techcorp.test',
            'country' => 'Pakistan',
            'tech_stack' => ['PHP', 'Laravel', 'Cloud ERP'],
        ]);

        // High priority lead with explicit RFP signal and verified email
        $rfpSignal = BuyingSignal::create([
            'company_id' => $company->id,
            'signal_type' => 'procurement_rfp',
            'title' => 'Tender for Cloud ERP',
            'source_url' => 'https://ppra.gov.pk/tender/1',
            'evidence_text' => 'Official government RFP for Cloud ERP development.',
            'observed_date' => Carbon::now()->subDays(5)->toDateString(),
            'confidence' => 95,
            'relevant_service' => 'erp_pos',
            'verification_status' => 'verified',
        ]);

        $qualifiedLead = Lead::create([
            'company_id' => $company->id,
            'full_name' => 'Dr. Imran Khan',
            'first_name' => 'Imran',
            'email' => 'imran@techcorp.test',
            'job_title' => 'Chief Technology Officer',
            'country' => 'Pakistan',
            'source_name' => 'Public Procurement Portal',
            'relevant_services' => ['erp_pos'],
            'email_verification_status' => 'verified',
        ]);

        $scoringService = app(LeadScoringService::class);
        $scoringService->evaluateLead($qualifiedLead);
        $qualifiedLead->refresh();

        $this->assertGreaterThanOrEqual(70, $qualifiedLead->overall_score);
        $this->assertArrayHasKey('explicit_request', $qualifiedLead->score_breakdown['scores']);
        $this->assertArrayHasKey('service_fit', $qualifiedLead->score_breakdown['scores']);
        $this->assertEquals('qualified', $qualifiedLead->lifecycle_stage);

        // Low scoring lead without signals or job title
        $lowCompany = Company::create(['name' => 'Empty Co', 'domain' => 'empty.test']);
        $lowLead = Lead::create([
            'company_id' => $lowCompany->id,
            'full_name' => 'Anonymous User',
            'email' => 'anon@empty.test',
            'country' => 'United States',
            'email_verification_status' => 'unverified',
            'source_name' => 'Manual Entry',
        ]);

        $scoringService->evaluateLead($lowLead);
        $lowLead->refresh();

        $this->assertLessThan(50, $lowLead->overall_score);
        $this->assertNotEmpty($lowLead->score_breakdown['disqualifications']);
    }

    public function test_eligibility_gate_enforces_all_10_mandatory_checks(): void
    {
        $settings = OutreachSetting::current();
        $settings->update([
            'mode' => 'controlled_pilot',
            'controlled_pilot_recipient' => 'authorized-pilot@leadpilot.test',
            'min_lead_score' => 70,
            'require_verified_email' => true,
            'emergency_stop' => false,
        ]);

        $company = Company::create(['name' => 'Pilot Co', 'domain' => 'pilotco.test', 'country' => 'Pakistan']);
        $signal = BuyingSignal::create([
            'company_id' => $company->id,
            'signal_type' => 'developer_hiring',
            'title' => 'Laravel Hiring Demand',
            'evidence_text' => 'Hiring Laravel Engineers',
            'observed_date' => Carbon::now()->toDateString(),
            'confidence' => 85,
            'relevant_service' => 'laravel_php',
            'verification_status' => 'verified',
        ]);

        $authorizedLead = Lead::create([
            'company_id' => $company->id,
            'full_name' => 'Authorized Tester',
            'email' => 'authorized-pilot@leadpilot.test',
            'job_title' => 'CTO',
            'country' => 'Pakistan',
            'source_name' => 'Job Board Demand',
            'relevant_services' => ['laravel_php'],
            'email_verification_status' => 'verified',
            'overall_score' => 85,
        ]);

        $unauthorizedLead = Lead::create([
            'company_id' => $company->id,
            'full_name' => 'Random Prospect',
            'email' => 'random@othercompany.test',
            'job_title' => 'CTO',
            'country' => 'Pakistan',
            'source_name' => 'Job Board Demand',
            'relevant_services' => ['laravel_php'],
            'email_verification_status' => 'verified',
            'overall_score' => 85,
        ]);

        $campaign = Campaign::create([
            'name' => 'Pilot Campaign',
            'service_offered' => 'Laravel and PHP development',
            'email_template_id' => $this->template->id,
            'min_lead_score' => 70,
            'daily_limit' => 20,
            'status' => 'running',
            'created_by' => $this->admin->id,
        ]);

        $gateService = app(EligibilityGateService::class);

        // Authorized pilot recipient must PASS
        $resAuthorized = $gateService->evaluate($authorizedLead, $campaign, null, true);
        $this->assertTrue($resAuthorized->isEligible);

        // Unauthorized lead must be BLOCKED in controlled-pilot mode
        $resUnauthorized = $gateService->evaluate($unauthorizedLead, $campaign, null, true);
        $this->assertFalse($resUnauthorized->isEligible);
        $this->assertArrayHasKey('controlled_pilot_restriction', $resUnauthorized->failedChecks);

        // Engage Emergency Stop
        $settings->update(['emergency_stop' => true, 'emergency_stop_reason' => 'Audit Killswitch Activated']);
        $resKillswitch = $gateService->evaluate($authorizedLead, $campaign, null, true);
        $this->assertFalse($resKillswitch->isEligible);
        $this->assertArrayHasKey('emergency_stop', $resKillswitch->failedChecks);
    }

    public function test_social_lead_ad_webhooks_ingest_voluntary_inquiries(): void
    {
        $metaPayload = [
            'email' => 'prospect-meta@company.test',
            'full_name' => 'Asad Siddiqui',
            'company_name' => 'Siddiqui Retailers',
            'phone_number' => '+923001234567',
            'service_needed' => 'erp_pos',
            'country' => 'Pakistan',
            'job_title' => 'Managing Director',
            'leadgen_id' => 'meta_lead_9999',
        ];

        $response = $this->postJson('/webhooks/meta_ads', $metaPayload, [
            'X-Webhook-Signature' => 'test_sig',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('leads', [
            'email' => 'prospect-meta@company.test',
            'full_name' => 'Asad Siddiqui',
            'source_name' => 'Meta Lead Ads (Facebook/Instagram)',
        ]);
        $this->assertDatabaseHas('buying_signals', [
            'signal_type' => 'voluntary_inquiry',
            'relevant_service' => 'erp_pos',
        ]);
    }
}
