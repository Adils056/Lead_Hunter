@extends('layouts.app')
@section('title', 'Website Audit — ' . $audit->company->name)
@section('content')
@php
    $icon = ['pass' => 'bi-check-circle-fill text-success', 'warn' => 'bi-exclamation-triangle-fill text-warning', 'fail' => 'bi-x-circle-fill text-danger', 'info' => 'bi-info-circle text-secondary', 'skipped' => 'bi-dash-circle text-muted'];
    $checks = collect($audit->checks);
@endphp
<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
    <div>
        <nav><ol class="breadcrumb mb-1 small"><li class="breadcrumb-item"><a href="{{ route('companies.show', $audit->company) }}">{{ $audit->company->name }}</a></li><li class="breadcrumb-item active">Website audit</li></ol></nav>
        <h3 class="fw-bold mb-0">Business opportunity report</h3>
        <div class="small text-muted">{{ $audit->final_url ?? $audit->url }} · audited {{ $audit->audited_at?->format('M d, Y H:i') }} · status: {{ $audit->status }}</div>
    </div>
    <div class="text-end">
        <div class="display-6 fw-bold {{ $audit->opportunity_score >= 50 ? 'text-danger' : ($audit->opportunity_score >= 25 ? 'text-warning' : 'text-success') }}">{{ $audit->opportunity_score }}<span class="fs-6 text-muted">/100</span></div>
        <div class="small text-muted">Opportunity (higher = more fixable gaps)</div>
    </div>
</div>

<div class="alert alert-light border small">{{ $audit->summary }}</div>

<div class="row g-4">
    <div class="col-lg-7">
        <div class="card-lp p-3">
            <h6 class="fw-bold mb-1">Checks performed ({{ $checks->whereIn('status', ['pass','warn','fail'])->count() }})</h6>
            <p class="small text-muted">Only checks listed here were run. "Server response time" measures HTML download from our server, not full page load.</p>
            @foreach($checks->sortBy(fn($c) => ['fail'=>0,'warn'=>1,'pass'=>2,'info'=>3,'skipped'=>4][$c['status']] ?? 5) as $c)
                <div class="d-flex gap-2 py-2 border-bottom small">
                    <i class="bi {{ $icon[$c['status']] ?? '' }}"></i>
                    <div><strong>{{ $c['label'] }}</strong> @if(isset($c['value']))<span class="text-muted">— {{ $c['value'] }}</span>@endif
                        <div class="text-muted">{{ $c['evidence'] }}</div>
                        @if(in_array($c['status'], ['fail', 'warn'], true) && ($fix = \App\Services\Audit\WebsiteAuditService::FIXES[$c['key']] ?? null))
                            <div class="mt-1"><i class="bi bi-wrench-adjustable text-primary me-1"></i><span class="fw-semibold">How to fix:</span> {{ $fix }}</div>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card-lp p-3 mb-4">
            <h6 class="fw-bold mb-2">Recommended offers (from evidence)</h6>
            @forelse((array) $audit->opportunities as $o)
                <div class="mb-2"><strong class="small">{{ $o['label'] }}</strong>
                    <ul class="small text-muted mb-0 ps-3">@foreach(array_slice($o['evidence'], 0, 3) as $e)<li>{{ $e }}</li>@endforeach</ul></div>
            @empty <p class="small text-muted mb-0">No service gaps found by the checks.</p> @endforelse
        </div>
        <div class="card-lp p-3 mb-4">
            <h6 class="fw-bold mb-2">Technology detected</h6>
            @forelse((array) $audit->technologies as $t)
                <span class="badge {{ !empty($t['legacy']) ? 'bg-warning text-dark' : 'bg-light text-dark border' }} mb-1" title="{{ $t['evidence'] }}">{{ $t['name'] }} {{ $t['version'] ?? '' }}</span>
            @empty <p class="small text-muted mb-0">Nothing fingerprinted.</p> @endforelse
        </div>
        <div class="card-lp p-3 mb-4">
            <h6 class="fw-bold mb-2">Public business contacts on the site</h6>
            @php $cf = $audit->contacts_found ?? []; @endphp
            @foreach($cf['emails'] ?? [] as $e)<div class="small"><i class="bi bi-envelope"></i> {{ $e['value'] }} @if(!$e['on_company_domain'])<span class="text-muted">(external domain)</span>@endif @if($su = \App\Services\Audit\SafeFetcher::safeUrl($e['source_url']))<a href="{{ $su }}" target="_blank" rel="noopener noreferrer" class="text-muted">source</a>@endif</div>@endforeach
            @foreach($cf['phones'] ?? [] as $p)<div class="small"><i class="bi bi-telephone"></i> {{ $p['value'] }}</div>@endforeach
            @foreach($cf['socials'] ?? [] as $host => $u)<div class="small"><i class="bi bi-globe"></i> <a href="{{ $u }}" target="_blank" rel="noopener">{{ $host }}</a></div>@endforeach
            @if(empty($cf['emails']) && empty($cf['phones']) && empty($cf['socials']))<p class="small text-muted mb-0">None published on the pages checked.</p>@endif
        </div>
        @if($audit->pagespeed)
        <div class="card-lp p-3">
            <h6 class="fw-bold mb-2">Google PageSpeed (mobile)</h6>
            <div class="small">Performance {{ $audit->pagespeed['performance_score'] }}/100 · LCP {{ $audit->pagespeed['lcp'] }} · CLS {{ $audit->pagespeed['cls'] }} · TBT {{ $audit->pagespeed['tbt'] }}</div>
        </div>
        @endif
    </div>
</div>
@endsection
