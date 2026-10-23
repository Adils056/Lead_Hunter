<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\BuyingSignal;
use App\Models\Company;
use App\Models\Lead;
use App\Models\LeadScore;
use App\Services\AiResearchService;
use App\Services\LeadScoringService;

echo "=== TEST 4: AI RESEARCH & EVIDENCE-BASED SCORING VERIFICATION ===\n";

$aiService = app(AiResearchService::class);
$scoringService = app(LeadScoringService::class);

$results = [];

// 1. Check Configuration & Availability
echo "\n--- 1. Configuration & Key Detection ---\n";
$isAiAvailable = $aiService->isAvailable();
echo "OpenAI Configured & Available: " . ($isAiAvailable ? "YES" : "NO (No valid OPENAI_API_KEY in .env)") . "\n";
if (!$isAiAvailable) {
    echo "[NOTE] OPENAI_API_KEY is empty in .env. Live OpenAI API calls are BLOCKED pending user credential.\n";
    $results['live_ai_call'] = 'BLOCKED_NO_KEY';
} else {
    $results['live_ai_call'] = 'CONFIGURED';
}

// 2. Structured Analysis & Truthful Fallback Verification
echo "\n--- 2. Structured Analysis & Truthful Fallback Verification ---\n";
$company = Company::create([
    'name' => 'TEST_Evidence_Fintech_LLC',
    'domain' => 'test-fintech-evidence.example.com',
    'country' => 'United States',
    'industry' => 'Financial Technology',
    'size_range' => '51-200',
]);

// Add a real, verified signal to provide evidence
$signal = BuyingSignal::create([
    'company_id' => $company->id,
    'signal_type' => 'project_requirements',
    'title' => 'RFP for Core Banking Portal Modernization',
    'evidence_text' => 'Public procurement notice published for modern responsive architecture.',
    'observed_date' => now()->toDateString(),
    'confidence' => 95,
    'relevant_service' => 'Laravel and PHP development',
    'verification_status' => 'verified',
]);

$research = $aiService->researchCompany($company);

echo "Research Returned Keys: " . implode(', ', array_keys($research)) . "\n";
$hasRequiredKeys = isset(
    $research['business_summary'],
    $research['potential_software_needs'],
    $research['recommended_agency_service'],
    $research['supporting_evidence'],
    $research['missing_information']
);

if ($hasRequiredKeys) {
    echo "[PASS] Structured analysis schema verified.\n";
    echo "  - Recommended Agency Service: {$research['recommended_agency_service']}\n";
    echo "  - Supporting Evidence: {$research['supporting_evidence']}\n";
    echo "  - Explicit Unknowns/Missing Info: {$research['missing_information']}\n";
    $results['structured_schema'] = 'PASS';
} else {
    echo "[FAIL] Missing required keys in research payload.\n";
    $results['structured_schema'] = 'FAIL';
}

// Check that missing info is represented as unknown, not fabricated
if (!empty($research['missing_information']) && str_contains(strtolower($research['missing_information']), 'budget')) {
    echo "[PASS] Missing budget/cycle evidence is truthfully acknowledged as requiring confirmation, not fabricated.\n";
    $results['non_fabrication'] = 'PASS';
} else {
    echo "[FAIL] Missing info not clearly acknowledged.\n";
    $results['non_fabrication'] = 'FAIL';
}

// 3. Evidence-Based 100-Point Scoring Engine Verification
echo "\n--- 3. 100-Point Evidence-Based Scoring Calculation ---\n";
$lead = Lead::create([
    'company_id' => $company->id,
    'full_name' => 'TEST_VP_Engineering',
    'job_title' => 'VP of Engineering', // Decision Maker (15 pts)
    'email' => 'vp.eng@test-fintech-evidence.example.com',
    'email_verification_status' => 'verified', // (+6 pts deliverable)
    'country' => 'United States',
    'relevant_services' => ['Laravel and PHP development'],
]);

$leadScore = $scoringService->evaluateLead($lead);

echo "Calculated Score: {$leadScore->overall_score} / 100 (Priority: {$leadScore->priority_level})\n";
echo "Breakdown: " . json_encode($leadScore->breakdown) . "\n";
echo "Evidence-Based Explanation: {$leadScore->reasoning}\n";

$scoreInRange = $leadScore->overall_score >= 0 && $leadScore->overall_score <= 100;
$breakdownSum = array_sum($leadScore->breakdown);
$hasExplanation = !empty($leadScore->reasoning);

if ($scoreInRange && $breakdownSum === $leadScore->overall_score && $hasExplanation) {
    echo "[PASS] 100-point transparent scoring engine accurately computed score with exact breakdown and explanation.\n";
    $results['scoring_accuracy'] = 'PASS';
} else {
    echo "[FAIL] Scoring mismatch or explanation missing.\n";
    $results['scoring_accuracy'] = 'FAIL';
}

// 4. Persistence of Lead Score Record
$persistedScore = LeadScore::where('lead_id', $lead->id)->latest()->first();
if ($persistedScore && $persistedScore->overall_score === $leadScore->overall_score) {
    echo "[PASS] Score record successfully persisted to lead_scores table with audit timestamp.\n";
    $results['score_persistence'] = 'PASS';
} else {
    echo "[FAIL] Score record was not saved to lead_scores table.\n";
    $results['score_persistence'] = 'FAIL';
}

// 5. Cleanup TEST records
$leadScore->delete();
$lead->forceDelete();
$signal->forceDelete();
$company->forceDelete();
echo "\n[CLEANUP] TEST records safely removed.\n";

echo "\n=== TEST 4 SUMMARY ===\n";
print_r($results);
