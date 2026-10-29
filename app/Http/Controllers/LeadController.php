<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\Suppression;
use App\Models\User;
use App\Services\CsvImportService;
use App\Services\EmailVerificationService;
use App\Services\LeadScoringService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Response;

class LeadController extends Controller
{
    protected LeadScoringService $scoringService;
    protected EmailVerificationService $verificationService;
    protected CsvImportService $csvImportService;

    public function __construct(
        LeadScoringService $scoringService,
        EmailVerificationService $verificationService,
        CsvImportService $csvImportService
    ) {
        $this->scoringService = $scoringService;
        $this->verificationService = $verificationService;
        $this->csvImportService = $csvImportService;
    }

    public function index(Request $request)
    {
        $this->authorize('viewAny', Lead::class);

        $search = \App\Support\Like::term($request->query('search'));
        $stage = $request->query('stage');
        $country = $request->query('country');
        $verification = $request->query('verification');
        $minScore = $request->query('min_score');
        $assignedTo = $request->query('assigned_to');

        $query = Lead::with(['company', 'assignedUser', 'leadSource']);

        if ($search) {
            $query->where(function ($q) use ($search) {
                \App\Support\Like::where($q, 'full_name', $search);
                \App\Support\Like::where($q, 'email', $search, 'or');
                \App\Support\Like::where($q, 'job_title', $search, 'or');
                $q->orWhereHas('company', fn ($cq) => \App\Support\Like::where($cq, 'name', $search));
            });
        }

        if ($stage && $stage !== 'all') {
            $query->where('lifecycle_stage', $stage);
        }

        if ($country) {
            $query->where('country', $country);
        }

        if ($verification && $verification !== 'all') {
            $query->where('email_verification_status', $verification);
        }

        if ($minScore) {
            $query->where('overall_score', '>=', (int) $minScore);
        }

        if ($assignedTo) {
            $query->where('assigned_user_id', $assignedTo);
        }

        $leads = $query->orderByDesc('overall_score')->paginate(15)->withQueryString();

        $countries = Lead::whereNotNull('country')->distinct()->pluck('country');
        $salesReps = User::whereIn('role', ['sales', 'admin'])->get();

        return view('leads.index', compact(
            'leads',
            'countries',
            'salesReps',
            'search',
            'stage',
            'country',
            'verification',
            'minScore',
            'assignedTo'
        ));
    }

    public function create()
    {
        $this->authorize('create', Lead::class);

        $selectedCompany = Company::find(old('company_id', request('company_id')));
        $salesReps = User::whereIn('role', ['sales', 'admin'])->get();
        $sources = LeadSource::where('is_active', true)->get();

        return view('leads.create', compact('selectedCompany', 'salesReps', 'sources'));
    }

    public function store(Request $request)
    {
        $this->authorize('create', Lead::class);

        $data = $request->validate([
            'first_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'full_name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'job_title' => ['nullable', 'string', 'max:150'],
            'company_id' => ['nullable', 'exists:companies,id'],
            'lead_source_id' => ['nullable', 'exists:lead_sources,id'],
            'country' => ['nullable', 'string', 'max:100'],
            'industry' => ['nullable', 'string', 'max:100'],
            'relevant_services' => ['nullable', 'array'],
            'profile_url' => ['nullable', 'url', 'max:500'],
            'assigned_user_id' => ['nullable', 'exists:users,id'],
            'notes' => ['nullable', 'string'],
        ]);

        // Duplicate Check by email
        if (!empty($data['email']) && Lead::where('email', strtolower($data['email']))->exists()) {
            return back()->withInput()->with('error', "A lead with email '{$data['email']}' already exists in the system.");
        }

        // Suppression Check
        if (!empty($data['email']) && Suppression::isSuppressed($data['email'])) {
            return back()->withInput()->with('warning', "Warning: '{$data['email']}' is currently on the global suppression / do-not-contact list.");
        }

        $lead = Lead::create([
            'first_name' => $data['first_name'] ?? null,
            'last_name' => $data['last_name'] ?? null,
            'full_name' => $data['full_name'],
            'email' => !empty($data['email']) ? strtolower($data['email']) : null,
            'phone' => $data['phone'] ?? null,
            'job_title' => $data['job_title'] ?? null,
            'company_id' => $data['company_id'] ?? null,
            'lead_source_id' => $data['lead_source_id'] ?? null,
            'country' => $data['country'] ?? 'Global',
            'industry' => $data['industry'] ?? null,
            'relevant_services' => $data['relevant_services'] ?? ['Custom software development'],
            'profile_url' => $data['profile_url'] ?? null,
            'assigned_user_id' => $data['assigned_user_id'] ?? Auth::id(),
            'notes' => $data['notes'] ?? null,
            'discovery_date' => now(),
            'lifecycle_stage' => 'new',
        ]);

        // Run email verification if email provided
        if (!empty($lead->email)) {
            $this->verificationService->verifyEmail($lead->email, $lead, Auth::id());
        }

        // Compute 100-point score
        $this->scoringService->evaluateLead($lead, Auth::id());

        AuditLog::record('lead_created', $lead);

        return redirect()->route('leads.show', $lead)
            ->with('success', "Lead '{$lead->full_name}' created and scored ({$lead->overall_score} pts).");
    }

    public function show(Lead $lead)
    {
        $this->authorize('view', $lead);

        $lead->load([
            'company.buyingSignals',
            'assignedUser',
            'leadSource',
            'scores' => fn($q) => $q->latest(),
            'tasks' => fn($q) => $q->latest(),
            'meetings' => fn($q) => $q->latest(),
            'deals',
            'campaignRecipients.campaign',
            'inboundReplies' => fn($q) => $q->latest(),
            'emailVerifications' => fn($q) => $q->latest(),
        ]);

        return view('leads.show', compact('lead'));
    }

    public function edit(Lead $lead)
    {
        $this->authorize('update', $lead);

        $selectedCompany = Company::find(old('company_id', $lead->company_id));
        $salesReps = User::whereIn('role', ['sales', 'admin'])->get();
        $sources = LeadSource::where('is_active', true)->get();

        return view('leads.edit', compact('lead', 'selectedCompany', 'salesReps', 'sources'));
    }

    public function update(Request $request, Lead $lead)
    {
        $this->authorize('update', $lead);

        $data = $request->validate([
            'first_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'full_name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'job_title' => ['nullable', 'string', 'max:150'],
            'company_id' => ['nullable', 'exists:companies,id'],
            'lifecycle_stage' => ['required', 'string'],
            'assigned_user_id' => ['nullable', 'exists:users,id'],
            'notes' => ['nullable', 'string'],
            'next_action_date' => ['nullable', 'date'],
            'next_action_note' => ['nullable', 'string', 'max:255'],
        ]);

        $old = $lead->only(['lifecycle_stage', 'assigned_user_id', 'email']);

        $lead->update([
            'first_name' => $data['first_name'] ?? null,
            'last_name' => $data['last_name'] ?? null,
            'full_name' => $data['full_name'],
            'email' => !empty($data['email']) ? strtolower($data['email']) : null,
            'phone' => $data['phone'] ?? null,
            'job_title' => $data['job_title'] ?? null,
            'company_id' => $data['company_id'] ?? null,
            'lifecycle_stage' => $data['lifecycle_stage'],
            'assigned_user_id' => $data['assigned_user_id'] ?? null,
            'notes' => $data['notes'] ?? null,
            'next_action_date' => $data['next_action_date'] ?? null,
            'next_action_note' => $data['next_action_note'] ?? null,
        ]);

        AuditLog::record('lead_updated', $lead, $old, $lead->only(['lifecycle_stage', 'assigned_user_id', 'email']));

        return redirect()->route('leads.show', $lead)
            ->with('success', 'Lead profile updated successfully.');
    }

    public function rescore(Lead $lead)
    {
        $this->authorize('rescore', $lead);

        $score = $this->scoringService->evaluateLead($lead, Auth::id());
        app(\App\Services\Outreach\WinLadderService::class)->refresh($lead->fresh());
        AuditLog::record('lead_rescored', $lead, null, ['score' => $score->overall_score]);

        return back()->with('success', "Lead re-evaluated! New Overall Score: {$score->overall_score}/100 ({$score->priority_level} priority).");
    }

    public function verifyEmail(Lead $lead)
    {
        $this->authorize('update', $lead);

        if (empty($lead->email)) {
            return back()->with('error', 'Cannot verify: lead does not have an email address.');
        }

        $result = $this->verificationService->verifyEmail($lead->email, $lead, Auth::id(), fresh: true);
        $this->scoringService->evaluateLead($lead, Auth::id());

        return back()->with('success', "Email verification complete: status is '" . \App\Support\EmailStatus::label($result['status']) . "' (provider: {$result['provider']}). Lead score updated.");
    }

    public function showImport()
    {
        $this->authorize('import', Lead::class);
        return view('leads.import');
    }

    public function previewImport(Request $request)
    {
        $this->authorize('import', Lead::class);

        $request->validate([
            'csv_file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
        ]);

        $file = $request->file('csv_file');
        $path = $file->getRealPath();

        $rows = [];
        if (($handle = fopen($path, 'r')) !== false) {
            $headers = fgetcsv($handle, 2000, ",");
            $count = 0;
            while (($data = fgetcsv($handle, 2000, ",")) !== false && $count < 10) {
                if (count($headers) === count($data)) {
                    $rows[] = array_combine($headers, $data);
                }
                $count++;
            }
            fclose($handle);
        }

        if (empty($headers)) {
            return back()->with('error', 'Failed to read CSV header row. Ensure file has comma-separated columns.');
        }

        // Store file in temp storage for execution
        $storedPath = $file->store('temp_imports');
        session(['import_csv_path' => $storedPath, 'import_csv_headers' => $headers]);

        return view('leads.import_mapping', compact('headers', 'rows'));
    }

    public function processImport(Request $request)
    {
        $this->authorize('import', Lead::class);

        $csvPath = session('import_csv_path');
        if (!$csvPath || !file_exists(storage_path('app/' . $csvPath))) {
            return redirect()->route('leads.import')->with('error', 'Import session expired. Please re-upload CSV.');
        }

        $columnMapping = $request->input('mapping', []);

        $fullPath = storage_path('app/' . $csvPath);
        $rows = [];
        if (($handle = fopen($fullPath, 'r')) !== false) {
            $headers = fgetcsv($handle, 2000, ",");
            while (($data = fgetcsv($handle, 2000, ",")) !== false) {
                if (count($headers) === count($data)) {
                    $rows[] = array_combine($headers, $data);
                }
            }
            fclose($handle);
        }

        @unlink($fullPath);
        session()->forget(['import_csv_path', 'import_csv_headers']);

        $result = $this->csvImportService->import($rows, $columnMapping, Auth::id());

        $msg = "Import completed: {$result['imported']} leads added, {$result['duplicates']} duplicates skipped.";
        if (!empty($result['errors'])) {
            $msg .= " Some rows had notices: " . implode(' ', array_slice($result['errors'], 0, 3));
        }

        return redirect()->route('leads.index')->with('success', $msg);
    }

    public function export(Request $request)
    {
        $this->authorize('export', Lead::class);

        $count = Lead::count();
        $filename = "leadpilot_leads_" . date('Y-m-d_His') . ".csv";

        $headers = [
            "Content-type" => "text/csv; charset=UTF-8",
            "Content-Disposition" => "attachment; filename={$filename}",
            "Pragma" => "no-cache",
            "Cache-Control" => "must-revalidate, post-check=0, pre-check=0",
            "Expires" => "0"
        ];

        $columns = [
            'ID',
            'Full Name',
            'Job Title',
            'Email',
            'Email Verification',
            'Phone',
            'Company',
            'Website',
            'Country',
            'City',
            'Industry',
            'Lifecycle Stage',
            'Overall Score',
            'Priority',
            'Score Reasons',
            'Source',
            'Source URL',
            'Discovery Date'
        ];

        $callback = function () use ($columns) {
            $file = fopen('php://output', 'w');
            fwrite($file, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel shows Arabic / Urdu names correctly
            fputcsv($file, $columns);

            Lead::with('company')->orderBy('id')->chunk(500, function ($leads) use ($file) {
                foreach ($leads as $l) {
                    fputcsv($file, [
                        $l->id,
                        $l->full_name,
                        $l->job_title,
                        $l->email,
                        $l->email_verification_status,
                        $l->phone ?: $l->company?->public_phone,
                        $l->company?->name ?? 'N/A',
                        $l->company?->website,
                        $l->country,
                        $l->company?->city,
                        $l->industry,
                        $l->lifecycle_stage,
                        $l->overall_score,
                        $l->priority_badge,
                        \Illuminate\Support\Str::limit(preg_replace('/\s+/', ' ', (string) $l->score_explanation), 500),
                        $l->source_name,
                        $l->source_url,
                        $l->discovery_date?->format('Y-m-d') ?? '',
                    ]);
                }
            });
            fclose($file);
        };

        AuditLog::record('leads_exported', null, null, ['count' => $count]);

        return Response::stream($callback, 200, $headers);
    }

    // ------------------------------------------------------------------ Win Ladder (who to email first)

    public function winLadder(Request $request, \App\Services\Outreach\WinLadderService $ladder)
    {
        $this->authorize('viewAny', Lead::class);
        $tier = strtoupper(\App\Support\Like::term($request->query('tier', 'A')));
        if (!isset(\App\Services\Outreach\WinLadderService::TIERS[$tier])) {
            $tier = 'A';
        }
        $unranked = Lead::whereNull('win_tier')->count();
        if ($unranked > 0 && $unranked <= 300) { // first visit / new leads: rank on the fly, larger sets use the button
            $ladder->refreshAll(true);
            $unranked = 0;
        }
        $country = \App\Support\Like::term($request->query('country')) ?: null;
        $leads = Lead::with(['company', 'icpProfile'])->where('win_tier', $tier)->when($country, fn ($q) => $q->where('country', $country))
            ->orderByDesc('win_points')->orderByDesc('overall_score')->orderBy('id')->paginate(25)->withQueryString();
        $countries = Lead::whereNotNull('win_tier')->whereNotNull('country')->distinct()->orderBy('country')->pluck('country');

        return view('leads.win_ladder', ['tier' => $tier, 'leads' => $leads, 'counts' => $ladder->counts($country), 'unranked' => $unranked,
            'expected' => $ladder->expected($tier), 'country' => $country, 'countries' => $countries]);
    }

    public function winLadderRefresh(\App\Services\Outreach\WinLadderService $ladder)
    {
        $this->authorize('viewAny', Lead::class);
        $counts = $ladder->refreshAll();

        return back()->with('success', 'Ladder recomputed for ' . array_sum($counts) . " lead(s): {$counts['A']} ready to email now, {$counts['B']} one fix away, {$counts['R']} replied.");
    }

    public function winLadderExport(Request $request, \App\Services\Outreach\WinLadderService $ladder)
    {
        $this->authorize('export', Lead::class);
        $tier = strtoupper(\App\Support\Like::term($request->query('tier', 'A')));
        $tier = isset(\App\Services\Outreach\WinLadderService::TIERS[$tier]) ? $tier : 'A';
        $leads = $ladder->ranked($tier, 5000, \App\Support\Like::term($request->query('country')) ?: null);
        $filename = 'leadpilot_win_ladder_' . $tier . '_' . date('Y-m-d_His') . '.csv';
        $callback = function () use ($leads, $tier) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel
            fputcsv($out, ['Rung', 'Rank', 'Company', 'Website', 'City', 'Country', 'Contact', 'Job title', 'Email', 'Email status', 'Score', 'Points', 'Reply %', 'Interested %', 'Deal %', 'Success-rate basis', 'Why', 'Blockers', 'Next action']);
            foreach ($leads as $i => $l) {
                fputcsv($out, [$tier, $i + 1, $l->company?->name, $l->company?->website, $l->company?->city, $l->country, $l->full_name, $l->job_title, $l->email,
                    $l->email_verification_status, $l->overall_score, $l->win_points, $l->win_reply_chance, $l->win_interested_chance, $l->win_deal_chance, $l->win_chance_basis, implode(' | ', (array) $l->win_reasons), implode(' | ', (array) $l->win_blockers), $l->win_next_action]);
            }
            fclose($out);
        };

        return response()->stream($callback, 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => "attachment; filename={$filename}"]);
    }
}
