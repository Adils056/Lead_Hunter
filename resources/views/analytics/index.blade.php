@extends('layouts.app')

@section('title', 'Analytics & Reporting')

@section('content')
<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
    <div>
        <h3 class="fw-bold mb-1">Sales Intelligence & Campaign Analytics</h3>
        <p class="text-muted small mb-0">Database-backed conversion metrics, funnel velocity, and customer acquisition costs.</p>
    </div>

    <div class="d-flex gap-2">
        <a href="{{ route('analytics.export', ['days' => $days]) }}" class="btn btn-sm btn-outline-secondary d-flex align-items-center gap-1">
            <i class="bi bi-download"></i>
            <span>Export Report CSV</span>
        </a>
        <form action="{{ route('analytics.index') }}" method="GET" class="d-flex gap-1">
            <select name="days" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="7" {{ $days == 7 ? 'selected' : '' }}>Last 7 Days</option>
                <option value="30" {{ $days == 30 ? 'selected' : '' }}>Last 30 Days</option>
                <option value="90" {{ $days == 90 ? 'selected' : '' }}>Last 90 Days</option>
            </select>
        </form>
    </div>
</div>

<!-- Conversion Funnel Row -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card-lp p-3 text-center">
            <div class="text-muted small fw-semibold text-uppercase">Discovered</div>
            <div class="fs-4 fw-bold mt-1 text-dark">{{ $totalLeads }}</div>
            <div class="small text-muted">Total Prospects</div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card-lp p-3 text-center">
            <div class="text-muted small fw-semibold text-uppercase">Verified Deliverable</div>
            <div class="fs-4 fw-bold mt-1 text-info">{{ $verifiedLeads }}</div>
            <div class="small text-info fw-semibold">{{ $verificationRate }}% Rate</div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card-lp p-3 text-center">
            <div class="text-muted small fw-semibold text-uppercase">Qualified ({{ \App\Support\ScoreBands::qualified() }}+)</div>
            <div class="fs-4 fw-bold mt-1 text-primary">{{ $qualifiedLeads }}</div>
            <div class="small text-primary fw-semibold">{{ $qualificationRate }}% Rate</div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card-lp p-3 text-center">
            <div class="text-muted small fw-semibold text-uppercase">Delivered</div>
            <div class="fs-4 fw-bold mt-1 text-success">{{ $delivered }}</div>
            <div class="small text-success fw-semibold">{{ $deliveryRate }}% of Sent</div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card-lp p-3 text-center">
            <div class="text-muted small fw-semibold text-uppercase">Inbound Replies</div>
            <div class="fs-4 fw-bold mt-1 text-purple" style="color: #6366f1;">{{ $replies }}</div>
            <div class="small text-muted">{{ $positiveReplies }} Positive</div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card-lp p-3 text-center">
            <div class="text-muted small fw-semibold text-uppercase">Won Deals</div>
            <div class="fs-4 fw-bold mt-1 text-success">{{ $wonDeals }}</div>
            <div class="small text-success fw-bold">${{ number_format($wonRevenue) }}</div>
        </div>
    </div>
</div>

<!-- Cost & ROI Analysis -->
<div class="card-lp p-4 mb-4" style="background: linear-gradient(135deg, #f8fafc 0%, #ede9fe 100%);">
    <div class="row g-4 align-items-center">
        <div class="col-md-4">
            <span class="text-muted small text-uppercase fw-bold">Total External API Spend</span>
            <div class="fs-3 fw-bold text-dark mt-1">${{ number_format($totalCost, 4) }}</div>
            <div class="small text-muted">OpenAI analysis, Hunter credits & verified infrastructure</div>
        </div>
        <div class="col-md-4 border-start-md">
            <span class="text-muted small text-uppercase fw-bold">Cost Per Qualified Lead (CPQL)</span>
            <div class="fs-3 fw-bold text-primary mt-1">${{ number_format($costPerQualifiedLead, 2) }}</div>
            <div class="small text-muted">Across {{ $qualifiedLeads }} qualified enterprise prospects</div>
        </div>
        <div class="col-md-4 border-start-md">
            <span class="text-muted small text-uppercase fw-bold">Cost Per Booked Discovery Meeting</span>
            <div class="fs-3 fw-bold text-success mt-1">${{ number_format($costPerMeeting, 2) }}</div>
            <div class="small text-muted">High-converting technical discovery calls</div>
        </div>
    </div>
</div>

<!-- Charts Row -->
<div class="row g-4 mb-4">
    <!-- Leads by Country Chart -->
    <div class="col-lg-7">
        <div class="card-lp p-4 h-100">
            <h6 class="fw-bold mb-3">Lead Concentration by Target Country</h6>
            <div style="height: 280px;">
                <canvas id="leadsByCountryChart"></canvas>
            </div>
        </div>
    </div>

    <!-- Score Distribution Donut Chart -->
    <div class="col-lg-5">
        <div class="card-lp p-4 h-100">
            <h6 class="fw-bold mb-3">Lead Priority Score Distribution</h6>
            <div style="height: 240px;" class="d-flex justify-content-center">
                <canvas id="scoreDistChart"></canvas>
            </div>
            <div class="d-flex justify-content-around text-center small text-muted mt-3 pt-2 border-top">
                @foreach($scoreDistribution as $label => $val)
                <div>
                    <strong>{{ $val }}</strong>
                    <div style="font-size: 0.7rem;">{{ explode(' ', $label)[0] }}</div>
                </div>
                @endforeach
            </div>
        </div>
    </div>
</div>

<!-- Source Performance Table -->
<div class="card-lp overflow-hidden mb-4">
    <div class="p-3 border-bottom bg-light-subtle d-flex justify-content-between align-items-center">
        <h6 class="fw-bold mb-0">Lead Discovery Source Performance</h6>
        <span class="small text-muted">Real database aggregated results</span>
    </div>
    <div class="table-responsive">
        <table class="table table-lp align-middle">
            <thead>
                <tr>
                    <th>Lead Source Name</th>
                    <th class="text-center">Total Discovered</th>
                    <th class="text-center">Average Score</th>
                    <th>Quality Assessment</th>
                </tr>
            </thead>
            <tbody>
                @foreach($leadsBySource as $src)
                <tr>
                    <td class="fw-bold text-dark">{{ $src->source_name }}</td>
                    <td class="text-center"><span class="badge bg-light text-dark border">{{ $src->count }}</span></td>
                    <td class="text-center"><span class="fw-bold text-primary">{{ round($src->avg_score, 1) }} pts</span></td>
                    <td>
                        @if($src->avg_score >= 75)
                            <span class="badge bg-success-subtle text-success">High Converting Source</span>
                        @elseif($src->avg_score >= 50)
                            <span class="badge bg-primary-subtle text-primary">Solid B2B Fit</span>
                        @else
                            <span class="badge bg-secondary-subtle text-secondary">General Inflow</span>
                        @endif
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>

@push('scripts')
<script>
document.addEventListener("DOMContentLoaded", function() {
    // 1. Leads By Country Chart
    const countryLabels = @json($leadsByCountry->pluck('country'));
    const countryCounts = @json($leadsByCountry->pluck('count'));

    new Chart(document.getElementById('leadsByCountryChart'), {
        type: 'bar',
        data: {
            labels: countryLabels,
            datasets: [{
                label: 'Prospects',
                data: countryCounts,
                backgroundColor: '#4f46e5',
                borderRadius: 6,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                y: { beginAtZero: true, ticks: { precision: 0 } },
                x: { grid: { display: false } }
            }
        }
    });

    // 2. Score Distribution Donut
    const scoreData = @json($scoreDistribution);
    new Chart(document.getElementById('scoreDistChart'), {
        type: 'doughnut',
        data: {
            labels: Object.keys(scoreData),
            datasets: [{
                data: Object.values(scoreData),
                backgroundColor: ['#10b981', '#f59e0b', '#64748b', '#ef4444'],
                borderWidth: 2,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 10 } } }
            },
            cutout: '70%'
        }
    });
});
</script>
@endpush
@endsection
