<?php

namespace Tests\Feature;

use App\Models\BuyingSignal;
use App\Models\Company;
use App\Models\Lead;
use App\Models\User;
use App\Services\LeadScoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadScoringTest extends TestCase
{
    use RefreshDatabase;

    public function test_100_point_scoring_engine_awards_points_for_signals_and_decision_maker(): void
    {
        $company = Company::create([
            'name' => 'High Intent Enterprise Ltd',
            'country' => 'United States',
            'domain' => 'highintent.com',
            'industry' => 'Financial Technology',
            'size_range' => '51-200',
        ]);

        // Explicit project requirement signal (up to 30 pts)
        BuyingSignal::create([
            'company_id' => $company->id,
            'signal_type' => 'project_requirements',
            'title' => 'RFP for Turnkey Laravel Backend Modernization',
            'evidence_text' => 'Direct RFP published seeking senior agency partner.',
            'observed_date' => now()->toDateString(),
            'confidence' => 95,
            'relevant_service' => 'Laravel and PHP development',
            'verification_status' => 'verified',
        ]);

        // Developer hiring signal (up to 20 pts)
        BuyingSignal::create([
            'company_id' => $company->id,
            'signal_type' => 'developer_hiring',
            'title' => 'Recruiting Lead Laravel Architect',
            'evidence_text' => 'Immediate requirement for backend specialists.',
            'observed_date' => now()->toDateString(),
            'confidence' => 90,
            'relevant_service' => 'Laravel and PHP development',
            'verification_status' => 'verified',
        ]);

        $lead = Lead::create([
            'company_id' => $company->id,
            'full_name' => 'Dr. Robert Ford',
            'job_title' => 'Chief Technology Officer', // Decision maker (15 pts)
            'email' => 'rford@highintent.com',
            'email_verification_status' => 'verified', // (+6 pts)
            'country' => 'United States',
            'relevant_services' => ['Laravel and PHP development'],
        ]);

        $scoringService = app(LeadScoringService::class);
        $score = $scoringService->evaluateLead($lead);

        $this->assertGreaterThanOrEqual(75, $score->overall_score);
        $this->assertEquals('high', $score->priority_level);
        $this->assertArrayHasKey('explicit_request', $score->breakdown);
        $this->assertArrayHasKey('technical_hiring', $score->breakdown);
        $this->assertArrayHasKey('decision_maker_identified', $score->breakdown);
        $this->assertDatabaseHas('lead_scores', ['lead_id' => $lead->id]);
    }

    public function test_rescore_endpoint_updates_lead(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $lead = Lead::create([
            'full_name' => 'Jane Smith',
            'email' => 'jane@example.com',
            'country' => 'United Kingdom',
            'overall_score' => 10,
        ]);

        $response = $this->actingAs($admin)->post("/leads/{$lead->id}/rescore");
        $response->assertSessionHas('success');

        $lead->refresh();
        $this->assertNotNull($lead->scored_at);
    }
}
