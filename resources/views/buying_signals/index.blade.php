@extends('layouts.app')

@section('title', 'Buying Intent Signals')

@section('content')
<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
    <div>
        <h3 class="fw-bold mb-1">Buying Intent Signal Engine</h3>
        <p class="text-muted small mb-0">Observed market opportunities: hiring posts, project tenders, tech changes, and procurement RFPs.</p>
    </div>

    <a href="{{ route('buying-signals.create') }}" class="btn btn-sm btn-primary-lp d-flex align-items-center gap-1">
        <i class="bi bi-plus-lg"></i>
        <span>Log Buying Signal</span>
    </a>
</div>

<!-- Filters -->
<div class="card-lp p-3 mb-4">
    <form action="{{ route('buying-signals.index') }}" method="GET" class="row g-2 align-items-center">
        <div class="col-md-3">
            <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="all" {{ $status == 'all' ? 'selected' : '' }}>All Verification Statuses</option>
                <option value="verified" {{ $status == 'verified' ? 'selected' : '' }}>Verified Only</option>
                <option value="unverified" {{ $status == 'unverified' ? 'selected' : '' }}>Unverified</option>
                <option value="dismissed" {{ $status == 'dismissed' ? 'selected' : '' }}>Dismissed</option>
            </select>
        </div>
        <div class="col-md-4">
            <select name="type" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="">All Signal Categories</option>
                @foreach($signalTypes as $sKey => $sLabel)
                    <option value="{{ $sKey }}" {{ $type == $sKey ? 'selected' : '' }}>{{ $sLabel }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-md-3">
            <select name="service" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="">All Agency Services</option>
                @foreach([
                    'Laravel and PHP development',
                    'ERP and CRM development',
                    'Custom software development',
                    'E-commerce development',
                    'API development and integration',
                    'SaaS product development',
                    'Database design, optimization, and system integrations'
                ] as $srv)
                    <option value="{{ $srv }}" {{ $service == $srv ? 'selected' : '' }}>{{ $srv }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-md-2 d-flex gap-2">
            <button type="submit" class="btn btn-sm btn-light border w-100"><i class="bi bi-funnel me-1"></i>Filter</button>
            @if($status !== 'all' || $type || $service)
                <a href="{{ route('buying-signals.index') }}" class="btn btn-sm btn-light border text-danger"><i class="bi bi-x-lg"></i></a>
            @endif
        </div>
    </form>
</div>

<!-- Signals List -->
<div class="row g-3 mb-4">
    @forelse($signals as $signal)
    <div class="col-lg-6">
        <div class="card-lp p-4 h-100 position-relative">
            <div class="d-flex justify-content-between align-items-start mb-2">
                <div>
                    <span class="badge bg-light text-dark border mb-1" style="font-size: 0.725rem;">
                        {{ $signalTypes[$signal->signal_type] ?? ucwords(str_replace('_', ' ', $signal->signal_type)) }}
                    </span>
                    <h5 class="fw-bold text-dark fs-6 mb-1">{{ $signal->title }}</h5>
                    <div class="small text-muted">
                        <i class="bi bi-building me-1"></i>
                        @if($signal->company)
                            <a href="{{ route('companies.show', $signal->company) }}" class="fw-semibold text-decoration-none">
                                {{ $signal->company->name }} ({{ $signal->company->country }})
                            </a>
                        @else
                            <span>Unlinked Company</span>
                        @endif
                    </div>
                </div>

                <div class="text-end">
                    <span class="badge {{ $signal->confidence >= 85 ? 'bg-success-subtle text-success' : 'bg-primary-subtle text-primary' }} border">
                        {{ $signal->confidence }}% Conf
                    </span>
                    <div class="small text-muted mt-1" style="font-size: 0.7rem;">
                        {{ $signal->isFresh() ? 'Fresh (<60d)' : 'Aged' }}
                    </div>
                </div>
            </div>

            <p class="text-secondary small mb-3" style="line-height: 1.5;">
                {{ $signal->evidence_text }}
            </p>

            <div class="p-2 rounded bg-light border small text-muted d-flex justify-content-between align-items-center mb-3" style="font-size: 0.75rem;">
                <div>
                    <strong class="text-dark">Service Fit:</strong> {{ $signal->relevant_service }}
                </div>
                <div>
                    <span>Observed: {{ $signal->observed_date->format('M d, Y') }}</span>
                </div>
            </div>

            <div class="d-flex justify-content-between align-items-center pt-2 border-top">
                <div>
                    <span class="badge {{ $signal->verification_status === 'verified' ? 'bg-success' : ($signal->verification_status === 'dismissed' ? 'bg-danger' : 'bg-warning text-dark') }}">
                        {{ ucfirst($signal->verification_status) }}
                    </span>
                    @if($signal->source_url)
                        <a href="{{ $signal->source_url }}" target="_blank" class="small text-muted text-decoration-none ms-2">
                            <i class="bi bi-box-arrow-up-right me-1"></i>Evidence Link
                        </a>
                    @endif
                </div>

                <div class="btn-group btn-group-sm">
                    @if($signal->verification_status !== 'verified')
                    <form action="{{ route('buying-signals.status', $signal) }}" method="POST">
                        @csrf
                        <input type="hidden" name="verification_status" value="verified">
                        <button type="submit" class="btn btn-sm btn-outline-success py-1 px-2" title="Verify Signal">
                            <i class="bi bi-check-lg me-1"></i>Verify
                        </button>
                    </form>
                    @endif
                    @if($signal->verification_status !== 'dismissed')
                    <form action="{{ route('buying-signals.status', $signal) }}" method="POST" class="ms-1">
                        @csrf
                        <input type="hidden" name="verification_status" value="dismissed">
                        <button type="submit" class="btn btn-sm btn-outline-danger py-1 px-2" title="Dismiss Signal">
                            <i class="bi bi-x-lg me-1"></i>Dismiss
                        </button>
                    </form>
                    @endif
                </div>
            </div>
        </div>
    </div>
    @empty
    <div class="col-12">
        <div class="card-lp p-5 text-center text-muted">
            <i class="bi bi-broadcast fs-1 opacity-50 d-block mb-2"></i>
            No buying intent signals match the selected filters.
        </div>
    </div>
    @endforelse
</div>

@if($signals->hasPages())
<div class="d-flex justify-content-end mb-4">
    {{ $signals->links() }}
</div>
@endif
@endsection
