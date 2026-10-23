@extends('layouts.app')

@section('title', $company->name . ' — Company Profile')

@section('content')
<div class="mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-2" style="font-size: 0.825rem;">
            <li class="breadcrumb-item"><a href="{{ route('companies.index') }}" class="text-decoration-none">Companies</a></li>
            <li class="breadcrumb-item active" aria-current="page">{{ $company->name }}</li>
        </ol>
    </nav>

    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
        <div>
            <div class="d-flex align-items-center gap-2">
                <h3 class="fw-bold mb-0">{{ $company->name }}</h3>
                <span class="badge {{ $company->status === 'customer' ? 'bg-success' : 'bg-primary' }}">{{ ucfirst($company->status) }}</span>
            </div>
            <div class="d-flex flex-wrap align-items-center gap-3 text-muted small mt-1">
                @if($company->website)
                    <a href="{{ $company->website }}" target="_blank" class="text-decoration-none text-muted">
                        <i class="bi bi-link-45deg"></i> {{ $company->website }}
                    </a>
                @endif
                <span><i class="bi bi-geo-alt"></i> {{ $company->city ? $company->city . ', ' : '' }}{{ $company->country }}</span>
                <span><i class="bi bi-tag"></i> {{ $company->industry ?: 'General Industry' }}</span>
                <span><i class="bi bi-people"></i> {{ $company->size_range ?: 'Size N/A' }}</span>
            </div>
        </div>

        <div class="d-flex gap-2">
            @if($company->website || $company->domain)
            <form action="{{ route('companies.audit', $company) }}" method="POST">
                @csrf
                <button type="submit" class="btn btn-sm btn-outline-success d-flex align-items-center gap-1 shadow-sm">
                    <i class="bi bi-clipboard2-pulse"></i>
                    <span>{{ $company->website_audited_at ? 'Re-run' : 'Run' }} Website Audit</span>
                </button>
            </form>
            @if($company->latestWebsiteAudit)
            <a href="{{ route('audits.show', $company->latestWebsiteAudit) }}" class="btn btn-sm btn-light border d-flex align-items-center gap-1">
                <i class="bi bi-file-earmark-text"></i> Audit {{ $company->website_opportunity_score }}/100
            </a>
            @endif
            @endif
            <form action="{{ route('companies.research', $company) }}" method="POST">
                @csrf
                <button type="submit" class="btn btn-sm btn-outline-primary d-flex align-items-center gap-1 shadow-sm">
                    <i class="bi bi-stars text-primary"></i>
                    <span>Run AI Analysis</span>
                </button>
            </form>
            <a href="{{ route('companies.edit', $company) }}" class="btn btn-sm btn-light border d-flex align-items-center gap-1">
                <i class="bi bi-pencil"></i>
                <span>Edit</span>
            </a>
            <a href="{{ route('leads.create') }}?company_id={{ $company->id }}" class="btn btn-sm btn-primary-lp d-flex align-items-center gap-1">
                <i class="bi bi-person-plus"></i>
                <span>Add Contact</span>
            </a>
        </div>
    </div>
</div>

<div class="row g-4">
    <!-- Left Column: AI Research & Intel -->
    <div class="col-lg-7">
        <!-- AI Research Card -->
        <div class="card-lp p-4 mb-4" style="border-left: 4px solid #4f46e5;">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <div class="d-flex align-items-center gap-2">
                    <span class="p-2 rounded bg-primary-subtle text-primary"><i class="bi bi-robot fs-5"></i></span>
                    <div>
                        <h6 class="fw-bold mb-0">AI Company Intelligence & Sales Research</h6>
                        <span class="text-muted" style="font-size: 0.75rem;">
                            {{ $company->ai_researched_at ? 'Evaluated ' . $company->ai_researched_at->diffForHumans() : 'Awaiting first analysis' }}
                        </span>
                    </div>
                </div>

                @if(!empty($company->ai_research['confidence_level']))
                <span class="badge bg-primary text-white">
                    Confidence: {{ $company->ai_research['confidence_level'] }}
                </span>
                @endif
            </div>

            @if(!empty($company->ai_research))
                <div class="mb-3">
                    <strong class="d-block small text-muted text-uppercase fw-bold mb-1">Business Summary</strong>
                    <p class="text-dark mb-0" style="font-size: 0.925rem; line-height: 1.6;">
                        {{ $company->ai_research['business_summary'] ?? $company->description }}
                    </p>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <div class="p-3 bg-light rounded border h-100">
                            <strong class="d-block small text-primary text-uppercase fw-bold mb-2">
                                <i class="bi bi-bullseye me-1"></i> Recommended Agency Service
                            </strong>
                            <div class="fw-bold text-dark fs-6">{{ $company->ai_research['recommended_agency_service'] ?? 'Custom Software' }}</div>
                            <div class="small text-muted mt-1">{{ $company->ai_research['supporting_evidence'] ?? '' }}</div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="p-3 bg-light rounded border h-100">
                            <strong class="d-block small text-secondary text-uppercase fw-bold mb-2">
                                <i class="bi bi-lightning-charge me-1"></i> Potential Software Needs
                            </strong>
                            <ul class="mb-0 ps-3 small text-dark">
                                @foreach((array) ($company->ai_research['potential_software_needs'] ?? []) as $need)
                                    <li>{{ $need }}</li>
                                @endforeach
                            </ul>
                        </div>
                    </div>
                </div>

                <div class="p-3 rounded bg-primary-subtle border border-primary-subtle mb-3">
                    <strong class="d-block text-primary small text-uppercase fw-bold mb-1">
                        <i class="bi bi-chat-quote-fill me-1"></i> Tailored Outreach Angle
                    </strong>
                    <div class="text-dark small fw-medium">
                        "{{ $company->ai_research['personalized_outreach_angle'] ?? 'Reference proven track record delivering scalable web systems.' }}"
                    </div>
                </div>

                @if(!empty($company->ai_research['missing_information']))
                <div class="small text-muted">
                    <i class="bi bi-info-circle me-1"></i><strong>Information needed:</strong> {{ $company->ai_research['missing_information'] }}
                </div>
                @endif
            @else
                <div class="text-center py-4">
                    <p class="text-muted small mb-3">No AI research generated yet for this company profile.</p>
                    <form action="{{ route('companies.research', $company) }}" method="POST">
                        @csrf
                        <button type="submit" class="btn btn-sm btn-primary-lp">
                            <i class="bi bi-stars me-1"></i> Generate AI Research Report
                        </button>
                    </form>
                </div>
            @endif
        </div>

        <!-- Buying Signals Card -->
        <div class="card-lp p-4 mb-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h6 class="fw-bold mb-0"><i class="bi bi-broadcast-pin text-warning me-1"></i> Buying Intent Signals ({{ $company->buyingSignals->count() }})</h6>
                <a href="{{ route('buying-signals.create') }}?company_id={{ $company->id }}" class="btn btn-sm btn-light border py-1 px-2">
                    <i class="bi bi-plus-lg"></i> Log Signal
                </a>
            </div>

            <div class="d-flex flex-column gap-3">
                @forelse($company->buyingSignals as $signal)
                <div class="p-3 rounded border bg-light-subtle">
                    <div class="d-flex justify-content-between align-items-start mb-1">
                        <span class="fw-bold text-dark">{{ $signal->title }}</span>
                        <span class="badge {{ $signal->verification_status === 'verified' ? 'bg-success-subtle text-success' : 'bg-secondary-subtle text-secondary' }} border">
                            {{ ucfirst($signal->verification_status) }}
                        </span>
                    </div>
                    <div class="small text-muted mb-2">{{ $signal->evidence_text }}</div>
                    <div class="d-flex align-items-center justify-content-between text-muted" style="font-size: 0.75rem;">
                        <div>
                            <span class="badge bg-light text-dark border me-1">{{ $signal->relevant_service }}</span>
                            <span>Confidence: {{ $signal->confidence }}%</span>
                        </div>
                        <span>Observed: {{ $signal->observed_date->format('M d, Y') }}</span>
                    </div>
                </div>
                @empty
                <div class="text-center py-3 text-muted small">No buying signals recorded yet.</div>
                @endforelse
            </div>
        </div>
    </div>

    <!-- Right Column: Contacts, Tech Stack, Deals -->
    <div class="col-lg-5">
        <!-- Verified Contacts / Leads -->
        <div class="card-lp p-4 mb-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h6 class="fw-bold mb-0"><i class="bi bi-person-lines-fill text-primary me-1"></i> Key Contacts ({{ $company->leads->count() }})</h6>
                <a href="{{ route('leads.create') }}?company_id={{ $company->id }}" class="btn btn-sm btn-light border py-1 px-2">
                    <i class="bi bi-plus"></i> Add
                </a>
            </div>

            <div class="d-flex flex-column gap-3">
                @forelse($company->leads as $lead)
                <div class="p-3 rounded border bg-white">
                    <div class="d-flex justify-content-between align-items-start mb-1">
                        <div>
                            <a href="{{ route('leads.show', $lead) }}" class="fw-bold text-dark text-decoration-none">
                                {{ $lead->full_name }}
                            </a>
                            <div class="small text-muted">{{ $lead->job_title ?: 'Manager' }}</div>
                        </div>
                        <span class="badge-lp {{ $lead->overall_score >= 75 ? 'badge-score-high' : ($lead->overall_score >= 50 ? 'badge-score-medium' : 'badge-score-low') }}">
                            {{ $lead->overall_score }} pts
                        </span>
                    </div>

                    @if($lead->email)
                    <div class="small mt-2 d-flex align-items-center gap-1">
                        <i class="bi bi-envelope text-muted"></i>
                        <span class="text-muted">{{ $lead->email }}</span>
                        <span class="badge {{ \App\Support\EmailStatus::badgeClass($lead->email_verification_status) }} ms-auto" style="font-size: 0.65rem;">
                            {{ \App\Support\EmailStatus::label($lead->email_verification_status) }}
                        </span>
                    </div>
                    @endif
                </div>
                @empty
                <div class="text-center py-3 text-muted small">No contacts associated with this company yet.</div>
                @endforelse
            </div>
        </div>

        <!-- Tech Stack -->
        <div class="card-lp p-4 mb-4">
            <h6 class="fw-bold mb-3"><i class="bi bi-cpu text-info me-1"></i> Technology Stack</h6>
            @if(!empty($company->tech_stack))
                <div class="d-flex flex-wrap gap-2">
                    @foreach((array) $company->tech_stack as $tech)
                        <span class="badge bg-light text-dark border px-2 py-1 fs-7">
                            <i class="bi bi-code-slash text-primary me-1"></i>{{ $tech }}
                        </span>
                    @endforeach
                </div>
            @else
                <p class="text-muted small mb-0">No technology stack identified yet.</p>
            @endif
        </div>

        <!-- Deals & Value -->
        <div class="card-lp p-4 mb-4">
            <h6 class="fw-bold mb-3"><i class="bi bi-cash-stack text-success me-1"></i> Active Pipeline Deals</h6>
            @forelse($company->deals as $deal)
            <div class="p-3 rounded border bg-light-subtle mb-2">
                <div class="d-flex justify-content-between align-items-center">
                    <strong class="text-dark small">{{ $deal->title }}</strong>
                    <span class="fw-bold text-success">${{ number_format($deal->value) }}</span>
                </div>
                <div class="d-flex justify-content-between align-items-center small text-muted mt-1">
                    <span>Stage: {{ ucwords(str_replace('_', ' ', $deal->stage)) }}</span>
                    <span>Win Prob: {{ $deal->probability }}%</span>
                </div>
            </div>
            @empty
            <p class="text-muted small mb-0">No deals registered for this company.</p>
            @endforelse
        </div>
    </div>
</div>
@endsection
