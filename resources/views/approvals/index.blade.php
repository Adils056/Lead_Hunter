@extends('layouts.app')
@section('title', 'Approvals')
@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <div><h3 class="fw-bold mb-1">Approvals</h3><p class="text-muted small mb-0">Exceptions that need a human. Each workflow stays paused until someone decides.</p></div>
    <div class="btn-group btn-group-sm">@foreach(['open'=>'Open','executed'=>'Executed','rejected'=>'Rejected','failed'=>'Failed','all'=>'All'] as $k=>$l)<a href="?status={{ $k }}" class="btn {{ $status===$k ? 'btn-dark' : 'btn-outline-secondary' }}">{{ $l }}</a>@endforeach</div>
</div>
<div class="card-lp p-0"><table class="table table-lp small align-middle mb-0">
<thead><tr class="text-muted"><th class="ps-3">Priority</th><th>Request</th><th>Type</th><th>Assigned</th><th>Due</th><th>Status</th></tr></thead><tbody>
@forelse($items as $a)
    <tr><td class="ps-3"><span class="badge {{ in_array($a->priority, ['critical','high']) ? 'bg-danger' : 'bg-secondary' }}">{{ $a->priority }}</span></td>
        <td><a href="{{ route('approvals.show', $a) }}" class="fw-semibold text-decoration-none">{{ $a->title }}</a><div class="text-muted">{{ \Illuminate\Support\Str::limit($a->summary, 110) }}</div></td>
        <td>{{ str_replace('_', ' ', $a->type) }}</td><td>{{ $a->assignee?->name ?? $a->assigned_role }}@if($a->escalation_level)<span class="badge bg-warning text-dark ms-1">esc {{ $a->escalation_level }}</span>@endif</td>
        <td class="{{ $a->isOpen() && $a->due_at?->isPast() ? 'text-danger fw-bold' : '' }}">{{ $a->due_at?->timezone(config('leadpilot.timezone'))->format('M d H:i') }}</td>
        <td>{{ str_replace('_', ' ', $a->status) }}</td></tr>
@empty <tr><td colspan="6" class="text-center text-muted py-5">Nothing here.</td></tr> @endforelse
</tbody></table></div>
<div class="mt-3">{{ $items->links() }}</div>
@endsection
