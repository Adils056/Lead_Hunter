@extends('layouts.app')
@section('title', 'Notifications')
@section('content')
<div class="d-flex justify-content-between mb-3"><h3 class="fw-bold">Notifications</h3>
<form method="POST" action="{{ route('notifications.read-all') }}">@csrf<button class="btn btn-sm btn-light border">Mark all read</button></form></div>
<div class="card-lp p-0">
@forelse($items as $n)
    <a href="{{ route('notifications.read', $n->id) }}" class="d-block p-3 border-bottom text-decoration-none text-dark {{ $n->read_at ? '' : 'bg-warning-subtle' }}">
        <div class="d-flex justify-content-between"><strong>{{ $n->data['title'] ?? 'Notification' }}</strong><span class="small text-muted">{{ $n->created_at->diffForHumans() }}</span></div>
        <div class="small">{{ \Illuminate\Support\Str::limit($n->data['what'] ?? '', 200) }}</div>
        @if(!empty($n->data['recommended']))<div class="small text-muted">Recommended: {{ $n->data['recommended'] }}</div>@endif
    </a>
@empty <p class="p-4 text-muted mb-0">No notifications.</p> @endforelse
</div>
@endsection
