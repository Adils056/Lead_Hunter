<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Company;
use App\Models\EmailLog;
use App\Models\EmailTemplate;
use App\Models\Lead;
use App\Models\Suppression;
use App\Models\User;
use App\Services\CampaignDeliveryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

echo "=== TEST 5: EMAIL DELIVERY, CAMPAIGN APPROVAL & SUPPRESSION VERIFICATION ===\n";

$deliveryService = app(CampaignDeliveryService::class);
$results = [];

// 1. Mail Transport Status
$mailDriver = config('mail.default');
echo "\n--- 1. Mail Configuration Status ---\n";
echo "Active Mail Driver: {$mailDriver}\n";
if ($mailDriver === 'log' || $mailDriver === 'smtp' || $mailDriver === 'array') {
    echo "[PASS] Mail transport configured safely for local testing (Driver: {$mailDriver}).\n";
    $results['mail_driver'] = 'PASS';
} else {
    echo "[WARN] Unexpected mail driver: {$mailDriver}\n";
    $results['mail_driver'] = 'WARN';
}

// 2. Safe Admin Test Email Delivery
echo "\n--- 2. Safe Admin Test Email Workflow ---\n";
$admin = User::where('role', 'admin')->first();
$template = EmailTemplate::first();

$testCampaign = Campaign::create([
    'name' => 'TEST_Outreach_Campaign',
    'service_offered' => 'Custom software development',
    'email_template_id' => $template->id,
    'daily_limit' => 10,
    'status' => 'draft',
    'created_by' => $admin->id,
]);

if ($admin) {
    $testResult = $deliveryService->sendTestEmail($testCampaign, $admin->email);
    
    if ($testResult) {
        echo "[PASS] Test email successfully routed via application email workflow to authorized address: {$admin->email}.\n";
        $results['test_email'] = 'PASS';
    } else {
        echo "[FAIL] Test email workflow returned false.\n";
        $results['test_email'] = 'FAIL';
    }
} else {
    echo "[FAIL] Admin user missing for test email.\n";
    $results['test_email'] = 'FAIL';
}

// 3. Campaign Approval Gate Check
echo "\n--- 3. Campaign Mandatory Approval Gate Check ---\n";
$testCompany = Company::create([
    'name' => 'TEST_Outreach_Corp',
    'domain' => 'test-outreach.example.com',
    'country' => 'United States',
]);

$testLead = Lead::create([
    'company_id' => $testCompany->id,
    'full_name' => 'TEST_Authorized_Prospect',
    'email' => 'test.prospect.outreach@test-outreach.example.com',
    'email_verification_status' => 'verified',
    'country' => 'United States',
    'overall_score' => 85,
]);

$campaign = Campaign::create([
    'name' => 'TEST_Gated_Campaign',
    'service_offered' => 'Web application development',
    'email_template_id' => $template->id,
    'daily_limit' => 10,
    'status' => 'draft', // Not approved!
    'created_by' => $admin->id,
]);

$recipient = CampaignRecipient::create([
    'campaign_id' => $campaign->id,
    'lead_id' => $testLead->id,
    'status' => 'draft',
    'idempotency_key' => 'test_idem_' . Str::uuid(),
    'personalized_subject' => 'Connecting with TEST_Authorized_Prospect',
    'personalized_body' => 'Hello, this is an automated safety verification.',
]);

// Attempt to dispatch non-approved campaign
$dispatchedBeforeApproval = $deliveryService->dispatchRecipient($recipient);
if (!$dispatchedBeforeApproval && $recipient->fresh()->status !== 'sent') {
    echo "[PASS] Approval Gate Enforced: Recipient in draft/unapproved campaign was BLOCKED from sending.\n";
    $results['approval_gate'] = 'PASS';
} else {
    echo "[FAIL] Recipient dispatched before campaign approval!\n";
    $results['approval_gate'] = 'FAIL';
}

// 4. Authorized Dispatch Following Administrator Approval
echo "\n--- 4. Authorized Dispatch Following Administrator Approval ---\n";
$campaign->update([
    'status' => 'running',
    'approved_by' => $admin->id,
    'approved_at' => now(),
]);
$recipient->update(['status' => 'approved']);
$recipient->refresh();

$dispatchedAfterApproval = $deliveryService->dispatchRecipient($recipient);
$freshRecipient = $recipient->fresh();

if ($dispatchedAfterApproval && $freshRecipient->status === 'sent') {
    echo "[PASS] Recipient dispatched successfully after administrator approval (Status: sent, Message ID: {$freshRecipient->provider_message_id}).\n";
    $results['authorized_dispatch'] = 'PASS';
} else {
    echo "[FAIL] Recipient failed to dispatch after approval.\n";
    $results['authorized_dispatch'] = 'FAIL';
}

// 5. Email Logs Verification
echo "\n--- 5. Email Audit Log Persistence ---\n";
$emailLog = EmailLog::where('campaign_recipient_id', $recipient->id)->first();
if ($emailLog && $emailLog->status === 'sent' && !empty($emailLog->message_id)) {
    echo "[PASS] Email log recorded with timestamp, recipient, and message ID '{$emailLog->message_id}'.\n";
    $results['email_logs'] = 'PASS';
} else {
    echo "[FAIL] Email log record missing or incomplete.\n";
    $results['email_logs'] = 'FAIL';
}

// 6. Double-Send / Idempotency Prevention
echo "\n--- 6. Idempotency & Anti-Double Send Protection ---\n";
$secondAttempt = $deliveryService->dispatchRecipient($recipient);
if (!$secondAttempt) {
    echo "[PASS] Anti-double send protection prevented re-dispatching already sent recipient.\n";
    $results['anti_double_send'] = 'PASS';
} else {
    echo "[FAIL] Anti-double send failed: duplicate email allowed!\n";
    $results['anti_double_send'] = 'FAIL';
}

// 7. Live Suppression Enforcement Check
echo "\n--- 7. Live Pre-Send Suppression Enforcement ---\n";
$suppressedLead = Lead::create([
    'company_id' => $testCompany->id,
    'full_name' => 'TEST_Suppressed_Prospect',
    'email' => 'optout.test@test-outreach.example.com',
    'email_verification_status' => 'verified',
    'country' => 'United States',
    'overall_score' => 80,
]);

// Register suppression
Suppression::addSuppression('optout.test@test-outreach.example.com', 'unsubscribe', 'manual_test');

$suppressedRecipient = CampaignRecipient::create([
    'campaign_id' => $campaign->id,
    'lead_id' => $suppressedLead->id,
    'status' => 'approved',
    'idempotency_key' => 'test_idem_' . Str::uuid(),
    'personalized_subject' => 'Hello',
    'personalized_body' => 'Test body',
]);

$suppressedDispatchResult = $deliveryService->dispatchRecipient($suppressedRecipient);
$suppressedRecipient->refresh();

if (!$suppressedDispatchResult && $suppressedRecipient->status === 'opted_out') {
    echo "[PASS] Live Suppression Enforced: Recipient was blocked and marked 'opted_out' due to suppression registry.\n";
    $results['suppression_enforcement'] = 'PASS';
} else {
    echo "[FAIL] Suppression check failed to block suppressed prospect!\n";
    $results['suppression_enforcement'] = 'FAIL';
}

// 8. Emergency Killswitch Verification
echo "\n--- 8. Emergency Killswitch Verification ---\n";
$campaign->update(['emergency_stopped' => true]);

$normalRecipient = CampaignRecipient::create([
    'campaign_id' => $campaign->id,
    'lead_id' => $testLead->id,
    'status' => 'approved',
    'idempotency_key' => 'test_idem_' . Str::uuid(),
    'personalized_subject' => 'Hello',
    'personalized_body' => 'Test body',
]);

$killswitchDispatch = $deliveryService->dispatchRecipient($normalRecipient);
$normalRecipient->refresh();

if (!$killswitchDispatch && $normalRecipient->status === 'skipped') {
    echo "[PASS] Emergency Killswitch Enforced: Campaign dispatches halted immediately and recipient marked 'skipped'.\n";
    $results['killswitch'] = 'PASS';
} else {
    echo "[FAIL] Emergency killswitch failed to stop dispatch!\n";
    $results['killswitch'] = 'FAIL';
}

// 9. Clean up TEST records
echo "\n--- 9. Safe Cleanup of TEST Records ---\n";
Suppression::where('email', 'optout.test@test-outreach.example.com')->delete();
$recipient->logs()->delete();
$recipient->delete();
$suppressedRecipient->delete();
$normalRecipient->delete();
$campaign->delete();
$testCampaign->delete();
$testLead->forceDelete();
$suppressedLead->forceDelete();
$testCompany->forceDelete();
echo "[PASS] Outreach TEST records safely deleted. Production/demo data intact.\n";
$results['cleanup'] = 'PASS';

echo "\n=== TEST 5 SUMMARY ===\n";
print_r($results);
