@extends('layouts.app')
@section('title', $icp->name . ' — Lead Hunter')
@section('content')
<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
    <div>
        <nav><ol class="breadcrumb mb-1 small"><li class="breadcrumb-item"><a href="{{ route('hunting.index') }}">Lead Hunter</a></li><li class="breadcrumb-item active">{{ $icp->name }}</li></ol></nav>
        <h3 class="fw-bold mb-0">{{ $icp->name }}</h3>
        <div class="small text-muted">{{ $icp->objective }}</div>
    </div>
    @if(auth()->user()->isAdmin())
    <div class="d-flex gap-2">
        <a href="{{ route('hunting.edit', $icp) }}" class="btn btn-sm btn-light border"><i class="bi bi-pencil"></i> Edit</a>
        <form method="POST" action="{{ route('hunting.hunt', $icp) }}" onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').innerText='Queuing…';">
            @csrf
            <input type="hidden" name="audit" value="1">
            <button class="btn btn-sm btn-primary-lp"><i class="bi bi-crosshair"></i> Run hunt now @if($estCost) (≈${{ number_format($estCost / 100, 2) }}) @endif</button>
        </form>
    </div>
    @endif
</div>

<div class="row g-4">
    <div class="col-lg-5">
        <div class="card-lp p-3 mb-4">
            <h6 class="fw-bold mb-2">Generated search configuration</h6>
            <p class="small text-muted">Exactly what will be sent to each source. Preview before paying for API calls.</p>
            @foreach($config as $slug => $opts)
                @php $a = $adapters[$slug] ?? null; @endphp
                <div class="border rounded p-2 mb-2 small">
                    <div class="d-flex justify-content-between align-items-start gap-2"><strong>{{ $a?->getSourceName() ?? $slug }}</strong>
                        <span class="badge flex-shrink-0 text-nowrap {{ $a && $a->isConfigured() ? 'bg-success' : 'bg-secondary' }}">{{ $a && $a->isConfigured() ? 'ready' : 'not configured' }}</span></div>
                    @if(!empty($opts['queries']))<div class="mt-1">Queries: @foreach($opts['queries'] as $q)<code class="me-1">{{ $q }}</code>; @endforeach</div>@endif
                    @if(!empty($opts['keywords']))<div class="mt-1">Keywords: {{ implode(', ', $opts['keywords']) }}</div>@endif
                    @if(!empty($opts['watchlist']))<div class="mt-1">Watchlist: {{ implode(', ', $opts['watchlist']) }}</div>@endif
                    <div class="text-muted mt-1">Limit {{ $opts['limit'] ?? '—' }}@if(!empty($opts['website_requirement'])) · website: {{ $opts['website_requirement'] }}@endif @if(!empty($opts['min_rating'])) · rating ≥ {{ $opts['min_rating'] }}@endif</div>
                </div>
            @endforeach
        </div>
        <div class="card-lp p-3">
            <h6 class="fw-bold mb-2">Run history</h6>
            <table class="table table-sm small mb-0"><thead><tr class="text-muted"><th>When</th><th>Source</th><th>Status</th><th>Found</th><th>New</th><th>Excl.</th><th>$</th></tr></thead><tbody>
            @forelse($runs as $r)
                <tr><td>{{ $r->created_at->format('M d H:i') }}</td><td>{{ $r->source_slug }}</td><td><span title="{{ implode(' | ', (array) $r->errors) }}" class="badge {{ $r->status === 'completed' ? 'bg-success' : ($r->status === 'skipped' ? 'bg-secondary' : 'bg-danger') }}">{{ $r->status }}</span></td><td>{{ $r->total_found }}</td><td>{{ $r->new_leads }}</td><td>{{ $r->excluded }}</td><td>{{ number_format($r->estimated_cost_cents / 100, 2) }}</td></tr>
            @empty <tr><td colspan="7" class="text-muted">Not run yet.</td></tr> @endforelse
            </tbody></table>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card-lp p-0">
            <div class="p-3 border-bottom d-flex justify-content-between"><h6 class="fw-bold mb-0">Prospects from this ICP</h6><span class="small text-muted">Min score target: {{ $icp->min_lead_score }}</span></div>
            <table class="table table-lp align-middle mb-0 small">
                <thead><tr class="text-muted"><th class="ps-3">Business</th><th>Contact</th><th>Website</th><th>Score</th><th>Next action</th></tr></thead>
                <tbody>
                @forelse($leads as $l)
                    @php $bd = $l->score_breakdown ?? []; @endphp
                    <tr class="{{ $l->overall_score < $icp->min_lead_score ? 'opacity-50' : '' }}">
                        <td class="ps-3"><a href="{{ route('assistant.show', $l) }}" class="fw-semibold text-decoration-none">{{ $l->company?->name ?? $l->full_name }}</a>
                            <div class="text-muted">{{ $l->company?->city }} @if($l->company?->google_rating) · ★ {{ $l->company->google_rating }} ({{ $l->company->google_review_count }}) @endif</div></td>
                        <td>@switch($l->contact_status)
                                @case('identified') <span class="badge bg-success">{{ $l->full_name }}</span> @break
                                @case('generic_inbox') <span class="badge bg-info text-dark">{{ $l->email }}</span> @break
                                @default <span class="badge bg-secondary">needs enrichment</span> @if($l->phone)<div class="text-muted">{{ $l->phone }}</div>@endif
                            @endswitch</td>
                        <td>@if($l->company?->website)
                                @if($l->company->latestWebsiteAudit)<a href="{{ route('audits.show', $l->company->latestWebsiteAudit) }}">gap {{ $l->company->website_opportunity_score }}/100</a>@else <span class="text-muted">not audited</span>@endif
                            @else <span class="badge bg-warning text-dark">no website</span>@endif</td>
                        <td><strong>{{ $l->overall_score }}</strong>@if(!empty($bd['confidence']))<div class="text-muted">{{ $bd['confidence'] }} confidence</div>@endif</td>
                        <td style="max-width:240px">{{ \Illuminate\Support\Str::limit($bd['suggested_next_action'] ?? '', 90) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-center text-muted py-5">No prospects yet. Run a hunt.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
