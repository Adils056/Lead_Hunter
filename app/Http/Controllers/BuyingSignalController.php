<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\BuyingSignal;
use App\Models\Company;
use App\Services\LeadScoringService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class BuyingSignalController extends Controller
{
    protected LeadScoringService $scoringService;

    public function __construct(LeadScoringService $scoringService)
    {
        $this->scoringService = $scoringService;
    }

    public function index(Request $request)
    {
        $status = $request->query('status', 'all');
        $type = $request->query('type');
        $service = $request->query('service');

        $query = BuyingSignal::with('company');

        if ($status !== 'all') {
            $query->where('verification_status', $status);
        }

        if ($type) {
            $query->where('signal_type', $type);
        }

        if ($service) {
            $query->where('relevant_service', $service);
        }

        $signals = $query->latest('observed_date')->paginate(15)->withQueryString();

        $signalTypes = [
            'developer_hiring' => 'Developer Hiring',
            'project_requirements' => 'Software Project Requirements',
            'erp_crm_request' => 'ERP/CRM Request',
            'funding_announcement' => 'Funding Announcement',
            'business_expansion' => 'Business Expansion',
            'procurement_rfp' => 'Public Procurement / Tender',
            'vendor_request' => 'Vendor Search',
            'tech_stack_change' => 'Technology Stack Change',
            'website_issues' => 'Documented Website Issue',
            'business_launch' => 'New Business Launch',
        ];

        return view('buying_signals.index', compact('signals', 'signalTypes', 'status', 'type', 'service'));
    }

    public function create(Request $request)
    {
        $companies = Company::orderBy('name')->get();
        $preselectedCompanyId = $request->query('company_id');

        return view('buying_signals.create', compact('companies', 'preselectedCompanyId'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'company_id' => ['required', 'exists:companies,id'],
            'signal_type' => ['required', 'string'],
            'title' => ['required', 'string', 'max:255'],
            'source_url' => ['nullable', 'url', 'max:500'],
            'evidence_text' => ['required', 'string'],
            'observed_date' => ['required', 'date'],
            'confidence' => ['required', 'integer', 'between:1,100'],
            'relevant_service' => ['required', 'string'],
            'verification_status' => ['required', 'in:unverified,verified,dismissed'],
        ]);

        $signal = BuyingSignal::create($data);

        // Auto re-score all leads under this company
        $company = Company::find($data['company_id']);
        if ($company) {
            foreach ($company->leads as $lead) {
                $this->scoringService->evaluateLead($lead, Auth::id());
            }
        }

        AuditLog::record('buying_signal_created', $signal);

        return redirect()->route('buying-signals.index')
            ->with('success', "Buying signal '{$signal->title}' logged and company leads automatically rescored.");
    }

    public function updateStatus(Request $request, BuyingSignal $signal)
    {
        $data = $request->validate([
            'verification_status' => ['required', 'in:verified,unverified,dismissed'],
        ]);

        $signal->update(['verification_status' => $data['verification_status']]);

        // Re-score related company leads
        if ($signal->company) {
            foreach ($signal->company->leads as $lead) {
                $this->scoringService->evaluateLead($lead, Auth::id());
            }
        }

        AuditLog::record('buying_signal_status_updated', $signal, null, ['status' => $data['verification_status']]);

        return back()->with('success', "Signal marked as {$data['verification_status']}.");
    }
}
