@extends('layouts.app')

@section('title', 'Lead Intelligence')

@section('content')
<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
    <div>
        <h3 class="fw-bold mb-1">Lead Intelligence Directory</h3>
        <p class="text-muted small mb-0">Verified business prospects scored by transparent 100-point AI & intent models.</p>
    </div>

    <div class="d-flex gap-2">
        <a href="{{ route('leads.export') }}" class="btn btn-sm btn-outline-secondary d-flex align-items-center gap-1">
            <i class="bi bi-download"></i>
            <span>Export CSV</span>
        </a>
        <a href="{{ route('leads.import') }}" class="btn btn-sm btn-outline-secondary d-flex align-items-center gap-1">
            <i class="bi bi-upload"></i>
            <span>Import CSV</span>
        </a>
        <a href="{{ route('leads.create') }}" class="btn btn-sm btn-primary-lp d-flex align-items-center gap-1">
            <i class="bi bi-plus-lg"></i>
            <span>Add Prospect</span>
        </a>
    </div>
</div>

<!-- Advanced Filter Bar -->
<div class="card-lp p-3 mb-4">
    <form action="{{ route('leads.index') }}" method="GET" class="row g-2 align-items-center">
        <div class="col-md-3">
            <div class="input-group input-group-sm">
                <span class="input-group-text bg-light border-end-0 text-muted"><i class="bi bi-search"></i></span>
                <input type="text" name="search" class="form-control border-start-0" placeholder="Search name, email, job title..." value="{{ $search }}">
            </div>
        </div>

        <div class="col-md-2">
            <select name="stage" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="">All Lifecycle Stages</option>
                @foreach(['new' => 'New', 'researching' => 'Researching', 'qualified' => 'Qualified', 'ready_for_review' => 'Ready for Review', 'contacted' => 'Contacted', 'replied' => 'Replied', 'meeting_booked' => 'Meeting Booked', 'proposal_sent' => 'Proposal Sent', 'won' => 'Won Deal', 'lost' => 'Lost Deal'] as $stgKey => $stgLabel)
                    <option value="{{ $stgKey }}" {{ $stage == $stgKey ? 'selected' : '' }}>{{ $stgLabel }}</option>
                @endforeach
            </select>
        </div>

        <div class="col-md-2">
            <select name="country" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="">All Countries</option>
                @foreach($countries as $c)
                    <option value="{{ $c }}" {{ $country == $c ? 'selected' : '' }}>{{ $c }}</option>
                @endforeach
            </select>
        </div>

        <div class="col-md-2">
            <select name="verification" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="">All Verification States</option>
                @foreach(\App\Support\EmailStatus::ALL as $st)
                <option value="{{ $st }}" {{ $verification == $st ? 'selected' : '' }}>{{ \App\Support\EmailStatus::label($st) }}</option>
                @endforeach
            </select>
        </div>

        <div class="col-md-2">
            <select name="min_score" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="">Any Score</option>
                @php $hb = \App\Support\ScoreBands::thresholds(); @endphp
                <option value="{{ $hb['high'] }}" {{ $minScore == $hb['high'] ? 'selected' : '' }}>High Priority ({{ $hb['high'] }}+ pts)</option>
                <option value="{{ $hb['qualified'] }}" {{ $minScore == $hb['qualified'] ? 'selected' : '' }}>Qualified ({{ $hb['qualified'] }}+ pts)</option>
                <option value="{{ $hb['nurture'] }}" {{ $minScore == $hb['nurture'] ? 'selected' : '' }}>Nurture+ ({{ $hb['nurture'] }}+ pts)</option>
            </select>
        </div>

        <div class="col-md-1 d-flex gap-1">
            <button type="submit" class="btn btn-sm btn-light border w-100" title="Apply Filters"><i class="bi bi-funnel"></i></button>
            @if($search || $stage || $country || $verification || $minScore || $assignedTo)
                <a href="{{ route('leads.index') }}" class="btn btn-sm btn-light border text-danger" title="Clear Filters"><i class="bi bi-x-lg"></i></a>
            @endif
        </div>
    </form>
</div>

<!-- Leads Data Table -->
<div class="card-lp overflow-hidden mb-4">
    <div class="table-responsive">
        <table class="table table-lp align-middle">
            <thead>
                <tr>
                    <th>Prospect & Role</th>
                    <th>Company</th>
                    <th>Email Deliverability</th>
                    <th>Intent & Score</th>
                    <th>Lifecycle Stage</th>
                    <th>Assigned Rep</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($leads as $lead)
                <tr>
                    <td>
                        <a href="{{ route('leads.show', $lead) }}" class="fw-bold text-dark text-decoration-none fs-6">
                            {{ $lead->full_name }}
                        </a>
                        <div class="small text-muted">{{ $lead->job_title ?: 'Role unknown' }}</div>
                    </td>

                    <td>
                        @if($lead->company)
                            <a href="{{ route('companies.show', $lead->company) }}" class="fw-semibold text-dark text-decoration-none">
                                {{ $lead->company->name }}
                            </a>
                            <div class="small text-muted">{{ $lead->country }}</div>
                        @else
                            <span class="text-muted small">Independent / {{ $lead->country }}</span>
                        @endif
                    </td>

                    <td>
                        @if($lead->email)
                            <div class="d-flex align-items-center gap-1">
                                <span class="text-dark small">{{ $lead->email }}</span>
                            </div>
                            <div>
                                <span class="badge {{ \App\Support\EmailStatus::badgeClass($lead->email_verification_status) }}" style="font-size: 0.7rem;">
                                    <i class="bi {{ \App\Support\EmailStatus::icon($lead->email_verification_status) }} me-1"></i>
                                    {{ \App\Support\EmailStatus::label($lead->email_verification_status) }}
                                </span>
                            </div>
                        @else
                            <span class="text-muted small">No email</span>
                        @endif
                    </td>

                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <span class="badge-lp {{ \App\Support\ScoreBands::badgeClass($lead->overall_score) }}">
                                {{ $lead->overall_score }} / 100
                            </span>
                            <span class="small text-muted">({{ $lead->priority_badge }})</span>
                        </div>
                        <div class="small text-muted mt-1" style="font-size: 0.725rem;">
                            Intent: {{ $lead->intent_score }} | Fit: {{ $lead->service_fit_score }}
                            @if($lead->win_interested_chance !== null && !in_array($lead->win_tier, ['D', 'R'], true))
                                | <span class="text-success" title="Success rate: estimated chance of an interested reply · {{ $lead->win_chance_basis }}">{{ rtrim(rtrim(number_format($lead->win_interested_chance, 1), '0'), '.') }}% win</span>
                            @endif
                        </div>
                    </td>

                    <td>
                        <span class="badge bg-light text-dark border">
                            {{ ucwords(str_replace('_', ' ', $lead->lifecycle_stage)) }}
                        </span>
                    </td>

                    <td>
                        <div class="small text-muted">
                            {{ $lead->assignedUser?->name ?? 'Unassigned' }}
                        </div>
                    </td>

                    <td class="text-end">
                        <div class="btn-group btn-group-sm">
                            <a href="{{ route('leads.show', $lead) }}" class="btn btn-light border" title="View Profile">
                                <i class="bi bi-eye"></i>
                            </a>
                            <form action="{{ route('leads.rescore', $lead) }}" method="POST" class="d-inline">
                                @csrf
                                <button type="submit" class="btn btn-light border" title="Re-evaluate 100-Point Score">
                                    <i class="bi bi-arrow-repeat text-primary"></i>
                                </button>
                            </form>
                            <a href="{{ route('leads.edit', $lead) }}" class="btn btn-light border" title="Edit Lead">
                                <i class="bi bi-pencil"></i>
                            </a>
                        </div>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="7" class="text-center py-5 text-muted">
                        <i class="bi bi-person-x fs-1 d-block mb-2 opacity-50"></i>
                        No leads found matching current filter criteria.
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($leads->hasPages())
    <div class="p-3 border-top bg-light-subtle d-flex justify-content-end">
        {{ $leads->links() }}
    </div>
    @endif
</div>
@endsection
