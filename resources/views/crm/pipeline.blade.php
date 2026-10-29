@extends('layouts.app')

@section('title', 'Sales CRM Pipeline')

@section('content')
<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
    <div>
        <h3 class="fw-bold mb-1">CRM Sales Pipeline</h3>
        <p class="text-muted small mb-0">Visual stage progression: from discovery to proposals, negotiations, and won enterprise contracts.</p>
    </div>

    <!-- Filters -->
    <form action="{{ route('crm.pipeline') }}" method="GET" class="d-flex gap-2">
        <select name="assigned_to" class="form-select form-select-sm" onchange="this.form.submit()">
            <option value="">All Representatives</option>
            @foreach($salesReps as $rep)
                <option value="{{ $rep->id }}" {{ $assignedTo == $rep->id ? 'selected' : '' }}>{{ $rep->name }}</option>
            @endforeach
        </select>
        <select name="country" class="form-select form-select-sm" onchange="this.form.submit()">
            <option value="">All Countries</option>
            @foreach($countries as $c)
                <option value="{{ $c }}" {{ $country == $c ? 'selected' : '' }}>{{ $c }}</option>
            @endforeach
        </select>
        @if($assignedTo || $country)
            <a href="{{ route('crm.pipeline') }}" class="btn btn-sm btn-light border text-danger"><i class="bi bi-x-lg"></i></a>
        @endif
    </form>
</div>

<!-- Horizontal Scrolling Kanban Board -->
<div class="d-flex gap-3 overflow-auto pb-4" style="min-height: calc(100vh - 220px);">
    @foreach($pipelineStages as $stageKey => $meta)
    @php
        $leadsInStage = $columnLeads[$stageKey] ?? collect();
    @endphp
    <div class="flex-shrink-0" style="width: 310px;">
        <!-- Column Header -->
        <div class="p-3 rounded-top border-bottom bg-white border d-flex justify-content-between align-items-center shadow-xs">
            <div class="d-flex align-items-center gap-2">
                <span class="rounded-circle bg-{{ $meta['color'] }}" style="width: 10px; height: 10px;"></span>
                <span class="fw-bold text-dark small">{{ $meta['label'] }}</span>
            </div>
            <span class="badge bg-light text-secondary border small">{{ number_format($stageTotals[$stageKey] ?? 0) }}</span>
        </div>

        <!-- Column Body -->
        <div class="p-2 bg-light-subtle rounded-bottom border border-top-0 d-flex flex-column gap-2" style="min-height: 480px;">
            @forelse($leadsInStage as $lead)
            <div class="card-lp p-3 bg-white shadow-xs">
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <span class="badge-lp {{ \App\Support\ScoreBands::badgeClass($lead->overall_score) }}">
                        {{ $lead->overall_score }} pts
                    </span>
                    <form action="{{ route('crm.pipeline.stage', $lead) }}" method="POST" class="m-0">
                        @csrf
                        <select name="lifecycle_stage" class="form-select form-select-sm py-0 border-0 bg-light text-muted" style="width: auto; font-size: 0.72rem;" onchange="this.form.submit()" aria-label="Move to stage">
                            <option value="" selected disabled>Move to…</option>
                            @foreach($pipelineStages as $targetKey => $targetMeta)
                                @if($targetKey !== $stageKey)
                                <option value="{{ $targetKey }}">{{ $targetMeta['label'] }}</option>
                                @endif
                            @endforeach
                        </select>
                    </form>
                </div>

                <a href="{{ route('leads.show', $lead) }}" class="fw-bold text-dark text-decoration-none d-block fs-6 mb-1">
                    {{ $lead->full_name }}
                </a>

                <div class="small text-muted mb-2">
                    <i class="bi bi-building me-1"></i>{{ $lead->company?->name ?? 'Direct' }}
                    <span class="mx-1">•</span>
                    {{ $lead->country }}
                </div>

                @if($lead->deals->isNotEmpty())
                <div class="p-1 px-2 rounded bg-success-subtle border border-success-subtle text-success small fw-bold mb-2 d-flex justify-content-between align-items-center" style="font-size: 0.75rem;">
                    <span><i class="bi bi-cash me-1"></i>{{ $lead->deals->first()->title }}</span>
                    <span>${{ number_format($lead->deals->first()->value) }}</span>
                </div>
                @endif

                <div class="d-flex justify-content-between align-items-center pt-2 border-top text-muted" style="font-size: 0.725rem;">
                    <span><i class="bi bi-person me-1"></i>{{ $lead->assignedUser?->name ?? 'Unassigned' }}</span>
                    @if($lead->email_verification_status === 'verified')
                        <i class="bi bi-check-circle-fill text-success" title="Verified Deliverable"></i>
                    @endif
                </div>
            </div>
            @empty
            <div class="text-center py-4 text-muted small opacity-75">
                No prospects in this stage
            </div>
            @endforelse
            @php $hidden = ($stageTotals[$stageKey] ?? 0) - $leadsInStage->count(); @endphp
            @if($hidden > 0)
            <div class="d-flex justify-content-between small px-1">
                @if($expand !== $stageKey)
                    <a href="{{ request()->fullUrlWithQuery(['expand' => $stageKey]) }}" class="text-decoration-none">Show more</a>
                @else
                    <span class="text-muted">Top {{ $leadsInStage->count() }}</span>
                @endif
                <a href="{{ route('leads.index', array_filter(['stage' => $stageKey, 'country' => $country, 'assigned_to' => $assignedTo])) }}" class="text-decoration-none">View all {{ number_format($stageTotals[$stageKey]) }} →</a>
            </div>
            @endif
        </div>
    </div>
    @endforeach
</div>
@endsection
