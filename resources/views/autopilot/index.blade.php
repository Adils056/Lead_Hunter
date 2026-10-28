@extends('layouts.app')
@section('title', 'Autopilot')
@section('content')
@php
    $hbOk = fn($c) => isset($heartbeats[$c]) && $heartbeats[$c]->beat_at->gt(now()->subMinutes(config('leadpilot.incidents.heartbeat_stale_minutes')));
    $stateOrder = \App\Services\Autopilot\LeadStateService::STATES;
@endphp
<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
    <div>
        <h3 class="fw-bold mb-1">Autopilot</h3>
        <p class="text-muted small mb-0">The system works on its own and stops only for exceptions. Times shown in {{ config('leadpilot.timezone') }}.</p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a href="{{ route('approvals.index') }}" class="btn btn-sm {{ $summary['pending_approvals'] ? 'btn-warning' : 'btn-light border' }}"><i class="bi bi-check2-square"></i> Approvals <span class="badge bg-dark">{{ $summary['pending_approvals'] }}</span></a>
        @if(auth()->user()->isAdmin())
        <a href="{{ route('autopilot.setup') }}" class="btn btn-sm btn-light border"><i class="bi bi-heart-pulse"></i> Setup &amp; health</a>
        <a href="{{ route('autopilot.policy') }}" class="btn btn-sm btn-light border"><i class="bi bi-sliders"></i> Autonomy &amp; budgets</a>
        <form method="POST" action="{{ route('autopilot.run') }}">@csrf<button class="btn btn-sm btn-primary-lp"><i class="bi bi-play-fill"></i> Run cycle now</button></form>
        @endif
    </div>
</div>

@foreach($incidents as $i)
<div class="alert {{ $i->severity === 'critical' ? 'alert-danger' : 'alert-warning' }} d-flex justify-content-between align-items-start gap-3">
    <div><strong>🚨 {{ $i->title }}</strong> <span class="small text-muted">#{{ $i->id }} · {{ $i->created_at->diffForHumans() }}</span><div class="small">{{ $i->details }}</div></div>
    @if(auth()->user()->isAdmin())
    <form method="POST" action="{{ route('autopilot.incident.resolve', $i) }}">@csrf<input type="hidden" name="resume" value="1"><button class="btn btn-sm btn-outline-dark">Resolve &amp; resume</button></form>
    @endif
</div>
@endforeach

<div class="row g-3 mb-4">
    @foreach([['New leads today', $stats['new_leads']], ['Ready / qualified', $stats['qualified']], ['Emails sent today', $stats['sent_today']], ['Replies today', $stats['replies_today']], ['Spend today', '$' . number_format($stats['spend_today'], 2)], ['Spend this month', '$' . number_format($stats['spend_month'], 2)]] as [$l, $v])
    <div class="col-6 col-md-2"><div class="card-lp p-3 h-100"><div class="small text-muted">{{ $l }}</div><div class="fs-4 fw-bold">{{ $v }}</div></div></div>
    @endforeach
</div>

<div class="row g-4">
    <div class="col-lg-7">
        <div class="card-lp p-3 mb-4">
            <h6 class="fw-bold mb-2">Needs your decision</h6>
            @forelse($approvals as $a)
                <a href="{{ route('approvals.show', $a) }}" class="d-flex justify-content-between border-bottom py-2 text-decoration-none text-dark small">
                    <span><span class="badge {{ in_array($a->priority, ['critical','high']) ? 'bg-danger' : 'bg-secondary' }} me-1">{{ $a->priority }}</span>{{ $a->title }}</span>
                    <span class="text-muted">{{ $a->due_at && $a->due_at->isPast() ? 'overdue' : 'due ' . $a->due_at?->diffForHumans() }}</span>
                </a>
            @empty
                <p class="small text-muted mb-0">Nothing waiting. ✔</p>
            @endforelse
        </div>
        <div class="card-lp p-3 mb-4">
            <h6 class="fw-bold mb-2">Pipeline</h6>
            <div class="d-flex flex-wrap gap-2">
                @foreach($stateOrder as $k => $label)
                    <span class="badge bg-light text-dark border p-2">{{ $label }} <strong class="ms-1">{{ $states[$k] ?? 0 }}</strong></span>
                @endforeach
            </div>
            <div class="small text-muted mt-2">A high score never means "send". Only leads that pass the eligibility gate become <em>Eligible for outreach</em>.</div>
        </div>
        <div class="card-lp p-3">
            <h6 class="fw-bold mb-2">Where Autopilot is searching (learned)</h6>
            <table class="table table-sm small mb-0"><thead><tr class="text-muted"><th>ICP</th><th>Source</th><th>Runs</th><th>Leads</th><th>Qualified</th><th>Positive</th><th>$</th><th>Status</th></tr></thead><tbody>
            @forelse($arms as $arm)
                <tr><td>{{ $arm->icpProfile?->name }}</td><td>{{ $arm->source_slug }}</td><td>{{ $arm->runs }}</td><td>{{ $arm->leads }}</td><td>{{ $arm->qualified }}</td><td>{{ $arm->positive }}</td><td>{{ number_format($arm->cost_cents / 100, 2) }}</td>
                    <td>{{ $arm->exhausted_until && $arm->exhausted_until->isFuture() ? 'resting until ' . $arm->exhausted_until->format('M d') : 'active' }}</td></tr>
            @empty <tr><td colspan="8" class="text-muted">No searches yet.</td></tr> @endforelse
            </tbody></table>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card-lp p-3 mb-4">
            <h6 class="fw-bold mb-2">System</h6>
            @foreach(['scheduler' => 'Scheduler', 'queue_worker' => 'Queue worker', 'autopilot' => 'Last finished autopilot cycle', 'reply_sync' => 'Reply sync'] as $c => $l)
                <div class="d-flex justify-content-between small py-1 border-bottom"><span>{{ $l }}</span>
                    <span class="{{ $hbOk($c) ? 'text-success' : 'text-danger' }}">{{ isset($heartbeats[$c]) ? $heartbeats[$c]->beat_at->diffForHumans() : 'never' }}</span></div>
            @endforeach
            @php $cycleStart = $heartbeats['autopilot_started'] ?? null; $cycleEnd = $heartbeats['autopilot'] ?? null; @endphp
            @if($cycleStart && (!$cycleEnd || $cycleStart->beat_at->gt($cycleEnd->beat_at)))
                <div class="d-flex justify-content-between small py-1 border-bottom"><span>Current cycle</span>
                    <span class="{{ $cycleStart->beat_at->gt(now()->subMinutes(20)) ? 'text-primary' : 'text-danger' }}">started {{ $cycleStart->beat_at->diffForHumans() }} · step: {{ $heartbeats['autopilot_step']->meta['step'] ?? '—' }}</span></div>
            @endif
        </div>
        <div class="card-lp p-3 mb-4">
            <h6 class="fw-bold mb-2">Workflows</h6>
            @foreach($workflows as $w => $st)
                <div class="d-flex justify-content-between align-items-center small py-1 border-bottom">
                    <span>{{ ucfirst(str_replace('_', ' ', $w)) }} @if($st['paused'])<span class="badge bg-danger">paused</span><div class="text-muted" style="font-size:.72rem">{{ $st['reason'] }}</div>@else<span class="badge bg-success">running</span>@endif</span>
                    @if(auth()->user()->isAdmin())
                    <form method="POST" action="{{ route('autopilot.workflow', $w) }}">@csrf<input type="hidden" name="pause" value="{{ $st['paused'] ? 0 : 1 }}"><button class="btn btn-sm btn-link p-0">{{ $st['paused'] ? 'Resume' : 'Pause' }}</button></form>
                    @endif
                </div>
            @endforeach
        </div>
        <div class="card-lp p-3">
            <h6 class="fw-bold mb-2">Integrations</h6>
            @foreach($providers as $slug => $p)
                <div class="d-flex justify-content-between small py-1 border-bottom"><span>{{ $p['label'] }} @if($p['paid'])<span class="badge bg-light text-dark border">paid</span>@endif</span>
                    <span class="{{ !$p['configured'] ? 'text-muted' : ($p['verified_at'] && $p['status'] === 'healthy' ? 'text-success' : ($p['status'] === 'disabled' ? 'text-danger' : 'text-warning')) }}">
                        {{ !$p['configured'] ? 'not configured' : ($p['verified_at'] ? $p['status'] . ' · verified ' . $p['verified_at']->diffForHumans() : 'not yet verified') }}</span></div>
            @endforeach
            <div class="small text-muted mt-2">Local data © OpenStreetMap contributors (ODbL) and Overture Maps Foundation places (CDLA-Permissive-2.0 / Apache-2.0 per source).</div>
        </div>
    </div>
</div>
@endsection
