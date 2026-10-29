@extends('layouts.app')

@section('title', 'Executive Sales Dashboard')

@section('content')
<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
    <div>
        <h3 class="fw-bold mb-1">Executive Sales Intelligence</h3>
        <p class="text-muted small mb-0">Real-time database analytics, buying signals, and campaign outreach performance.</p>
    </div>

    <!-- Quick Actions -->
    <div class="d-flex align-items-center gap-2">
        <a href="{{ route('leads.import') }}" class="btn btn-sm btn-outline-secondary d-flex align-items-center gap-1">
            <i class="bi bi-file-earmark-arrow-up"></i>
            <span>Import CSV</span>
        </a>
        <a href="{{ route('campaigns.create') }}" class="btn btn-sm btn-primary-lp d-flex align-items-center gap-1">
            <i class="bi bi-plus-circle"></i>
            <span>New Campaign</span>
        </a>
    </div>
</div>

<!-- Filter Bar -->
<div class="card-lp p-3 mb-4">
    <form action="{{ route('dashboard') }}" method="GET" class="row g-2 align-items-center">
        <div class="col-md-3">
            <select name="range" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="7" {{ $dateRange == '7' ? 'selected' : '' }}>Last 7 Days</option>
                <option value="30" {{ $dateRange == '30' ? 'selected' : '' }}>Last 30 Days</option>
                <option value="90" {{ $dateRange == '90' ? 'selected' : '' }}>Last 90 Days</option>
                <option value="all" {{ $dateRange == 'all' ? 'selected' : '' }}>All Time</option>
            </select>
        </div>
        <div class="col-md-3">
            <select name="country" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="">All Target Countries</option>
                @foreach($countriesList as $c)
                    <option value="{{ $c }}" {{ $country == $c ? 'selected' : '' }}>{{ $c }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-md-3">
            <select name="service" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="">All Agency Services</option>
                @foreach($servicesList as $slug => $label)
                    <option value="{{ $slug }}" {{ $service == $slug ? 'selected' : '' }}>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-md-3 d-flex gap-2">
            @if($country || $service || $source || $dateRange != '30')
                <a href="{{ route('dashboard') }}" class="btn btn-sm btn-light border text-danger w-100">Reset Filters</a>
            @else
                <button type="submit" class="btn btn-sm btn-light border w-100"><i class="bi bi-funnel me-1"></i>Apply Filters</button>
            @endif
        </div>
    </form>
</div>

<!-- Primary KPI Grid -->
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="metric-card">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="metric-label">Total Prospects</div>
                    <div class="metric-val">{{ number_format($totalContacts) }}</div>
                    <div class="small text-muted mt-1">across {{ $totalCompanies }} verified companies</div>
                </div>
                <div class="icon-box bg-primary-subtle text-primary">
                    <i class="bi bi-people-fill"></i>
                </div>
            </div>
        </div>
    </div>

    <div class="col-sm-6 col-xl-3">
        <div class="metric-card">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="metric-label">Qualified Leads</div>
                    <div class="metric-val text-success">{{ number_format($qualifiedLeads) }}</div>
                    <div class="small text-muted mt-1"><span class="badge bg-success-subtle text-success">{{ $highPriorityLeads }} High Priority</span></div>
                </div>
                <div class="icon-box bg-success-subtle text-success">
                    <i class="bi bi-award-fill"></i>
                </div>
            </div>
        </div>
    </div>

    <div class="col-sm-6 col-xl-3">
        <div class="metric-card">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="metric-label">Verified Emails</div>
                    <div class="metric-val text-info">{{ number_format($verifiedEmails) }}</div>
                    <div class="small text-muted mt-1">Mailbox confirmed (SMTP probe or Hunter)@if($mxOnlyEmails) · {{ number_format($mxOnlyEmails) }} MX-only, unproven @endif</div>
                </div>
                <div class="icon-box bg-info-subtle text-info">
                    <i class="bi bi-patch-check-fill"></i>
                </div>
            </div>
        </div>
    </div>

    <div class="col-sm-6 col-xl-3">
        <div class="metric-card">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="metric-label">Pipeline Value</div>
                    <div class="metric-val text-indigo">${{ number_format($pipelineValue) }}</div>
                    <div class="small text-success mt-1"><i class="bi bi-trophy me-1"></i>${{ number_format($totalDealValue) }} Won</div>
                </div>
                <div class="icon-box bg-warning-subtle text-warning">
                    <i class="bi bi-currency-dollar"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Secondary Delivery & Conversion Grid -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card-lp p-3 text-center">
            <div class="text-muted small fw-semibold text-uppercase">Outreach Sent</div>
            <div class="fs-4 fw-bold mt-1 text-dark">{{ $emailsSent }}</div>
            <div class="small text-muted">{{ $runningCampaigns }} Active Campaigns</div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card-lp p-3 text-center">
            <div class="text-muted small fw-semibold text-uppercase">Delivery Rate</div>
            <div class="fs-4 fw-bold mt-1 text-success">{{ $deliveryRate }}%</div>
            <div class="small text-muted">{{ $emailsDelivered }} Delivered</div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card-lp p-3 text-center">
            <div class="text-muted small fw-semibold text-uppercase">Bounce Rate</div>
            <div class="fs-4 fw-bold mt-1 text-danger">{{ $bounceRate }}%</div>
            <div class="small text-muted">{{ $emailsBounced }} Hard Bounces</div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card-lp p-3 text-center">
            <div class="text-muted small fw-semibold text-uppercase">Reply Rate</div>
            <div class="fs-4 fw-bold mt-1 text-primary">{{ $replyRate }}%</div>
            <div class="small text-muted">{{ $repliesReceived }} Total Inbound</div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card-lp p-3 text-center">
            <div class="text-muted small fw-semibold text-uppercase">Positive Replies</div>
            <div class="fs-4 fw-bold mt-1 text-success">{{ $positiveReplies }}</div>
            <div class="small text-success fw-semibold">{{ $positiveReplyRate }}% of Replies</div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card-lp p-3 text-center">
            <div class="text-muted small fw-semibold text-uppercase">Meetings Booked</div>
            <div class="fs-4 fw-bold mt-1 text-purple" style="color: #6366f1;">{{ $meetingsBooked }}</div>
            <div class="small text-muted">{{ $leadToMeetingRate }}% Conversion</div>
        </div>
    </div>
</div>

<!-- Charts & Lead Table Row -->
<div class="row g-4 mb-4">
    <!-- Chart: CRM Pipeline Funnel -->
    <div class="col-lg-7">
        <div class="card-lp p-4 h-100">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h6 class="fw-bold mb-0">CRM Sales Funnel Distribution</h6>
                <span class="badge bg-light text-secondary border">Live Counts</span>
            </div>
            <div style="height: 280px;">
                <canvas id="pipelineFunnelChart"></canvas>
            </div>
        </div>
    </div>

    <!-- Top Performing Sources -->
    <div class="col-lg-5">
        <div class="card-lp p-4 h-100">
            <h6 class="fw-bold mb-3">Top Lead Discovery Channels</h6>
            <div class="table-responsive">
                <table class="table table-sm table-borderless align-middle">
                    <thead>
                        <tr class="text-muted small border-bottom">
                            <th>Channel</th>
                            <th class="text-center">Leads</th>
                            <th class="text-end">Avg Score</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($sourcePerformance as $src)
                        <tr>
                            <td>
                                <div class="fw-semibold text-dark">{{ $src->source_name }}</div>
                            </td>
                            <td class="text-center">
                                <span class="badge bg-light text-dark border">{{ $src->count }}</span>
                            </td>
                            <td class="text-end">
                                <span class="fw-bold {{ $src->avg_score >= \App\Support\ScoreBands::qualified() ? 'text-success' : 'text-primary' }}">{{ round($src->avg_score, 1) }}</span>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="3" class="text-center text-muted py-3">No source performance data logged yet.</td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-3 pt-3 border-top d-flex justify-content-between align-items-center">
                <span class="small text-muted">Cost Per Qualified Lead:</span>
                <span class="fw-bold text-dark">${{ number_format($costPerQualifiedLead, 2) }}</span>
            </div>
        </div>
    </div>
</div>

<!-- Real-time Activity: Signals, Top Leads, Pending Tasks -->
<div class="row g-4">
    <!-- Highest Priority Qualified Leads -->
    <div class="col-lg-6">
        <div class="card-lp p-4 h-100">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h6 class="fw-bold mb-0">High-Priority Prospects</h6>
                <a href="{{ route('leads.index') }}" class="small text-decoration-none">View All <i class="bi bi-arrow-right"></i></a>
            </div>
            <div class="table-responsive">
                <table class="table table-sm align-middle table-lp">
                    <thead>
                        <tr>
                            <th>Prospect</th>
                            <th>Company</th>
                            <th>Score</th>
                            <th>Stage</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($topLeads as $tl)
                        <tr>
                            <td>
                                <div class="fw-bold text-dark">{{ $tl->full_name }}</div>
                                <div class="small text-muted">{{ $tl->job_title ?: 'Role unknown' }}</div>
                            </td>
                            <td>
                                <div>{{ $tl->company?->name ?? 'Direct' }}</div>
                                <div class="small text-muted">{{ $tl->country }}</div>
                            </td>
                            <td>
                                <span class="badge-lp {{ \App\Support\ScoreBands::badgeClass($tl->overall_score) }}">
                                    {{ $tl->overall_score }} pts
                                </span>
                            </td>
                            <td>
                                <span class="badge bg-light text-dark border small">{{ ucwords(str_replace('_', ' ', $tl->lifecycle_stage)) }}</span>
                            </td>
                            <td class="text-end">
                                <a href="{{ route('leads.show', $tl) }}" class="btn btn-sm btn-light border py-0 px-2"><i class="bi bi-chevron-right"></i></a>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Active Buying Signals -->
    <div class="col-lg-6">
        <div class="card-lp p-4 h-100">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h6 class="fw-bold mb-0">Recent Buying Intent Signals</h6>
                <a href="{{ route('buying-signals.index') }}" class="small text-decoration-none">All Signals <i class="bi bi-arrow-right"></i></a>
            </div>
            <div class="d-flex flex-column gap-3">
                @forelse($recentSignals as $sig)
                <div class="p-3 rounded border bg-light-subtle">
                    <div class="d-flex justify-content-between align-items-start mb-1">
                        <strong class="text-dark" style="font-size: 0.9rem;">{{ $sig->title }}</strong>
                        <span class="badge bg-primary-subtle text-primary">{{ $sig->confidence }}% Conf</span>
                    </div>
                    <div class="small text-muted mb-2">{{ Str::limit($sig->evidence_text, 140) }}</div>
                    <div class="d-flex align-items-center gap-2 text-secondary" style="font-size: 0.75rem;">
                        <span><i class="bi bi-building me-1"></i>{{ $sig->company?->name }}</span>
                        <span>•</span>
                        <span><i class="bi bi-tag me-1"></i>{{ $sig->relevant_service }}</span>
                        <span>•</span>
                        <span>{{ $sig->observed_date->diffForHumans() }}</span>
                    </div>
                </div>
                @empty
                <div class="text-center text-muted py-4">No recent signals logged.</div>
                @endforelse
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
document.addEventListener("DOMContentLoaded", function() {
    const ctx = document.getElementById('pipelineFunnelChart').getContext('2d');
    const stageData = @json($stageCounts);

    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: Object.keys(stageData),
            datasets: [{
                label: 'Active Leads',
                data: Object.values(stageData),
                backgroundColor: [
                    '#94a3b8', '#0ea5e9', '#4f46e5', '#f59e0b',
                    '#38bdf8', '#6366f1', '#10b981', '#a855f7',
                    '#22c55e', '#ef4444'
                ],
                borderRadius: 6,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: { precision: 0 }
                },
                x: {
                    grid: { display: false }
                }
            }
        }
    });
});
</script>
@endpush
@endsection
