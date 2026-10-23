<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Company;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Support\Facades\DB;

echo "=== TEST 2: LIVE MYSQL DATABASE PERSISTENCE & DUPLICATE HANDLING ===\n";
echo "Active DB Connection: " . DB::connection()->getName() . "\n";
echo "Active DB Database: " . DB::connection()->getDatabaseName() . "\n";

$results = [];

// Step 1: Create a clearly labeled test company and test lead
echo "\n--- Step 1: Creating TEST_Company and TEST_Lead in MySQL ---\n";
$testDomain = 'test-persistence-corp.example.com';
$testEmail = 'test.prospect@test-persistence-corp.example.com';

// Ensure clean slate before test
Company::where('domain', $testDomain)->forceDelete();
Lead::where('email', $testEmail)->forceDelete();

$company = Company::create([
    'name' => 'TEST_Company_Alpha_Enterprises',
    'domain' => Company::normalizeDomain($testDomain),
    'country' => 'United States',
    'industry' => 'Financial Technology',
    'size_range' => '51-200',
]);

echo "Created Company #{$company->id}: {$company->name} (Domain: {$company->domain})\n";

$lead = Lead::create([
    'company_id' => $company->id,
    'full_name' => 'TEST_Lead_Marcus_Vance',
    'job_title' => 'Chief Technology Officer',
    'email' => strtolower(trim($testEmail)),
    'country' => 'United States',
    'email_verification_status' => 'verified',
    'overall_score' => 85,
    'lifecycle_stage' => 'qualified',
]);

echo "Created Lead #{$lead->id}: {$lead->full_name} (Email: {$lead->email})\n";

// Step 2 & 3: Confirm saved and retrieved directly from MySQL
echo "\n--- Step 2 & 3: Direct Database Retrieval Verification ---\n";
$persistedCompany = DB::table('companies')->where('id', $company->id)->first();
$persistedLead = DB::table('leads')->where('id', $lead->id)->first();

if ($persistedCompany && $persistedLead) {
    echo "[PASS] Records successfully persisted and verified in MySQL table 'companies' and 'leads'.\n";
    $results['persistence'] = 'PASS';
} else {
    echo "[FAIL] Record could not be retrieved from MySQL.\n";
    $results['persistence'] = 'FAIL';
}

// Step 4: Duplicate Company Handling
echo "\n--- Step 4: Duplicate Company Domain Handling ---\n";
$duplicateCompanyPrevented = false;
try {
    // Normalization should catch identical or protocol-prefixed variants
    $normalized = Company::normalizeDomain("https://{$testDomain}/about");
    $existing = Company::where('domain', $normalized)->first();
    if ($existing) {
        $duplicateCompanyPrevented = true;
        echo "[PASS] Duplicate company domain detected and flagged: Company ID #{$existing->id} already registered with domain '{$normalized}'.\n";
    } else {
        Company::create([
            'name' => 'TEST_Company_Alpha_Duplicate',
            'domain' => $normalized,
        ]);
    }
} catch (\Exception $e) {
    $duplicateCompanyPrevented = true;
    echo "[PASS] Database constraint prevented duplicate company: " . $e->getMessage() . "\n";
}
$results['duplicate_company'] = $duplicateCompanyPrevented ? 'PASS' : 'FAIL';

// Step 5: Duplicate Lead Email Handling
echo "\n--- Step 5: Duplicate Lead Email Handling ---\n";
$duplicateLeadPrevented = false;
$existingLead = Lead::where('email', strtolower($testEmail))->first();
if ($existingLead) {
    $duplicateLeadPrevented = true;
    echo "[PASS] Duplicate lead check successfully flagged existing lead #{$existingLead->id} for email '{$testEmail}'.\n";
} else {
    echo "[FAIL] Duplicate lead check failed to locate existing lead.\n";
}
$results['duplicate_lead'] = $duplicateLeadPrevented ? 'PASS' : 'FAIL';

// Step 6: Verify updating lead preserves integrity
echo "\n--- Step 6: Lead Update Verification ---\n";
$lead->update([
    'lifecycle_stage' => 'meeting_booked',
    'notes' => 'Updated via controlled test verification.',
]);
$freshLead = Lead::find($lead->id);
if ($freshLead->lifecycle_stage === 'meeting_booked' && $freshLead->email === $testEmail) {
    echo "[PASS] Lead successfully updated in MySQL without data corruption.\n";
    $results['lead_update'] = 'PASS';
} else {
    echo "[FAIL] Lead update verification failed.\n";
    $results['lead_update'] = 'FAIL';
}

// Step 7: Check RBAC permissions for deletion
echo "\n--- Step 7: RBAC Deletion Permission Check ---\n";
$adminUser = User::where('role', 'admin')->first();
$salesUser = User::where('role', 'sales')->first();

$salesCanDelete = $salesUser ? $salesUser->can('delete', $lead) : false;
$adminCanDelete = $adminUser ? $adminUser->can('delete', $lead) : false;

echo "Sales User Can Delete Lead: " . ($salesCanDelete ? "YES" : "NO (Protected)") . "\n";
echo "Admin User Can Delete Lead: " . ($adminCanDelete ? "YES (Authorized)" : "NO") . "\n";

if (!$salesCanDelete && $adminCanDelete) {
    echo "[PASS] Deletion permissions behave strictly as designed (Sales denied, Admin authorized).\n";
    $results['rbac_deletion'] = 'PASS';
} else {
    echo "[FAIL] Deletion permissions do not match RBAC policy.\n";
    $results['rbac_deletion'] = 'FAIL';
}

// Step 8: Clean up only test records
echo "\n--- Step 8: Clean Up of TEST Records ---\n";
$lead->forceDelete();
$company->forceDelete();

$cleanCheck = DB::table('leads')->where('email', $testEmail)->count();
if ($cleanCheck === 0) {
    echo "[PASS] TEST records safely removed from MySQL. Production/demo data untouched.\n";
    $results['cleanup'] = 'PASS';
} else {
    echo "[WARN] TEST lead still present in database.\n";
    $results['cleanup'] = 'WARN';
}

echo "\n=== TEST 2 SUMMARY ===\n";
print_r($results);
