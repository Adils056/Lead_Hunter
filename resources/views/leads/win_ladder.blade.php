@extends('layouts.app')

@section('title', 'Win Ladder')

@section('content')
@php($tiers = \App\Services\Outreach\WinLadderService::TIERS)
<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
    <div>
        <h3 class="fw-bold mb-1">Win Ladder — who to email first</h3>
        <p class="text-muted small mb-0">
            Every lead sits on one rung. Rung A is ranked best-first by buying evidence: an explicit request beats an audited website gap, which beats a generic fit.
            Evidence points are not a win probability — but each rung tells you the one next action.
        </p>
    </div>
    <div class="d-flex gap-2">
        <a href="{{ route('leads.win-ladder.export', ['tier' => $tier]) }}" class="btn btn-sm btn-outline-secondary d-flex align-items-center gap-1">
            <i class="bi bi-download"></i><span>Export rung {{ $tier }} (CSV)</span>
        </a>
        <form method="POST" action="{{ route('leads.win-ladder.refresh') }}">
            @csrf
            <button class="btn btn-sm btn-primary-lp d-flex align-items-center gap-1" type="submit">
                <i class="bi bi-arrow-repeat"></i><span>Recompute ladder</span>
            </button>
        </form>
    </div>
</div>

@if(session('success'))
    <div class="alert alert-success py-2">{{ session('success') }}</div>
@endif

<div class="row g-2 mb-4">
    @foreach(\App\Services\Outreach\WinLadderService::ORDER as $t)
        <div class="col-6 col-md-4 col-xl-2">
            <a href="{{ route('leads.win-ladder', ['tier' => $t]) }}" class="text-decoration-none">
                <div class="card-lp p-3 h-100 {{ $tier === $t ? 'border border-2 border-' . $tiers[$t]['class'] : '' }}">
                    <div class="d-flex justify-content-between align-items-start">
                        <span class="badge bg-{{ $tiers[$t]['class'] }}{{ in_array($tiers[$t]['class'], ['warning','info']) ? ' text-dark' : '' }}"><i class="bi {{ $tiers[$t]['icon'] }} me-1"></i>{{ $t }}</span>
                        <span class="fs-4 fw-bold text-dark">{{ $counts[$t] ?? 0 }}</span>
                    </div>
                    <div class="fw-semibold text-dark mt-2">{{ $tiers[$t]['short'] }}</div>
                    <div class="text-muted" style="font-size: .72rem;">{{ $tiers[$t]['help'] }}</div>
                </div>
            </a>
        </div>
    @endforeach
</div>

@if($unranked > 0)
    <div class="alert alert-warning py-2 small">{{ $unranked }} lead(s) are not ranked yet — click <strong>Recompute ladder</strong>.</div>
@endif

<div class="card-lp overflow-hidden">
    <div class="px-3 py-2 border-bottom d-flex justify-content-between align-items-center">
        <div>
            <span class="badge bg-{{ $tiers[$tier]['class'] }}{{ in_array($tiers[$tier]['class'], ['warning','info']) ? ' text-dark' : '' }} me-2">{{ $tier }}</span>
            <strong>{{ $tiers[$tier]['label'] }}</strong>
            <span class="text-muted small ms-2">{{ $tiers[$tier]['help'] }}</span>
        </div>
        <span class="text-muted small">{{ $leads->total() }} lead(s), best first</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th style="width:3rem">#</th>
                    <th>Company &amp; contact</th>
                    <th>Reach</th>
                    <th>Score / points</th>
                    <th>Why this rung (evidence)</th>
                    <th>{{ $tier === 'A' ? 'Next action' : 'Blocker → next action' }}</th>
                    <th class="text-end">Act</th>
                </tr>
            </thead>
            <tbody>
            @forelse($leads as $i => $lead)
                <tr>
                    <td class="text-muted fw-bold">{{ $leads->firstItem() + $i }}</td>
                    <td>
                        @if($lead->company)
                            <a href="{{ route('companies.show', $lead->company) }}" class="fw-bold text-dark text-decoration-none">{{ $lead->company->name }}</a>
                        @else
                            <span class="fw-bold text-dark">Independent</span>
                        @endif
                        <div class="small">
                            <a href="{{ route('leads.show', $lead) }}" class="text-decoration-none">{{ $lead->full_name }}</a>
                            <span class="text-muted">· {{ $lead->job_title ?: 'role unknown' }}</span>
                        </div>
                        <div class="text-muted" style="font-size:.72rem">{{ $lead->company?->city }} {{ $lead->country }} @if($lead->icpProfile)· ICP: {{ $lead->icpProfile->name }}@endif</div>
                    </td>
                    <td class="small">
                        @if($lead->email)
                            <div>{{ $lead->email }}</div>
                            <span class="badge {{ \App\Support\EmailStatus::badgeClass($lead->email_verification_status) }}" style="font-size:.7rem">{{ \App\Support\EmailStatus::label($lead->email_verification_status) }}</span>
                            <span class="badge bg-light text-dark border" style="font-size:.7rem">{{ str_replace('_', ' ', $lead->contact_status ?? 'identified') }}</span>
                        @else
                            <span class="text-muted">No email</span>
                        @endif
                    </td>
                    <td>
                        <span class="badge-lp {{ \App\Support\ScoreBands::badgeClass($lead->overall_score) }}">{{ $lead->overall_score }} / 100</span>
                        <div class="small text-muted mt-1" title="Evidence points (ranking only)"><i class="bi bi-trophy me-1"></i>{{ $lead->win_points }} pts</div>
                    </td>
                    <td class="small">
                        <ul class="mb-0 ps-3">
                            @foreach(array_slice((array) $lead->win_reasons, 0, 4) as $r)
                                <li>{{ $r }}</li>
                            @endforeach
                        </ul>
                    </td>
                    <td class="small">
                        @foreach((array) $lead->win_blockers as $b)
                            <div class="text-{{ $tier === 'D' ? 'danger' : 'warning-emphasis' }}"><i class="bi bi-exclamation-triangle me-1"></i>{{ $b }}</div>
                        @endforeach
                        <div class="{{ $lead->win_blockers ? 'text-muted' : 'fw-semibold text-success' }}">{{ $lead->win_next_action }}</div>
                    </td>
                    <td class="text-end">
                        <div class="btn-group btn-group-sm">
                            <a href="{{ route('assistant.show', $lead) }}" class="btn btn-light border" title="Sales Assistant: evidence-based draft"><i class="bi bi-magic text-primary"></i></a>
                            @if($lead->email && !in_array($lead->email_verification_status, ['verified']))
                                <form action="{{ route('leads.verify-email', $lead) }}" method="POST" class="d-inline">@csrf
                                    <button class="btn btn-light border" title="Verify email"><i class="bi bi-patch-check"></i></button>
                                </form>
                            @endif
                            <form action="{{ route('leads.rescore', $lead) }}" method="POST" class="d-inline">@csrf
                                <button class="btn btn-light border" title="Re-score and re-rank"><i class="bi bi-arrow-repeat"></i></button>
                            </form>
                            <a href="{{ route('leads.show', $lead) }}" class="btn btn-light border" title="Open lead"><i class="bi bi-eye"></i></a>
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="text-center text-muted py-5">No leads on this rung.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @if($leads->hasPages())
        <div class="px-3 py-2 border-top">{{ $leads->links() }}</div>
    @endif
</div>

<div class="card-lp p-3 mt-4 small text-muted">
    <strong class="text-dark">How the ladder decides (top rule wins):</strong>
    <ol class="mb-1 mt-1">
        <li><strong>R Replied</strong> — any reply that is not a decline or an out-of-office, or stage replied / meeting / proposal. A human answers.</li>
        <li><strong>D Do not email</strong> — suppression list, declined, invalid address, country not approved, ICP exclusion, won/lost/not a fit.</li>
        <li><strong>S In sequence</strong> — draft pending approval or scheduled, or the follow-up interval has not passed.</li>
        <li><strong>C Nurture</strong> — no email, no named contact, sequence exhausted, score below nurture, or more than two blockers.</li>
        <li><strong>A Email now</strong> — all 10 eligibility checks pass, there is a dated observation to personalise with, and the best-fit service is approved.</li>
        <li><strong>B One fix away</strong> — everything else: exactly the listed blocker(s) stand between this lead and rung A.</li>
    </ol>
    Points inside a rung: explicit request up to 400 · software need 150 · website audit gap 2× opportunity score · decision maker 150 (influencer 80) · verified email 100 (risky 40) · approved offer 80 · lead score up to 100 · fresh evidence 60 · active business 30 · phone 10.
</div>
@endsection
