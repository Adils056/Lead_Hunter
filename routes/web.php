<?php

use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BuyingSignalController;
use App\Http\Controllers\CampaignController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\CrmPipelineController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\IntegrationController;
use App\Http\Controllers\LeadController;
use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

// Root redirect
Route::get('/', function () {
    return redirect()->route('dashboard');
});

// Authentication Routes
Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
Route::post('/login', [AuthController::class, 'login'])->name('login.post')->middleware('throttle:5,1'); // 5 attempts/min per IP
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
// Self-service registration (LEADPILOT_ALLOW_REGISTRATION; the first account of a fresh install becomes admin)
Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
Route::post('/register', [AuthController::class, 'register'])->name('register.post')->middleware('throttle:10,1');

// Public Unsubscribe Endpoint
Route::match(['get', 'post'], '/unsubscribe', [CampaignController::class, 'unsubscribe'])->name('campaigns.unsubscribe')->middleware('throttle:30,1');

// Inbound Email & Delivery Provider Webhooks (CSRF exempted)
Route::post('/webhooks/{provider}', [WebhookController::class, 'handle'])->name('webhooks.handle')->middleware('throttle:60,1'); // HMAC-verified in the controller

// Authenticated Application Routes
Route::middleware(['auth'])->group(function () {
    // User Profile
    Route::get('/profile', [AuthController::class, 'profile'])->name('profile');
    Route::post('/profile', [AuthController::class, 'updateProfile'])->name('profile.update');

    // Main Executive Dashboard
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Companies
    Route::get('/companies/export', [CompanyController::class, 'export'])->name('companies.export');
    Route::get('/companies/lookup', [CompanyController::class, 'lookup'])->name('companies.lookup');
    Route::post('/companies/{company}/research', [CompanyController::class, 'triggerAiResearch'])->name('companies.research');
    Route::resource('companies', CompanyController::class)->except(['destroy']);

    // Leads & Contacts
    Route::get('/leads/export', [LeadController::class, 'export'])->name('leads.export');
    Route::get('/leads/win-ladder', [LeadController::class, 'winLadder'])->name('leads.win-ladder');
    Route::get('/leads/win-ladder/export', [LeadController::class, 'winLadderExport'])->name('leads.win-ladder.export');
    Route::post('/leads/win-ladder/refresh', [LeadController::class, 'winLadderRefresh'])->name('leads.win-ladder.refresh');
    Route::get('/leads/import', [LeadController::class, 'showImport'])->name('leads.import');
    Route::post('/leads/import/preview', [LeadController::class, 'previewImport'])->name('leads.import.preview');
    Route::post('/leads/import/process', [LeadController::class, 'processImport'])->name('leads.import.process');
    Route::post('/leads/{lead}/rescore', [LeadController::class, 'rescore'])->name('leads.rescore');
    Route::post('/leads/{lead}/verify-email', [LeadController::class, 'verifyEmail'])->name('leads.verify-email');
    Route::resource('leads', LeadController::class)->except(['destroy']);

    // Buying Intent Signals
    Route::get('/buying-signals', [BuyingSignalController::class, 'index'])->name('buying-signals.index');
    Route::get('/buying-signals/create', [BuyingSignalController::class, 'create'])->name('buying-signals.create');
    Route::post('/buying-signals', [BuyingSignalController::class, 'store'])->name('buying-signals.store');
    Route::post('/buying-signals/{signal}/status', [BuyingSignalController::class, 'updateStatus'])->name('buying-signals.status');

    // CRM Pipeline
    Route::get('/crm/pipeline', [CrmPipelineController::class, 'index'])->name('crm.pipeline');
    Route::post('/crm/pipeline/{lead}/stage', [CrmPipelineController::class, 'updateStage'])->name('crm.pipeline.stage');
    Route::post('/crm/tasks', [CrmPipelineController::class, 'storeTask'])->name('crm.tasks.store');
    Route::post('/crm/tasks/{task}/complete', [CrmPipelineController::class, 'completeTask'])->name('crm.tasks.complete');
    Route::post('/crm/meetings', [CrmPipelineController::class, 'storeMeeting'])->name('crm.meetings.store');
    Route::post('/crm/deals', [CrmPipelineController::class, 'storeDeal'])->name('crm.deals.store');

    // Campaigns & Outreach Automation
    Route::post('/campaigns/{campaign}/approve', [CampaignController::class, 'approve'])->name('campaigns.approve');
    Route::post('/campaigns/{campaign}/pause', [CampaignController::class, 'pause'])->name('campaigns.pause');
    Route::post('/campaigns/{campaign}/resume', [CampaignController::class, 'resume'])->name('campaigns.resume');
    Route::post('/campaigns/{campaign}/emergency-stop', [CampaignController::class, 'emergencyStop'])->name('campaigns.emergency-stop');
    Route::post('/campaigns/{campaign}/test-email', [CampaignController::class, 'sendTestEmail'])->name('campaigns.test-email');
    Route::post('/campaigns/recipients/{recipient}/dispatch', [CampaignController::class, 'dispatchRecipient'])->name('campaigns.recipient.dispatch');
    Route::resource('campaigns', CampaignController::class)->only(['index', 'create', 'store', 'show']);

    // Analytics & Reporting
    Route::get('/analytics', [AnalyticsController::class, 'index'])->name('analytics.index');
    Route::get('/analytics/export', [AnalyticsController::class, 'export'])->name('analytics.export');

    // Settings & Integrations (Protected by Admin Policy)
    Route::get('/settings/integrations', [IntegrationController::class, 'index'])->name('settings.integrations');
    Route::get('/settings/ai-models', [\App\Http\Controllers\AiProviderController::class, 'index'])->name('settings.ai-providers');
    Route::post('/settings/ai-models', [\App\Http\Controllers\AiProviderController::class, 'store'])->name('settings.ai-providers.store');
    Route::post('/settings/ai-models/recommended', [\App\Http\Controllers\AiProviderController::class, 'recommended'])->name('settings.ai-providers.recommended');
    Route::put('/settings/ai-models/{provider}', [\App\Http\Controllers\AiProviderController::class, 'update'])->name('settings.ai-providers.update');
    Route::post('/settings/ai-models/{provider}/toggle', [\App\Http\Controllers\AiProviderController::class, 'toggle'])->name('settings.ai-providers.toggle');
    Route::post('/settings/ai-models/{provider}/test', [\App\Http\Controllers\AiProviderController::class, 'test'])->name('settings.ai-providers.test')->middleware('throttle:20,1');
    Route::delete('/settings/ai-models/{provider}', [\App\Http\Controllers\AiProviderController::class, 'destroy'])->name('settings.ai-providers.destroy');
    Route::put('/settings/integrations/{integration}', [IntegrationController::class, 'update'])->name('settings.integrations.update');
    Route::post('/settings/integrations/{integration}/test', [IntegrationController::class, 'testConnection'])->name('settings.integrations.test');

    // Outreach Automation Settings (Phase 5)
    Route::get('/settings/outreach-automation', [\App\Http\Controllers\OutreachAutomationController::class, 'show'])->name('settings.outreach-automation');
    Route::put('/settings/outreach-automation', [\App\Http\Controllers\OutreachAutomationController::class, 'update'])->name('settings.outreach-automation.update');
    Route::post('/settings/outreach-automation/activate', [\App\Http\Controllers\OutreachAutomationController::class, 'activate'])->name('settings.outreach-automation.activate');
    Route::post('/settings/outreach-automation/emergency-stop', [\App\Http\Controllers\OutreachAutomationController::class, 'toggleEmergencyStop'])->name('settings.outreach-automation.emergency-stop');
});


// ---- Lead Hunting Engine v3 ----
Route::middleware(['auth'])->group(function () {
    Route::get('/hunting', [\App\Http\Controllers\IcpProfileController::class, 'index'])->name('hunting.index');
    Route::get('/hunting/create', [\App\Http\Controllers\IcpProfileController::class, 'create'])->name('hunting.create');
    Route::post('/hunting', [\App\Http\Controllers\IcpProfileController::class, 'store'])->name('hunting.store');
    Route::get('/hunting/sources.json', [\App\Http\Controllers\IcpProfileController::class, 'sources'])->name('hunting.sources');
    Route::get('/hunting/{icp}', [\App\Http\Controllers\IcpProfileController::class, 'show'])->name('hunting.show');
    Route::get('/hunting/{icp}/edit', [\App\Http\Controllers\IcpProfileController::class, 'edit'])->name('hunting.edit');
    Route::put('/hunting/{icp}', [\App\Http\Controllers\IcpProfileController::class, 'update'])->name('hunting.update');
    Route::delete('/hunting/{icp}', [\App\Http\Controllers\IcpProfileController::class, 'destroy'])->name('hunting.destroy');
    Route::post('/hunting/{icp}/hunt', [\App\Http\Controllers\IcpProfileController::class, 'hunt'])->name('hunting.hunt');

    Route::post('/companies/{company}/audit', [\App\Http\Controllers\WebsiteAuditController::class, 'store'])->name('companies.audit')->middleware('throttle:20,1');
    Route::post('/companies/{company}/report', [\App\Http\Controllers\WebsiteReportController::class, 'store'])->name('companies.report.build')->middleware('throttle:6,1');
    Route::get('/companies/{company}/report', [\App\Http\Controllers\WebsiteReportController::class, 'show'])->name('companies.report');
    Route::get('/companies/{company}/report.pdf', [\App\Http\Controllers\WebsiteReportController::class, 'pdf'])->name('companies.report.pdf');
    Route::get('/audits/{audit}', [\App\Http\Controllers\WebsiteAuditController::class, 'show'])->name('audits.show');

    Route::get('/leads/{lead}/assistant', [\App\Http\Controllers\SalesAssistantController::class, 'show'])->name('assistant.show');
    Route::post('/leads/{lead}/assistant/polish', [\App\Http\Controllers\SalesAssistantController::class, 'polish'])->name('assistant.polish')->middleware('throttle:10,1');
    Route::post('/leads/{lead}/assistant/classify', [\App\Http\Controllers\SalesAssistantController::class, 'classify'])->name('assistant.classify');

    Route::get('/settings/scoring', [\App\Http\Controllers\ScoringSettingsController::class, 'edit'])->name('settings.scoring');
    Route::put('/settings/scoring', [\App\Http\Controllers\ScoringSettingsController::class, 'update'])->name('settings.scoring.update');
});

// ---- v4 Autopilot ----
Route::middleware(['auth'])->group(function () {
    Route::get('/autopilot', [\App\Http\Controllers\AutopilotController::class, 'index'])->name('autopilot.index');
    Route::post('/autopilot/run', [\App\Http\Controllers\AutopilotController::class, 'runNow'])->name('autopilot.run')->middleware('throttle:6,1');
    Route::post('/autopilot/workflows/{workflow}', [\App\Http\Controllers\AutopilotController::class, 'toggleWorkflow'])->name('autopilot.workflow');
    Route::post('/autopilot/incidents/{incident}/resolve', [\App\Http\Controllers\AutopilotController::class, 'resolveIncident'])->name('autopilot.incident.resolve');
    Route::get('/autopilot/policy', [\App\Http\Controllers\AutopilotController::class, 'policy'])->name('autopilot.policy');
    Route::put('/autopilot/policy', [\App\Http\Controllers\AutopilotController::class, 'updatePolicy'])->name('autopilot.policy.update');
    Route::post('/autopilot/budgets', [\App\Http\Controllers\AutopilotController::class, 'saveBudget'])->name('autopilot.budget');
    Route::get('/autopilot/setup', [\App\Http\Controllers\AutopilotController::class, 'setup'])->name('autopilot.setup');
    Route::post('/autopilot/setup/defaults', [\App\Http\Controllers\AutopilotController::class, 'applyDefaults'])->name('autopilot.setup.defaults');
    Route::get('/autopilot/performance', [\App\Http\Controllers\AutopilotController::class, 'performance'])->name('autopilot.performance');
    Route::get('/autopilot/weekly', [\App\Http\Controllers\AutopilotController::class, 'weekly'])->name('autopilot.weekly');

    Route::get('/approvals', [\App\Http\Controllers\ApprovalController::class, 'index'])->name('approvals.index');
    Route::get('/approvals/{approval}', [\App\Http\Controllers\ApprovalController::class, 'show'])->name('approvals.show');
    Route::post('/approvals/{approval}/decide', [\App\Http\Controllers\ApprovalController::class, 'decide'])->name('approvals.decide')->middleware('throttle:60,1');

    Route::get('/outbox', [\App\Http\Controllers\OutboxController::class, 'index'])->name('outbox.index');
    Route::resource('templates', \App\Http\Controllers\EmailTemplateController::class)->except(['show', 'destroy'])->parameters(['templates' => 'template']);
    Route::resource('whatsapp-templates', \App\Http\Controllers\WhatsappTemplateController::class)->except(['show']);
    Route::post('/leads/{lead}/whatsapp', [\App\Http\Controllers\WhatsappTemplateController::class, 'open'])->name('leads.whatsapp')->middleware('throttle:30,1');
    Route::get('/outbox/{recipient}', [\App\Http\Controllers\OutboxController::class, 'show'])->name('outbox.show');

    Route::get('/notifications', [\App\Http\Controllers\NotificationController::class, 'index'])->name('notifications.index');
    Route::get('/notifications/{id}/read', [\App\Http\Controllers\NotificationController::class, 'read'])->name('notifications.read');
    Route::post('/notifications/read-all', [\App\Http\Controllers\NotificationController::class, 'readAll'])->name('notifications.read-all');
});
