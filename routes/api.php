<?php

use App\Http\Controllers\Api\V1\ApiController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| REST API Routes - LeadPilot AI v1
|--------------------------------------------------------------------------
*/

Route::prefix('v1')->group(function () {
    Route::get('/dashboard', [ApiController::class, 'dashboard']);
    Route::get('/companies', [ApiController::class, 'getCompanies']);
    Route::get('/companies/{company}', [ApiController::class, 'showCompany']);
    Route::get('/leads', [ApiController::class, 'getLeads']);
    Route::get('/leads/{lead}/timeline', [ApiController::class, 'showLeadTimeline']);
    Route::post('/leads/{lead}/rescore', [ApiController::class, 'rescoreLead']);
    Route::get('/buying-signals', [ApiController::class, 'getBuyingSignals']);
    Route::post('/ai/research', [ApiController::class, 'triggerAiResearch']);
    Route::get('/campaigns', [ApiController::class, 'getCampaigns']);
    Route::get('/integrations', [ApiController::class, 'getIntegrations']);
});
