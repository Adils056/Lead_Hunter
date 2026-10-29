<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\BuyingSignal;
use App\Models\Campaign;
use App\Models\Company;
use App\Models\Integration;
use App\Models\Lead;
use App\Services\AiResearchService;
use App\Services\LeadScoringService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ApiController extends Controller
{
    protected LeadScoringService $scoringService;
    protected AiResearchService $aiService;

    public function __construct(LeadScoringService $scoringService, AiResearchService $aiService)
    {
        $this->scoringService = $scoringService;
        $this->aiService = $aiService;
    }

    public function dashboard(): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => [
                'total_companies' => Company::count(),
                'total_leads' => Lead::count(),
                'qualified_leads' => Lead::where('overall_score', '>=', \App\Support\ScoreBands::qualified())->count(),
                'running_campaigns' => Campaign::where('status', 'running')->count(),
                'buying_signals_active' => BuyingSignal::where('verification_status', 'verified')->count(),
            ]
        ]);
    }

    public function getCompanies(Request $request): JsonResponse
    {
        $companies = Company::paginate($request->query('per_page', 15));
        return response()->json(['status' => 'success', 'data' => $companies]);
    }

    public function showCompany(Company $company): JsonResponse
    {
        $company->load(['leads', 'buyingSignals']);
        return response()->json(['status' => 'success', 'data' => $company]);
    }

    public function getLeads(Request $request): JsonResponse
    {
        $leads = Lead::with('company')->paginate($request->query('per_page', 15));
        return response()->json(['status' => 'success', 'data' => $leads]);
    }

    public function showLeadTimeline(Lead $lead): JsonResponse
    {
        $timeline = [
            'lead' => $lead,
            'scores' => $lead->scores()->latest()->get(),
            'tasks' => $lead->tasks()->latest()->get(),
            'meetings' => $lead->meetings()->latest()->get(),
            'outreach' => $lead->campaignRecipients()->with('campaign')->latest()->get(),
            'replies' => $lead->inboundReplies()->latest()->get(),
        ];
        return response()->json(['status' => 'success', 'data' => $timeline]);
    }

    public function rescoreLead(Lead $lead): JsonResponse
    {
        $score = $this->scoringService->evaluateLead($lead);
        return response()->json([
            'status' => 'success',
            'data' => [
                'lead_id' => $lead->id,
                'overall_score' => $score->overall_score,
                'priority' => $score->priority_level,
                'breakdown' => $score->breakdown,
            ]
        ]);
    }

    public function getBuyingSignals(): JsonResponse
    {
        $signals = BuyingSignal::with('company')->latest('observed_date')->paginate(20);
        return response()->json(['status' => 'success', 'data' => $signals]);
    }

    public function triggerAiResearch(Request $request): JsonResponse
    {
        $request->validate(['company_id' => 'required|exists:companies,id']);
        $company = Company::findOrFail($request->input('company_id'));

        $research = $this->aiService->researchCompany($company);
        return response()->json(['status' => 'success', 'data' => $research]);
    }

    public function getCampaigns(): JsonResponse
    {
        $campaigns = Campaign::with('template')->latest()->paginate(15);
        return response()->json(['status' => 'success', 'data' => $campaigns]);
    }

    public function getIntegrations(): JsonResponse
    {
        $integrations = Integration::select(['id', 'provider', 'name', 'status', 'is_active', 'last_tested_at'])->get();
        return response()->json(['status' => 'success', 'data' => $integrations]);
    }
}
