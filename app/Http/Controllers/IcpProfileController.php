<?php

namespace App\Http\Controllers;

use App\Models\DiscoveryRun;
use App\Models\IcpProfile;
use App\Services\Discovery\DiscoveryManager;
use App\Services\Hunting\IcpSearchConfigBuilder;
use App\Services\Hunting\LeadHuntService;
use Illuminate\Http\Request;

class IcpProfileController extends Controller
{
    protected function admin(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403, 'Only administrators can manage ICPs and run paid hunts.');
    }

    public function index(\App\Services\Discovery\SourceStatusService $status)
    {
        $profiles = IcpProfile::withCount(['leads' => fn ($q) => $this->visibleLeads($q)])->latest()->get();
        $runs = DiscoveryRun::with('icpProfile')->latest()->limit(15)->get();
        $sources = $status->all();

        return view('hunting.index', compact('profiles', 'runs', 'sources'));
    }

    /** Live status of every discovery source (polled by the Lead Hunter page every 30 s). */
    public function sources(\App\Services\Discovery\SourceStatusService $status)
    {
        return response()->json(['as_of' => now()->toIso8601String(), 'sources' => array_values($status->all())]);
    }

    public function create(DiscoveryManager $discovery)
    {
        $this->admin();

        return view('hunting.form', ['icp' => new IcpProfile(['min_lead_score' => 50, 'max_results_per_run' => 25, 'website_requirement' => 'any', 'exclude_existing_customers' => true, 'is_active' => true]), 'adapters' => $discovery->getAdapters()]);
    }

    public function store(Request $request)
    {
        $this->admin();
        $icp = IcpProfile::create($this->validated($request) + ['created_by' => auth()->id()]);

        return redirect()->route('hunting.show', $icp)->with('success', 'Ideal Customer Profile saved. Review the generated search config, then run a hunt.');
    }

    public function show(IcpProfile $icp, IcpSearchConfigBuilder $builder, DiscoveryManager $discovery)
    {
        $config = $builder->build($icp);
        $runs = $icp->runs()->latest()->limit(20)->get();
        $leads = $this->visibleLeads($icp->leads())->with('company.latestWebsiteAudit')->orderByDesc('overall_score')->limit(50)->get();
        $estCost = 0;
        foreach ($config as $slug => $opts) {
            if ($slug === 'google_places') {
                $estCost += count($opts['queries'] ?? []) * (int) ceil(($opts['limit'] ?? 20) / 20) * (int) config('leadpilot.sources.google_places.est_cost_cents_per_request');
            }
        }
        $adapters = $discovery->getAdapters();

        return view('hunting.show', compact('icp', 'config', 'runs', 'leads', 'estCost', 'adapters'));
    }

    public function edit(IcpProfile $icp, DiscoveryManager $discovery)
    {
        $this->admin();

        return view('hunting.form', ['icp' => $icp, 'adapters' => $discovery->getAdapters()]);
    }

    public function update(Request $request, IcpProfile $icp)
    {
        $this->admin();
        $icp->update($this->validated($request));

        return redirect()->route('hunting.show', $icp)->with('success', 'ICP updated.');
    }

    public function destroy(IcpProfile $icp)
    {
        $this->admin();
        $icp->delete();

        return redirect()->route('hunting.index')->with('success', 'ICP archived.');
    }

    public function hunt(Request $request, IcpProfile $icp)
    {
        $this->admin();
        \App\Jobs\RunHuntJob::dispatch($icp, auth()->id(), $request->boolean('audit', true));

        return redirect()->route('hunting.show', $icp)->with('success', 'Hunt queued. Results appear in Run history as each source finishes (requires the queue worker: php artisan queue:work).');
    }

    /** Mirrors LeadPolicy::view — sales reps see unassigned leads and their own. */
    protected function visibleLeads($query)
    {
        $user = auth()->user();

        return $user->isAdmin() ? $query : $query->where(fn ($q) => $q->whereNull('assigned_user_id')->orWhere('assigned_user_id', $user->id));
    }

    protected function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
            'website_requirement' => ['required', 'in:' . implode(',', array_keys(IcpProfile::WEBSITE_REQUIREMENTS))],
            'min_purchase_ability' => ['nullable', 'in:low,medium,high'],
            'min_lead_score' => ['required', 'integer', 'min:0', 'max:100'],
            'min_google_rating' => ['nullable', 'numeric', 'min:0', 'max:5'],
            'min_review_count' => ['nullable', 'integer', 'min:0'],
            'max_results_per_run' => ['required', 'integer', 'min:1', 'max:200'],
            'monthly_budget' => ['nullable', 'numeric', 'min:0'],
            'objective' => ['nullable', 'string', 'max:255'],
            'business_types' => ['nullable', 'array'],
            'target_services' => ['nullable', 'array'],
            'sources' => ['nullable', 'array'],
            'channels' => ['nullable', 'array'],
        ]);

        foreach (['countries', 'regions', 'cities', 'industries', 'niches', 'company_sizes', 'tech_include', 'tech_exclude', 'pain_points', 'keywords', 'contact_roles', 'exclude_domains', 'exclude_keywords'] as $f) {
            $data[$f] = array_values(array_filter(array_map('trim', preg_split('/[\n,]+/', (string) $request->input($f, '')))));
        }
        $data['min_google_rating'] = $request->filled('min_google_rating') ? (int) round($request->input('min_google_rating') * 10) : null;
        $data['monthly_budget_cents'] = $request->filled('monthly_budget') ? (int) round($request->input('monthly_budget') * 100) : null;
        unset($data['monthly_budget']);
        $data['is_active'] = $request->boolean('is_active');
        $data['exclude_existing_customers'] = $request->boolean('exclude_existing_customers');

        return $data;
    }
}
