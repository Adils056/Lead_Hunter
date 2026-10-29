@extends('layouts.app')
@section('title', 'Website report — ' . $company->name)
@section('content')
@php($ai = $report->ai)
<div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
    <div>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-1" style="font-size: .825rem;">
            <li class="breadcrumb-item"><a href="{{ route('companies.show', $company) }}">{{ $company->name }}</a></li><li class="breadcrumb-item active">Website report</li></ol></nav>
        <h3 class="fw-bold mb-1">Website & competitor report</h3>
        <p class="text-muted small mb-0">{{ $company->website }} · {{ $company->industry_group ?: $company->industry }} · {{ $company->city }} {{ $company->country }} · built {{ $report->created_at->diffForHumans() }}</p>
    </div>
    <div class="d-flex gap-2">
        <a href="{{ route('companies.report.pdf', $company) }}" class="btn btn-sm btn-primary-lp"><i class="bi bi-file-earmark-pdf me-1"></i>Download PDF</a>
        <form method="POST" action="{{ route('companies.report.build', $company) }}">@csrf<button class="btn btn-sm btn-light border">Rebuild</button></form>
    </div>
</div>

@if($ai && ($ai['summary'] ?? null))
<div class="card-lp p-3 mb-4 border-start border-4 border-primary">
    <div class="small text-muted text-uppercase fw-semibold mb-1">AI analysis @if($ai['category'] ?? null)· {{ $ai['category'] }}@endif <span class="text-lowercase fw-normal">({{ $report->ai_provider }})</span></div>
    <p class="mb-1">{{ $ai['summary'] }}</p>
    @if($ai['competitor_insight'] ?? null)<p class="mb-0 text-muted small">{{ $ai['competitor_insight'] }}</p>@endif
</div>
@endif

<div class="row g-3 mb-4">
    <div class="col-lg-7"><div class="card-lp p-3 h-100">
        <h6 class="fw-bold mb-1">How the website compares</h6>
        <p class="small text-muted mb-3">Scores from audit checks actually run (0-100). Compared with {{ count($b['competitors']) }} local {{ $company->industry_group ?: 'similar' }} business(es).@if($b['rank']) Overall position: <strong>{{ $b['rank'] }} of {{ $b['field'] }}</strong>.@endif</p>
        @foreach($b['areas'] as $key => $a)
            <div class="mb-3">
                <div class="d-flex justify-content-between small"><span class="fw-semibold">{{ $a['label'] }}</span>
                    <span>You <strong>{{ $a['you'] ?? '–' }}</strong> · Avg {{ $a['average'] ?? '–' }} · Best {{ $a['best'] ?? '–' }}</span></div>
                <div class="progress" style="height: 8px;" role="img" aria-label="{{ $a['label'] }}: you {{ $a['you'] ?? 'not measured' }}, average {{ $a['average'] ?? 'not measured' }}">
                    <div class="progress-bar {{ ($a['you'] ?? 0) >= ($a['average'] ?? 0) ? 'bg-success' : 'bg-warning' }}" style="width: {{ $a['you'] ?? 0 }}%"></div>
                </div>
                @if($a['average'] !== null)<div class="position-relative" style="height: 6px;"><div class="position-absolute bg-dark" style="left: {{ $a['average'] }}%; width: 2px; height: 6px;" title="Competitor average"></div></div>@endif
            </div>
        @endforeach
    </div></div>
    <div class="col-lg-5"><div class="card-lp p-3 h-100">
        <h6 class="fw-bold mb-2">Where you lead</h6>
        <ul class="small mb-3 ps-3">@forelse($b['leads_in'] as $t)<li>{{ $t }}</li>@empty<li class="text-muted">No area clearly ahead yet.</li>@endforelse</ul>
        <h6 class="fw-bold mb-2">Where competitors lead</h6>
        <ul class="small mb-0 ps-3">@forelse($b['behind_in'] as $t)<li>{{ $t }}</li>@empty<li class="text-muted">No area clearly behind.</li>@endforelse</ul>
    </div></div>
</div>

<div class="card-lp p-3 mb-4">
    <h6 class="fw-bold mb-2">Opportunities to improve</h6>
    <div class="table-responsive"><table class="table table-sm small align-middle mb-0">
        <thead><tr class="text-muted"><th>Finding</th><th>Competitors who already have it</th><th>How to fix</th></tr></thead>
        <tbody>
        @forelse(array_slice($b['gaps'], 0, 12) as $g)
            <tr><td><span class="badge {{ $g['status'] === 'fail' ? 'bg-danger-subtle text-danger' : 'bg-warning-subtle text-warning' }} me-1">{{ $g['status'] }}</span><strong>{{ $g['label'] }}</strong><div class="text-muted">{{ \Illuminate\Support\Str::limit($g['evidence'], 140) }}</div></td>
                <td class="text-nowrap">{{ $g['competitors_with_it'] }} of {{ $g['competitors'] }}</td><td>{{ $g['fix'] }}</td></tr>
        @empty <tr><td colspan="3" class="text-muted">No problems found by the audit.</td></tr> @endforelse
        </tbody>
    </table></div>
</div>

<div class="row g-3 mb-4">
    <div class="col-lg-6"><div class="card-lp p-3 h-100">
        <h6 class="fw-bold mb-2">Software that would help win against competitors</h6>
        @foreach(($ai['needs'] ?? []) ?: array_map(fn ($s) => ['solution' => $s['solution'], 'why' => $s['why']], $report->solutions ?? []) as $n)
            <div class="py-2 border-bottom small"><strong>{{ $n['solution'] }}</strong><div class="text-muted">{{ $n['why'] }}</div></div>
        @endforeach
    </div></div>
    <div class="col-lg-6"><div class="card-lp p-3 h-100">
        <h6 class="fw-bold mb-2">Competitors compared</h6>
        <table class="table table-sm small mb-0"><thead><tr class="text-muted"><th>Business</th><th class="text-end">Overall</th><th class="text-end">Google reviews</th></tr></thead><tbody>
        @forelse($b['competitors'] as $c)
            <tr><td><a href="{{ route('companies.show', $c['id']) }}">{{ $c['name'] }}</a><div class="text-muted">{{ $c['city'] }}</div></td><td class="text-end">{{ $c['scores']['overall'] ?? '–' }}</td><td class="text-end">{{ $c['reviews'] ?? '–' }}</td></tr>
        @empty <tr><td colspan="3" class="text-muted">No audited local competitor found yet: they are audited the first time a report is built, so rebuild in a minute.</td></tr> @endforelse
        </tbody></table>
    </div></div>
</div>
@endsection
