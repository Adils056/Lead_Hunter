@extends('layouts.app')
@section('title', "Buyer's Radar")
@section('content')
@php($lv = ['hot' => ['Hot', 'bg-danger', 'bi-fire'], 'warm' => ['Warm', 'bg-warning text-dark', 'bi-thermometer-half'], 'normal' => ['Normal', 'bg-secondary', 'bi-circle']])
<div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
    <div>
        <h3 class="fw-bold mb-1">Buyer's Radar</h3>
        <p class="text-muted small mb-0">Everyone showing buying intent: people asking for developers or agencies, tenders, budgets and price questions, hiring, and problems we found. <strong>Hot</strong> = asking for this work now; <strong>Warm</strong> = clear need, no ask yet; <strong>Normal</strong> = a fit. Hot first.</p>
    </div>
</div>

<div class="row g-2 mb-3">
    @foreach($lv as $k => [$label, $cls, $icon])
        <div class="col-4"><a href="{{ route('radar.index', array_merge(array_filter($f), ['level' => $f['level'] === $k ? null : $k])) }}" class="text-decoration-none">
            <div class="card-lp p-3 {{ $f['level'] === $k ? 'border border-2 border-dark' : '' }}">
                <div class="d-flex justify-content-between align-items-center"><span class="badge {{ $cls }}"><i class="bi {{ $icon }} me-1"></i>{{ $label }}</span><span class="fs-4 fw-bold text-dark">{{ $counts[$k] ?? 0 }}</span></div>
            </div></a></div>
    @endforeach
</div>

<div class="card-lp p-3 mb-3">
    <form method="GET" action="{{ route('radar.index') }}" class="row g-2 align-items-center">
        @if($f['level'])<input type="hidden" name="level" value="{{ $f['level'] }}">@endif
        <div class="col-md-3"><input type="search" name="q" value="{{ $f['q'] }}" class="form-control form-control-sm" placeholder="Search posts…" aria-label="Search"></div>
        <div class="col-6 col-md-2"><select name="platform" class="form-select form-select-sm" onchange="this.form.submit()" aria-label="Platform"><option value="">All platforms</option>@foreach($platforms as $p)<option value="{{ $p }}" @selected($f['platform'] === $p)>{{ $p }}</option>@endforeach</select></div>
        <div class="col-6 col-md-2"><select name="country" class="form-select form-select-sm" onchange="this.form.submit()" aria-label="Country"><option value="">All countries</option>@foreach($countries as $c)<option value="{{ $c }}" @selected($f['country'] === $c)>{{ $c }}</option>@endforeach</select></div>
        <div class="col-6 col-md-2"><select name="category" class="form-select form-select-sm" onchange="this.form.submit()" aria-label="Business category"><option value="">All categories</option>@foreach($categories as $c)<option value="{{ $c }}" @selected($f['category'] === $c)>{{ $c }}</option>@endforeach</select></div>
        <div class="col-6 col-md-2"><select name="service" class="form-select form-select-sm" onchange="this.form.submit()" aria-label="Service"><option value="">All services</option>@foreach($services as $k => $label)<option value="{{ $k }}" @selected($f['service'] === $k)>{{ $label }}</option>@endforeach</select></div>
        <div class="col-md-1"><select name="status" class="form-select form-select-sm" onchange="this.form.submit()" aria-label="Status">@foreach(['new' => 'New', 'contacted' => 'Contacted', 'dismissed' => 'Dismissed', 'all' => 'All'] as $k => $label)<option value="{{ $k }}" @selected($f['status'] === $k)>{{ $label }}</option>@endforeach</select></div>
    </form>
</div>

@if(session('success'))<div class="alert alert-success py-2 small">{{ session('success') }}</div>@endif

<div class="card-lp p-0">
    @forelse($items as $it)
        @php([$label, $cls, $icon] = $lv[$it->intent_level] ?? $lv['normal'])
        <div class="p-3 border-bottom d-flex gap-3 align-items-start">
            <div class="text-center" style="min-width: 64px"><span class="badge {{ $cls }} d-block mb-1"><i class="bi {{ $icon }} me-1"></i>{{ $label }}</span><span class="small text-muted">{{ $it->intent_score }}/100</span></div>
            <div class="flex-grow-1" style="min-width: 0">
                <div class="fw-semibold text-break">@if($it->url)<a href="{{ $it->url }}" target="_blank" rel="noopener noreferrer" class="text-dark">{{ $it->title }}</a>@else{{ $it->title }}@endif</div>
                <div class="small text-muted mb-1">
                    {{ $it->platform }}@if($it->author) · {{ $it->author }}@endif @if($it->company) · <a href="{{ route('companies.show', $it->company) }}">{{ $it->company->name }}</a>@endif
                    @if($it->country) · {{ $it->country }}@endif @if($it->category) · {{ $it->category }}@endif @if($it->service) · {{ \App\Support\ServiceCatalog::label($it->service) }}@endif
                    · {{ $it->posted_at?->diffForHumans() }}
                </div>
                @if($it->excerpt)<div class="small text-break">{{ \Illuminate\Support\Str::limit($it->excerpt, 260) }}</div>@endif
                @if($it->reasons)<div class="mt-1">@foreach($it->reasons as $r)<span class="badge bg-light text-dark border me-1 fw-normal">{{ $r }}</span>@endforeach</div>@endif
            </div>
            <div class="d-flex flex-column gap-1">
                @foreach(['contacted' => 'Contacted', 'dismissed' => 'Dismiss'] as $st => $btn)
                    @if($it->status !== $st)<form method="POST" action="{{ route('radar.status', $it) }}">@csrf<input type="hidden" name="status" value="{{ $st }}"><button class="btn btn-sm btn-light border w-100">{{ $btn }}</button></form>@endif
                @endforeach
            </div>
        </div>
    @empty
        <p class="p-4 text-muted mb-0">Nothing on the radar for these filters. New posts are checked every 2 hours.</p>
    @endforelse
</div>
<div class="mt-3">{{ $items->links() }}</div>
@endsection
