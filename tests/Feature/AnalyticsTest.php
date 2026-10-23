<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Company;
use App\Models\Deal;
use App\Models\EmailTemplate;
use App\Models\InboundReply;
use App\Models\Lead;
use App\Models\Meeting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnalyticsTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $sales;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->sales = User::factory()->create([
            'role' => 'sales',
            'is_active' => true,
        ]);
    }

    public function test_analytics_dashboard_loads_with_zero_division_protection(): void
    {
        // When there is no data at all in the database, division by zero must not occur
        $response = $this->actingAs($this->admin)->get('/analytics');

        $response->assertStatus(200);
        $response->assertViewHas('totalLeads', 0);
        $response->assertViewHas('verificationRate', 0);
        $response->assertViewHas('qualificationRate', 0);
        $response->assertViewHas('deliveryRate', 0);
        $response->assertViewHas('bounceRate', 0);
        $response->assertViewHas('replyRate', 0);
        $response->assertViewHas('positiveRate', 0);
    }

    public function test_analytics_calculates_conversion_metrics_accurately(): void
    {
        $company = Company::create([
            'name' => 'Data Metrics Corp',
            'country' => 'United States',
            'domain' => 'datametricscorp.com',
        ]);

        // 2 verified leads, 1 unverified
        $lead1 = Lead::create([
            'company_id' => $company->id,
            'full_name' => 'Alice Green',
            'email' => 'alice@datametricscorp.com',
            'email_verification_status' => 'verified',
            'overall_score' => 85,
            'country' => 'United States',
            'source_name' => 'LinkedIn Jobs',
        ]);

        $lead2 = Lead::create([
            'company_id' => $company->id,
            'full_name' => 'Bob White',
            'email' => 'bob@datametricscorp.com',
            'email_verification_status' => 'verified',
            'overall_score' => 65,
            'country' => 'United Kingdom',
            'source_name' => 'LinkedIn Jobs',
        ]);

        $lead3 = Lead::create([
            'company_id' => $company->id,
            'full_name' => 'Charlie Gray',
            'email' => 'charlie@datametricscorp.com',
            'email_verification_status' => 'unverified',
            'overall_score' => 20,
            'country' => 'Germany',
            'source_name' => 'Apollo.io',
        ]);

        // Deal won
        Deal::create([
            'lead_id' => $lead1->id,
            'company_id' => $company->id,
            'owner_id' => $this->sales->id,
            'title' => 'Laravel Cloud Enterprise Retainer',
            'value' => 45000.00,
            'stage' => 'won',
            'closed_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)->get('/analytics');

        $response->assertStatus(200);
        $response->assertViewHas('totalLeads', 3);
        $response->assertViewHas('verifiedLeads', 2);
        $response->assertViewHas('qualifiedLeads', 2); // score >= 60
        $response->assertViewHas('wonDeals', 1);
        $response->assertViewHas('wonRevenue', 45000.00);
        // (2 / 3) * 100 = 66.7%
        $response->assertViewHas('verificationRate', 66.7);
        $response->assertViewHas('qualificationRate', 66.7);
    }

    public function test_analytics_csv_report_export(): void
    {
        Lead::create([
            'full_name' => 'Export Tester',
            'email' => 'export@example.com',
            'source_name' => 'Direct RFP',
            'overall_score' => 90,
        ]);

        $response = $this->actingAs($this->admin)->get('/analytics/export?days=30');

        $response->assertStatus(200);
        $this->assertStringContainsString('text/csv', strtolower((string) $response->headers->get('Content-Type')));
        $this->assertTrue(str_contains($response->headers->get('Content-Disposition'), 'leadpilot_analytics_report_'));

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'analytics_report_exported',
        ]);
    }

    public function test_crm_pipeline_renders_and_updates_lead_stage(): void
    {
        $lead = Lead::create([
            'full_name' => 'Diana Prince',
            'email' => 'diana@themyscira.com',
            'lifecycle_stage' => 'qualified',
            'overall_score' => 80,
            'assigned_user_id' => $this->sales->id,
        ]);

        // Access pipeline kanban
        $kanban = $this->actingAs($this->sales)->get('/crm/pipeline');
        $kanban->assertStatus(200);
        $kanban->assertSee('Diana Prince');

        // Move stage via POST /crm/pipeline/{lead}/stage
        $post = $this->actingAs($this->sales)->post("/crm/pipeline/{$lead->id}/stage", [
            'lifecycle_stage' => 'proposal_sent',
        ]);

        $post->assertRedirect();
        $lead->refresh();
        $this->assertEquals('proposal_sent', $lead->lifecycle_stage);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'lead_stage_changed',
            'auditable_id' => $lead->id,
        ]);
    }
}
