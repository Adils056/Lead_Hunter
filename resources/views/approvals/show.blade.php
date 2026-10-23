@extends('layouts.app')
@section('title', 'Approval #' . $a->id)
@section('content')
@php $p = (array) $a->payload; @endphp
<nav><ol class="breadcrumb small mb-1"><li class="breadcrumb-item"><a href="{{ route('approvals.index') }}">Approvals</a></li><li class="breadcrumb-item active">#{{ $a->id }}</li></ol></nav>
<div class="d-flex justify-content-between align-items-start gap-3 mb-3">
    <div><h4 class="fw-bold mb-1">{{ $a->title }}</h4>
        <div class="small text-muted">{{ str_replace('_',' ',$a->type) }} · priority <strong>{{ $a->priority }}</strong> · status <strong>{{ str_replace('_',' ',$a->status) }}</strong> · due {{ $a->due_at?->timezone(config('leadpilot.timezone'))->format('D M d, H:i') }} ({{ config('leadpilot.timezone') }}) @if($a->escalation_level)· escalated ×{{ $a->escalation_level }}@endif</div></div>
    @if($lead)<a class="btn btn-sm btn-light border" href="{{ route('assistant.show', $lead) }}">Open lead</a>@endif
</div>
<div class="row g-4">
    <div class="col-lg-8">
        <div class="card-lp p-3 mb-3"><h6 class="fw-bold">What happened &amp; why you're needed</h6><p class="small mb-0">{{ $a->summary }}</p>
            @if($a->recommended_action)<div class="small mt-2"><strong>Recommended:</strong> {{ ucfirst(str_replace('_',' ',$a->recommended_action)) }}</div>@endif</div>
        @if($a->evidence)<div class="card-lp p-3 mb-3"><h6 class="fw-bold">Evidence</h6><ul class="small mb-0 ps-3">@foreach((array)$a->evidence as $k => $v)<li>@if(is_string($k))<strong>{{ $k }}:</strong> @endif<span style="white-space:pre-wrap">{{ is_scalar($v) ? $v : json_encode($v) }}</span></li>@endforeach</ul></div>@endif
        @if($a->attempted)<div class="card-lp p-3 mb-3"><h6 class="fw-bold">Already done by the system</h6><ul class="small mb-0 ps-3">@foreach((array)$a->attempted as $x)<li>{{ $x }}</li>@endforeach</ul></div>@endif

        @if($canDecide && $a->isOpen())
        <form method="POST" action="{{ route('approvals.decide', $a) }}" class="card-lp p-3 mb-3">@csrf
            @if(isset($p['subject']) || isset($p['body']))
                <label class="small fw-semibold">Subject</label><input name="payload[subject]" class="form-control form-control-sm mb-2" value="{{ $p['subject'] ?? '' }}">
                <label class="small fw-semibold">Message</label><textarea name="payload[body]" rows="10" class="form-control form-control-sm mb-2">{{ $p['body'] ?? '' }}</textarea>
            @endif
            @if($a->type === 'low_confidence_reply')
                <label class="small fw-semibold">Correct category</label>
                <select name="payload[category]" class="form-select form-select-sm mb-2">@foreach(\App\Services\Sales\ReplyClassifier::CATEGORIES as $c)<option value="{{ $c }}" @selected(($p['category'] ?? '') === $c)>{{ str_replace('_',' ',$c) }}</option>@endforeach</select>
            @endif
            <input name="note" class="form-control form-control-sm mb-2" placeholder="Note (optional)">
            <div class="d-flex flex-wrap gap-2">
                <button name="action" value="{{ (isset($p['body']) || $a->type === 'low_confidence_reply') ? 'edit_approve' : 'approve' }}" class="btn btn-sm btn-success">Approve{{ isset($p['body']) ? ' (with edits)' : '' }}</button>
                <button name="action" value="reject" class="btn btn-sm btn-outline-danger">Reject</button>
                <button name="action" value="request_info" class="btn btn-sm btn-outline-secondary">Request info</button>
                <button name="action" value="escalate" class="btn btn-sm btn-outline-warning">Escalate</button>
                <button name="action" value="pause" class="btn btn-sm btn-outline-dark">Pause this workflow</button>
                <select name="user_id" class="form-select form-select-sm w-auto"><option value="">Reassign to…</option>@foreach($users as $u)<option value="{{ $u->id }}">{{ $u->name }} ({{ $u->role }})</option>@endforeach</select>
                <button name="action" value="reassign" class="btn btn-sm btn-outline-primary">Reassign</button>
            </div>
        </form>
        @elseif($canDecide && $a->status === 'failed')
        <form method="POST" action="{{ route('approvals.decide', $a) }}" class="card-lp p-3 mb-3">@csrf<p class="small text-danger">{{ $a->execution_result }}</p><button name="action" value="retry" class="btn btn-sm btn-primary-lp">Retry</button></form>
        @else
        <div class="card-lp p-3 mb-3 small">Decided by {{ $a->decider?->name ?? '—' }} {{ $a->decided_at?->diffForHumans() }}. @if($a->execution_result)<div><strong>Result:</strong> {{ $a->execution_result }}</div>@endif</div>
        @endif
    </div>
    <div class="col-lg-4">
        <div class="card-lp p-3"><h6 class="fw-bold">History</h6>
            @foreach($a->events as $e)
                <div class="small border-bottom py-1"><strong>{{ str_replace('_',' ',$e->event) }}</strong> · {{ $e->user?->name ?? 'system' }} <span class="text-muted">{{ \Illuminate\Support\Carbon::parse($e->created_at)->diffForHumans() }}</span>
                    @if($e->event === 'notified')<div class="text-muted" style="font-size:.72rem">@foreach((array)($e->data['results'] ?? []) as $r){{ 'user #' . ($r['user'] ?? '?') }}: {{ collect($r)->except('user')->map(fn($v,$k)=>"$k=$v")->implode(', ') }}<br>@endforeach</div>@endif
                </div>
            @endforeach
        </div>
    </div>
</div>
@endsection
