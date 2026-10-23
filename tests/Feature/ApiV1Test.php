<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiV1Test extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
        ]);
        $this->actingAs($this->admin); // the API is session-authenticated since the Phase 0 hotfix
    }

    public function test_api_v1_dashboard_returns_real_kpis(): void
    {
        $company = Company::create([
            'name' => 'API Test Corp',
            'country' => 'United States',
            'domain' => 'apitestcorp.com',
        ]);

        Lead::create([
            'company_id' => $company->id,
            'full_name' => 'John API',
            'email' => 'john@apitestcorp.com',
            'email_verification_status' => 'verified',
            'overall_score' => 88,
            'lifecycle_stage' => 'qualified',
        ]);

        $response = $this->getJson('/api/v1/dashboard');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'status',
            'data' => [
                'total_companies',
                'total_leads',
                'qualified_leads',
                'running_campaigns',
                'buying_signals_active',
            ]
        ]);
        $response->assertJson([
            'status' => 'success',
            'data' => [
                'total_leads' => 1,
                'qualified_leads' => 1,
            ]
        ]);
    }

    public function test_api_v1_leads_and_filtering(): void
    {
        $company = Company::create([
            'name' => 'Filter Test Corp',
            'country' => 'United Kingdom',
            'domain' => 'filtertest.co.uk',
        ]);

        Lead::create([
            'company_id' => $company->id,
            'full_name' => 'Jane UK',
            'email' => 'jane@filtertest.co.uk',
            'country' => 'United Kingdom',
            'overall_score' => 92,
        ]);

        $response = $this->getJson('/api/v1/leads?country=United%20Kingdom');

        $response->assertStatus(200);
        $response->assertJson(['status' => 'success']);
        $this->assertCount(1, $response->json('data.data'));
        $this->assertEquals('Jane UK', $response->json('data.data.0.full_name'));
    }

    public function test_api_v1_ai_research_returns_structured_analysis(): void
    {
        $company = Company::create([
            'name' => 'Fintech Vanguard Inc',
            'country' => 'United States',
            'domain' => 'fintechvanguard.com',
            'industry' => 'Financial Technology',
        ]);

        $response = $this->postJson('/api/v1/ai/research', [
            'company_id' => $company->id,
        ]);

        $response->assertStatus(200);
        $response->assertJson(['status' => 'success']);
        $response->assertJsonStructure([
            'status',
            'data' => [
                'business_summary',
                'potential_software_needs',
                'recommended_agency_service',
                'personalized_outreach_angle',
            ]
        ]);
    }
}
