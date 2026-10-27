@extends('layouts.app')
@section('title', 'Conversation')
@section('content')
@php $tz = config('leadpilot.timezone', 'Asia/Karachi'); $lead = $recipient->lead; @endphp
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <a href="{{ route('outbox.index') }}" class="text-decoration-none small"><i class="bi bi-arrow-left"></i> Sent &amp; Replies</a>
        <h3 class="fw-bold mb-1 mt-1">{{ $lead?->company?->name ?? $lead?->full_name ?? 'Conversation' }}</h3>
        <p class="text-muted small mb-0">{{ $lead?->email }} @if($lead?->company?->website) · <a href="{{ $lead->company->website }}" target="_blank" rel="noopener noreferrer">{{ $lead->company->website }}</a>@endif</p>
    </div>
    @if($lead)
        <div class="d-flex gap-2">
            <a href="{{ route('leads.show', $lead) }}" class="btn btn-sm btn-outline-secondary">Lead</a>
            <a href="{{ route('assistant.show', $lead) }}" class="btn btn-sm btn-primary-lp"><i class="bi bi-reply-fill me-1"></i>Answer</a>
        </div>
    @endif
</div>

@php
    $events = collect();
    foreach ($thread as $t) { $events->push(['at' => $t->sent_at, 'type' => 'sent', 'item' => $t]); }
    foreach ($thread as $t) { foreach ($t->replies()->get() as $rep) { $events->push(['at' => $rep->received_at, 'type' => 'reply', 'item' => $rep]); } }
    foreach ($otherReplies as $rep) { if (!$events->contains(fn ($e) => $e['type'] === 'reply' && $e['item']->id === $rep->id)) { $events->push(['at' => $rep->received_at, 'type' => 'reply', 'item' => $rep]); } }
    $events = $events->sortBy(fn ($e) => $e['at']?->timestamp ?? 0)->values();
@endphp

@foreach($events as $e)
    @if($e['type'] === 'sent')
        @php $m = $e['item']; @endphp
        <div class="card-lp p-3 mb-3 {{ $m->id === $recipient->id ? 'border-primary' : '' }}">
            <div class="d-flex justify-content-between small text-muted mb-2">
                <span><i class="bi bi-send-fill text-primary me-1"></i>We sent · {{ (int) $m->sequence_step <= 1 ? 'First e-mail' : 'Follow-up ' . ((int) $m->sequence_step - 1) }}</span>
                <span>{{ $m->sent_at?->timezone($tz)->format('M d, Y H:i') }}@if($m->bounced_at || $m->status === 'bounced') · <span class="badge bg-danger">Bounced</span>@endif</span>
            </div>
            <div class="fw-semibold mb-2">{{ $m->personalized_subject }}</div>
            <div style="white-space: pre-wrap;" class="small">{{ trim(strip_tags(str_ireplace(['<br>', '<br/>', '<br />', '</p>'], "\n", (string) $m->personalized_body))) }}</div>
        </div>
    @else
        @php $rep = $e['item']; @endphp
        <div class="card-lp p-3 mb-3 ms-md-5" style="border-left: 4px solid #198754;">
            <div class="d-flex justify-content-between small text-muted mb-2">
                <span><i class="bi bi-reply-fill text-success me-1"></i>{{ $rep->from_email }} replied · @include('outbox._reply_badge', ['reply' => $rep])</span>
                <span>{{ $rep->received_at?->timezone($tz)->format('M d, Y H:i') }}</span>
            </div>
            <div class="fw-semibold mb-2">{{ $rep->subject }}</div>
            <div style="white-space: pre-wrap;" class="small">{{ trim($rep->body_text) }}</div>
        </div>
    @endif
@endforeach
@endsection
