<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use App\Services\AiResearchService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Response;

class CompanyController extends Controller
{
    protected AiResearchService $aiService;

    public function __construct(AiResearchService $aiService)
    {
        $this->aiService = $aiService;
    }

    public function index(Request $request)
    {
        $this->authorize('viewAny', Company::class);

        $search = \App\Support\Like::term($request->query('search'));
        $country = $request->query('country');
        $industry = $request->query('industry');
        $size = $request->query('size');
        $status = $request->query('status', 'active');

        $query = Company::with(['assignedUser'])
            ->withCount(['leads', 'buyingSignals']);

        if ($status === 'active') {
            $query->where('status', '!=', 'archived');
        } elseif ($status !== 'all') {
            $query->where('status', $status);
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                \App\Support\Like::where($q, 'name', $search);
                \App\Support\Like::where($q, 'local_name', $search, 'or'); // the Urdu / Arabic name still finds it
                \App\Support\Like::where($q, 'domain', $search, 'or');
                \App\Support\Like::where($q, 'city', $search, 'or');
            });
        }

        if ($country) {
            $query->where('country', $country);
        }

        if ($industry) {
            $query->where('industry_group', $industry);
        }

        if ($size) {
            $query->where('size_range', $size);
        }

        $companies = $query->latest()->paginate(15)->withQueryString();

        $countries = Company::whereNotNull('country')->distinct()->pluck('country');
        $industries = Company::whereNotNull('industry_group')->distinct()->orderBy('industry_group')->pluck('industry_group');

        return view('companies.index', compact('companies', 'countries', 'industries', 'search', 'country', 'industry', 'size', 'status'));
    }

    public function create()
    {
        $this->authorize('create', Company::class);
        $salesReps = User::where('role', 'sales')->orWhere('role', 'admin')->get();
        return view('companies.create', compact('salesReps'));
    }

    public function store(Request $request)
    {
        $this->authorize('create', Company::class);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'website' => ['nullable', 'url', 'max:255'],
            'industry' => ['nullable', 'string', 'max:100'],
            'country' => ['required', 'string', 'max:100'],
            'city' => ['nullable', 'string', 'max:100'],
            'size_range' => ['nullable', 'string', 'max:50'],
            'description' => ['nullable', 'string', 'max:2000'],
            'public_email' => ['nullable', 'email', 'max:255'],
            'public_phone' => ['nullable', 'string', 'max:50'],
            'assigned_user_id' => ['nullable', 'exists:users,id'],
            'tech_stack' => ['nullable', 'string'],
        ]);

        $domain = Company::extractDomain($data['website'] ?? null);

        // Deduplication check by normalized domain
        if ($domain && Company::where('domain', $domain)->exists()) {
            return back()->withInput()->with('error', "A company with normalized domain '{$domain}' already exists in the database.");
        }

        $techStackArray = !empty($data['tech_stack']) ? array_map('trim', explode(',', $data['tech_stack'])) : [];

        $company = Company::create([
            'name' => $data['name'],
            'website' => $data['website'] ?? null,
            'domain' => $domain,
            'industry' => $data['industry'] ?? null,
            'country' => $data['country'],
            'city' => $data['city'] ?? null,
            'size_range' => $data['size_range'] ?? null,
            'description' => $data['description'] ?? null,
            'public_email' => $data['public_email'] ?? null,
            'public_phone' => $data['public_phone'] ?? null,
            'tech_stack' => $techStackArray,
            'assigned_user_id' => $data['assigned_user_id'] ?? Auth::id(),
            'source_provider' => 'manual',
            'discovery_date' => now(),
            'status' => 'prospect',
        ]);

        AuditLog::record('company_created', $company);

        return redirect()->route('companies.show', $company)
            ->with('success', "Company '{$company->name}' created successfully.");
    }

    public function show(Company $company)
    {
        $this->authorize('view', $company);

        $company->load([
            'assignedUser',
            'leads.scores',
            'buyingSignals' => fn($q) => $q->latest(),
            'deals',
            'tasks' => fn($q) => $q->latest(),
        ]);

        return view('companies.show', compact('company'));
    }

    public function edit(Company $company)
    {
        $this->authorize('update', $company);
        $salesReps = User::where('role', 'sales')->orWhere('role', 'admin')->get();
        return view('companies.edit', compact('company', 'salesReps'));
    }

    public function update(Request $request, Company $company)
    {
        $this->authorize('update', $company);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'website' => ['nullable', 'url', 'max:255'],
            'industry' => ['nullable', 'string', 'max:100'],
            'country' => ['required', 'string', 'max:100'],
            'city' => ['nullable', 'string', 'max:100'],
            'size_range' => ['nullable', 'string', 'max:50'],
            'description' => ['nullable', 'string', 'max:2000'],
            'public_email' => ['nullable', 'email', 'max:255'],
            'public_phone' => ['nullable', 'string', 'max:50'],
            'status' => ['required', 'string', 'in:prospect,active,customer,archived'],
            'assigned_user_id' => ['nullable', 'exists:users,id'],
            'tech_stack' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
        ]);

        $domain = Company::extractDomain($data['website'] ?? null);

        // Check duplicate if domain changed
        if ($domain && $domain !== $company->domain && Company::where('domain', $domain)->where('id', '!=', $company->id)->exists()) {
            return back()->withInput()->with('error', "Another company with normalized domain '{$domain}' already exists.");
        }

        $techStackArray = !empty($data['tech_stack']) ? array_map('trim', explode(',', $data['tech_stack'])) : $company->tech_stack;

        $oldValues = $company->only(['name', 'status', 'assigned_user_id']);

        $company->update([
            'name' => $data['name'],
            'website' => $data['website'] ?? null,
            'domain' => $domain,
            'industry' => $data['industry'] ?? null,
            'country' => $data['country'],
            'city' => $data['city'] ?? null,
            'size_range' => $data['size_range'] ?? null,
            'description' => $data['description'] ?? null,
            'public_email' => $data['public_email'] ?? null,
            'public_phone' => $data['public_phone'] ?? null,
            'status' => $data['status'],
            'assigned_user_id' => $data['assigned_user_id'] ?? null,
            'tech_stack' => $techStackArray,
            'notes' => $data['notes'] ?? $company->notes,
        ]);

        AuditLog::record('company_updated', $company, $oldValues, $company->only(['name', 'status', 'assigned_user_id']));

        return redirect()->route('companies.show', $company)
            ->with('success', 'Company details updated successfully.');
    }

    public function triggerAiResearch(Company $company)
    {
        $this->authorize('update', $company);

        $research = $this->aiService->researchCompany($company, Auth::id());
        AuditLog::record('company_ai_researched', $company);

        $message = isset($research['is_rule_based_fallback']) 
            ? 'AI API key not configured: Generated comprehensive rule-based intelligence analysis.'
            : 'AI research synthesis completed successfully using OpenAI model.';

        return back()->with('success', $message);
    }

    /** JSON for the company search field: at most 20 matches by name or domain. */
    public function lookup(Request $request)
    {
        $this->authorize('viewAny', Company::class);
        $q = \App\Support\Like::term($request->query('q'));
        if (mb_strlen($q) < 2) {
            return response()->json([]);
        }

        return response()->json(Company::where('status', '!=', 'archived')
            ->where(function ($w) use ($q) {
                \App\Support\Like::where($w, 'name', $q);
                \App\Support\Like::where($w, 'domain', $q, 'or');
            })
            ->orderBy('name')->limit(20)->get(['id', 'name', 'domain', 'city', 'country'])
            ->map(fn (Company $c) => ['id' => $c->id, 'label' => self::lookupLabel($c)])->values());
    }

    /** "Name — City, Country · domain" so branches with the same name can be told apart. */
    public static function lookupLabel(Company $c): string
    {
        $where = implode(', ', array_filter([$c->city, $c->country]));

        return $c->name . ($where ? " — {$where}" : '') . ($c->domain ? " · {$c->domain}" : '');
    }

    public function export(Request $request)
    {
        $this->authorize('export', Company::class);

        $companies = Company::where('status', '!=', 'archived')->get();
        $filename = "leadpilot_companies_" . date('Y-m-d_His') . ".csv";

        $headers = [
            "Content-type" => "text/csv; charset=UTF-8",
            "Content-Disposition" => "attachment; filename={$filename}",
            "Pragma" => "no-cache",
            "Cache-Control" => "must-revalidate, post-check=0, pre-check=0",
            "Expires" => "0"
        ];

        $columns = ['ID', 'Name', 'Domain', 'Industry', 'Country', 'City', 'Size Range', 'Public Email', 'Status', 'Discovery Date'];

        $callback = function () use ($companies, $columns) {
            $file = fopen('php://output', 'w');
            fwrite($file, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel shows Arabic / Urdu names correctly
            fputcsv($file, $columns);

            foreach ($companies as $comp) {
                fputcsv($file, [
                    $comp->id,
                    $comp->name,
                    $comp->domain,
                    $comp->industry,
                    $comp->country,
                    $comp->city,
                    $comp->size_range,
                    $comp->public_email,
                    $comp->status,
                    $comp->discovery_date?->format('Y-m-d') ?? '',
                ]);
            }
            fclose($file);
        };

        AuditLog::record('companies_exported', null, null, ['count' => $companies->count()]);

        return Response::stream($callback, 200, $headers);
    }
}
