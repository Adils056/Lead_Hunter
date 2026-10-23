@extends('layouts.app')

@section('title', 'Company Intelligence')

@section('content')
<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
    <div>
        <h3 class="fw-bold mb-1">Company Database</h3>
        <p class="text-muted small mb-0">Normalized business directory with tech stack detection, buying signals, and AI research synthesis.</p>
    </div>

    <div class="d-flex gap-2">
        <a href="{{ route('companies.export') }}" class="btn btn-sm btn-outline-secondary d-flex align-items-center gap-1">
            <i class="bi bi-download"></i>
            <span>Export CSV</span>
        </a>
        <a href="{{ route('companies.create') }}" class="btn btn-sm btn-primary-lp d-flex align-items-center gap-1">
            <i class="bi bi-plus-lg"></i>
            <span>Add Company</span>
        </a>
    </div>
</div>

<!-- Filters -->
<div class="card-lp p-3 mb-4">
    <form action="{{ route('companies.index') }}" method="GET" class="row g-2 align-items-center">
        <div class="col-md-4">
            <div class="input-group input-group-sm">
                <span class="input-group-text bg-light border-end-0 text-muted"><i class="bi bi-search"></i></span>
                <input type="text" name="search" class="form-control border-start-0" placeholder="Search by name, domain, or city..." value="{{ $search }}">
            </div>
        </div>
        <div class="col-md-3">
            <select name="country" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="">All Countries</option>
                @foreach($countries as $c)
                    <option value="{{ $c }}" {{ $country == $c ? 'selected' : '' }}>{{ $c }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-md-3">
            <select name="industry" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="">All Industries</option>
                @foreach($industries as $ind)
                    <option value="{{ $ind }}" {{ $industry == $ind ? 'selected' : '' }}>{{ $ind }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-md-2 d-flex gap-2">
            <button type="submit" class="btn btn-sm btn-light border w-100"><i class="bi bi-funnel me-1"></i>Filter</button>
            @if($search || $country || $industry)
                <a href="{{ route('companies.index') }}" class="btn btn-sm btn-light border text-danger"><i class="bi bi-x-lg"></i></a>
            @endif
        </div>
    </form>
</div>

<!-- Companies Table -->
<div class="card-lp overflow-hidden mb-4">
    <div class="table-responsive">
        <table class="table table-lp align-middle">
            <thead>
                <tr>
                    <th>Company Name & Domain</th>
                    <th>Industry & Market</th>
                    <th>Company Size</th>
                    <th>Tech Stack</th>
                    <th class="text-center">Contacts</th>
                    <th class="text-center">Signals</th>
                    <th>Status</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($companies as $company)
                <tr>
                    <td>
                        <div class="fw-bold text-dark fs-6">{{ $company->name }}</div>
                        @if($company->website)
                            <a href="{{ $company->website }}" target="_blank" rel="noopener noreferrer" class="text-muted small text-decoration-none">
                                <i class="bi bi-globe me-1 text-primary"></i>{{ $company->domain ?: $company->website }}
                            </a>
                        @endif
                    </td>
                    <td>
                        <div class="text-dark fw-semibold small">{{ $company->industry ?: 'General' }}</div>
                        <div class="text-muted small"><i class="bi bi-geo-alt me-1"></i>{{ $company->city ? $company->city . ', ' : '' }}{{ $company->country }}</div>
                    </td>
                    <td>
                        <span class="badge bg-light text-dark border">{{ $company->size_range ?: 'Unspecified' }}</span>
                    </td>
                    <td>
                        @if(!empty($company->tech_stack))
                            <div class="d-flex flex-wrap gap-1">
                                @foreach(array_slice((array) $company->tech_stack, 0, 3) as $tech)
                                    <span class="badge bg-primary-subtle text-primary border" style="font-size: 0.7rem;">{{ $tech }}</span>
                                @endforeach
                                @if(count((array) $company->tech_stack) > 3)
                                    <span class="badge bg-light text-secondary border" style="font-size: 0.65rem;">+{{ count((array) $company->tech_stack) - 3 }}</span>
                                @endif
                            </div>
                        @else
                            <span class="text-muted small">Not identified</span>
                        @endif
                    </td>
                    <td class="text-center">
                        <span class="badge bg-light text-dark border">{{ $company->leads_count }}</span>
                    </td>
                    <td class="text-center">
                        <span class="badge bg-warning-subtle text-warning border">{{ $company->buying_signals_count }}</span>
                    </td>
                    <td>
                        <span class="badge {{ $company->status === 'customer' ? 'bg-success-subtle text-success' : ($company->status === 'prospect' ? 'bg-primary-subtle text-primary' : 'bg-light text-secondary') }} border">
                            {{ ucfirst($company->status) }}
                        </span>
                    </td>
                    <td class="text-end">
                        <a href="{{ route('companies.show', $company) }}" class="btn btn-sm btn-light border px-2 py-1" title="View Intelligence Profile">
                            <i class="bi bi-eye"></i> View
                        </a>
                        <a href="{{ route('companies.edit', $company) }}" class="btn btn-sm btn-light border px-2 py-1 ms-1" title="Edit Company">
                            <i class="bi bi-pencil"></i>
                        </a>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="8" class="text-center py-5 text-muted">
                        <i class="bi bi-buildings fs-1 d-block mb-2 text-secondary opacity-50"></i>
                        No companies found matching the selected filters.
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($companies->hasPages())
    <div class="p-3 border-top bg-light-subtle d-flex justify-content-end">
        {{ $companies->links() }}
    </div>
    @endif
</div>
@endsection
