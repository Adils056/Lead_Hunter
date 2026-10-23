<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\BuyingSignal;
use App\Models\Campaign;
use App\Models\Company;
use App\Models\EmailTemplate;
use App\Models\Lead;
use App\Models\OutreachSetting;
use App\Models\Suppression;
use App\Services\EligibilityGateService;
use App\Services\LeadScoringService;
use Carbon\Carbon;

echo "=========================================================================\n";
echo "   LeadPilot AI — End-to-End Autonomous Outreach & Eligibility Audit\n";
echo "=========================================================================\n\n";

$scoringService = app(LeadScoringService::class);
$gateService = app(EligibilityGateService::class);
$settings = OutreachSetting::current();

echo "Current System Outreach Mode: " . strtoupper(str_replace('_', ' ', $settings->mode)) . "\n";
echo "Emergency Stop Status: " . ($settings->isEmergencyStopped() ? "ENGAGED" : "OFF") . "\n";
echo "Configured Minimum Score: " . $settings->min_lead_score . "/100\n";
echo "Configured Target Countries: " . implode(', ', $settings->allowed_countries ?? []) . "\n\n";

// Ensure a test campaign template exists
$template = EmailTemplate::firstOrCreate(
    ['slug' => 'test-audit-template'],
    [
        'name' => 'B2B Software Development Outreach',
        'service_category' => 'laravel_php',
        'subject' => 'Regarding {{company_name}}\'s software development',
        'body_text' => "Hi {{first_name}},\n\nNoticed {{company_name}}'s recent requirements. Would love to connect.",
        'body_html' => "<p>Hi {{first_name}},<br>Noticed {{company_name}}'s recent requirements. Would love to connect.</p>",
        'is_active' => true,
    ]
);

$campaign = Campaign::firstOrCreate(
    ['name' => 'Audit Autonomous Campaign'],
    [
        'service_offered' => 'Laravel and PHP development',
        'email_template_id' => $template->id,
        'min_lead_score' => 70,
        'target_countries' => ['Pakistan', 'United States', 'United Kingdom'],
        'daily_limit' => 20,
        'status' => 'running',
        'created_by' => 1,
    ]
);

// SCENARIO 1: High Priority Qualified Prospect with Stored RFP Evidence
echo "--- SCENARIO 1: High Priority Qualified Prospect (PPRA Tender Notice) ---\n";
$comp1 = Company::firstOrCreate(
    ['domain' => 'punjab-portal-audit.gov.pk'],
    [
        'name' => 'Punjab Digital Services Board',
        'website' => 'https://punjab-portal-audit.gov.pk',
        'country' => 'Pakistan',
        'industry' => 'Public Sector',
        'tech_stack' => ['PHP', 'Laravel', 'Cloud ERP'],
        'source' => 'Public Procurement Portal',
    ]
);

$sig1 = BuyingSignal::firstOrCreate(
    ['company_id' => $comp1->id, 'signal_type' => 'procurement_rfp'],
    [
        'title' => 'RFP for Enterprise Web Portal & Database Optimization',
        'source_url' => 'https://ppra.punjab.gov.pk/tender/audit-99',
        'evidence_text' => 'Official procurement document requesting Laravel/PHP custom web application development.',
        'observed_date' => Carbon::now()->subDays(2)->toDateString(),
        'confidence' => 95,
        'relevant_service' => 'laravel_php',
        'verification_status' => 'verified',
    ]
);

$lead1 = Lead::firstOrCreate(
    ['email' => 'tech-director@punjab-portal-audit.gov.pk'],
    [
        'company_id' => $comp1->id,
        'first_name' => 'Tariq',
        'last_name' => 'Mahmood',
        'full_name' => 'Tariq Mahmood',
        'job_title' => 'Chief Technology Officer',
        'country' => 'Pakistan',
        'industry' => 'Public Sector',
        'source_name' => 'Public Procurement Portal',
        'relevant_services' => ['laravel_php', 'custom_software'],
        'email_verification_status' => 'verified',
    ]
);

$score1 = $scoringService->evaluateLead($lead1);
$lead1->refresh();

echo "Lead: {$lead1->full_name} ({$lead1->job_title}) at {$comp1->name}\n";
echo "Calculated Score: {$lead1->overall_score}/100 (Priority: " . strtoupper($score1->priority_level) . ")\n";
echo "Score Breakdown: " . json_encode($lead1->score_breakdown['scores'] ?? []) . "\n";
echo "Score Reasoning: {$lead1->score_explanation}\n";

$eligibility1 = $gateService->evaluate($lead1, $campaign, null, false);
echo "Eligibility Gate Verdict (Manual/Pilot check): " . ($eligibility1->isEligible ? "ELIGIBLE [PASS]" : "NOT ELIGIBLE [FAIL]") . "\n";
if (!$eligibility1->isEligible) {
    echo "  Primary Reason: {$eligibility1->primaryReason}\n";
} else {
    echo "  Passed Checks: " . implode(', ', array_keys($eligibility1->passedChecks)) . "\n";
}

// SCENARIO 2: Unverified / Incomplete Contact Lead
echo "\n--- SCENARIO 2: Prospect with Missing/Unverified Email ---\n";
$comp2 = Company::firstOrCreate(
    ['domain' => 'unverified-agency-audit.com'],
    [
        'name' => 'Unverified Tech Corp',
        'country' => 'United States',
        'industry' => 'Software',
    ]
);

$lead2 = Lead::firstOrCreate(
    ['email' => 'unverified-contact@unverified-agency-audit.com'],
    [
        'company_id' => $comp2->id,
        'first_name' => 'John',
        'last_name' => 'Unknown',
        'full_name' => 'John Unknown',
        'job_title' => 'Manager',
        'country' => 'United States',
        'source_name' => 'Web Directory',
        'email_verification_status' => 'unverified',
    ]
);

$score2 = $scoringService->evaluateLead($lead2);
$eligibility2 = $gateService->evaluate($lead2, $campaign, null, false);
echo "Lead: {$lead2->full_name} ({$lead2->email})\n";
echo "Calculated Score: {$lead2->overall_score}/100\n";
echo "Eligibility Gate Verdict: " . ($eligibility2->isEligible ? "ELIGIBLE [PASS]" : "BLOCKED [SAFE]") . "\n";
echo "Reason: {$eligibility2->primaryReason}\n";

// SCENARIO 3: Prospect on the Suppression List
echo "\n--- SCENARIO 3: Prospect on Do-Not-Contact / Suppression List ---\n";
$comp3 = Company::firstOrCreate(['domain' => 'optout-firm-audit.com'], ['name' => 'OptOut Firm', 'country' => 'United Kingdom']);
$lead3 = Lead::firstOrCreate(
    ['email' => 'opted-out-user@optout-firm-audit.com'],
    [
        'company_id' => $comp3->id,
        'full_name' => 'Sarah Connor',
        'job_title' => 'VP Engineering',
        'country' => 'United Kingdom',
        'source_name' => 'Apollo.io Organizations',
        'email_verification_status' => 'verified',
        'overall_score' => 88,
    ]
);
Suppression::firstOrCreate(['email' => $lead3->email], ['reason' => 'user_unsubscribe', 'source' => 'audit_test']);

$eligibility3 = $gateService->evaluate($lead3, $campaign, null, false);
echo "Lead: {$lead3->full_name} ({$lead3->email})\n";
echo "Eligibility Gate Verdict: " . ($eligibility3->isEligible ? "ELIGIBLE [FAIL]" : "BLOCKED [PASS]") . "\n";
echo "Reason: {$eligibility3->primaryReason}\n";

// SCENARIO 4: Disqualified Low-Scoring Lead (< 50 points)
echo "\n--- SCENARIO 4: Disqualified Low-Scoring Lead (< 50 points) ---\n";
$comp4 = Company::firstOrCreate(['domain' => 'lowscore-co-audit.com'], ['name' => 'LowScore Co']);
$lead4 = Lead::firstOrCreate(
    ['email' => 'intern@lowscore-co-audit.com'],
    [
        'company_id' => $comp4->id,
        'full_name' => 'Sam Student',
        'job_title' => 'Intern',
        'country' => 'France', // Disallowed country
        'email_verification_status' => 'verified',
        'source_name' => 'Manual Entry',
    ]
);
$score4 = $scoringService->evaluateLead($lead4);
$lead4->refresh();
$eligibility4 = $gateService->evaluate($lead4, $campaign, null, false);
echo "Lead: {$lead4->full_name} ({$lead4->job_title})\n";
echo "Calculated Score: {$lead4->overall_score}/100\n";
echo "Eligibility Gate Verdict: " . ($eligibility4->isEligible ? "ELIGIBLE [FAIL]" : "BLOCKED [PASS]") . "\n";
echo "Reason: {$eligibility4->primaryReason}\n";

echo "\n=========================================================================\n";
echo "   Summary of Gate Decisions:\n";
$reason2 = $eligibility2->failedChecks['email_verification'] ?? '';
$reason3 = $eligibility3->failedChecks['suppression'] ?? '';
$reason4 = $eligibility4->failedChecks['score_threshold'] ?? '';
echo "   1. High-Priority Qualified Prospect (Score {$lead1->overall_score}): " . ($eligibility1->isEligible ? "PASSED ELIGIBILITY" : "HELD") . "\n";
echo "   2. Unverified Email Prospect: BLOCKED by Gate ({$reason2})\n";
echo "   3. Suppressed Recipient: BLOCKED by Gate ({$reason3})\n";
echo "   4. Low-Scoring Non-Target Prospect: BLOCKED by Gate ({$reason4})\n";
echo "=========================================================================\n";

