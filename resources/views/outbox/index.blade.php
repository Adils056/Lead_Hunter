@extends('layouts.app')
@section('title', 'Sent & Replies')
@section('content')
@php $tz = config('leadpilot.timezone', 'Asia/Karachi'); @endphp
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div><h3 class="fw-bold mb-1">Sent &amp; Replies</h3><p class="text-muted small mb-0">Every e-mail that went out, who it went to, and what they answered.</p></div>
    <form class="d-flex gap-2 flex-nowrap" method="GET" style="min-width: min(100%, 320px);">
        <input type="hidden" name="tab" value="{{ $tab }}">
        <input type="search" name="q" value="{{ $q }}" class="form-control form-control-sm" placeholder="Search name, e-mail, company, subject">
        <button class="btn btn-sm btn-dark"><i class="bi bi-search"></i></button>
    </form>
</div>

<div class="row g-3 mb-3">
    @foreach([
        ['Sent (all time)', $stats['sent'], 'bi-send-fill', 'text-primary'],
        ['Sent today', $stats['today'], 'bi-calendar-check', 'text-secondary'],
        ['Got a reply', $stats['replied'], 'bi-reply-fill', 'text-success'],
        ['Reply rate', $stats['rate'] . '%', 'bi-graph-up-arrow', 'text-success'],
        ['Bounced', $stats['bounced'], 'bi-exclamation-octagon', $stats['bounced'] ? 'text-danger' : 'text-secondary'],
    ] as [$label, $value, $icon, $color])
        <div class="col-6 col-md">
            <div class="card-lp p-3 h-100">
                <div class="text-muted small"><i class="bi {{ $icon }} {{ $color }} me-1"></i>{{ $label }}</div>
                <div class="fs-4 fw-bold">{{ $value }}</div>
            </div>
        </div>
    @endforeach
</div>

<ul class="nav nav-tabs mb-0">
    <li class="nav-item"><a class="nav-link {{ $tab === 'sent' ? 'active fw-semibold' : '' }}" href="{{ route('outbox.index', ['tab' => 'sent', 'q' => $q ?: null]) }}"><i class="bi bi-send me-1"></i>Sent e-mails</a></li>
    <li class="nav-item"><a class="nav-link {{ $tab === 'replies' ? 'active fw-semibold' : '' }}" href="{{ route('outbox.index', ['tab' => 'replies', 'q' => $q ?: null]) }}"><i class="bi bi-reply me-1"></i>Replies</a></li>
</ul>

<div class="card-lp p-0 border-top-0">
@if($tab === 'sent')
    <table class="table table-lp small align-middle mb-0">
        <thead><tr class="text-muted"><th class="ps-3">Sent</th><th>To</th><th style="min-width: 220px;">Subject</th><th>Step</th><th>Status</th><th style="min-width: 260px;">Reply</th></tr></thead>
        <tbody>
        @forelse($items as $r)
            @php $reply = $r->replies->first(); $bounced = $r->bounced_at || $r->status === 'bounced'; @endphp
            <tr>
                <td class="ps-3 text-nowrap">{{ $r->sent_at?->timezone($tz)->format('M d, H:i') }}</td>
                <td>
                    <div class="fw-semibold">{{ $r->lead?->company?->name ?? $r->lead?->full_name ?? '—' }}</div>
                    <div class="text-muted">{{ $r->lead?->email }}</div>
                </td>
                <td><a href="{{ route('outbox.show', $r) }}" class="text-decoration-none fw-semibold">{{ \Illuminate\Support\Str::limit($r->personalized_subject ?: '(no subject)', 70) }}</a></td>
                <td>{{ (int) $r->sequence_step <= 1 ? 'First' : 'Follow-up ' . ((int) $r->sequence_step - 1) }}</td>
                <td>
                    @if($bounced)<span class="badge bg-danger">Bounced</span>
                    @elseif($reply)<span class="badge bg-success">Replied</span>
                    @else<span class="badge bg-light text-dark border">Sent</span>@endif
                </td>
                <td>
                    @if($reply)
                        @include('outbox._reply_badge', ['reply' => $reply])
                        <div class="text-muted">{{ \Illuminate\Support\Str::limit(trim($reply->body_text), 80) }}</div>
                    @else<span class="text-muted">—</span>@endif
                </td>
            </tr>
        @empty
            <tr><td colspan="6" class="text-center text-muted py-5">No e-mails sent yet.</td></tr>
        @endforelse
        </tbody>
    </table>
@else
    <table class="table table-lp small align-middle mb-0">
        <thead><tr class="text-muted"><th class="ps-3">Received</th><th>From</th><th>Type</th><th style="min-width: 280px;">Message</th><th></th></tr></thead>
        <tbody>
        @forelse($items as $reply)
            <tr>
                <td class="ps-3 text-nowrap">{{ $reply->received_at?->timezone($tz)->format('M d, H:i') }}</td>
                <td>
                    <div class="fw-semibold">{{ $reply->lead?->company?->name ?? $reply->lead?->full_name ?? '—' }}</div>
                    <div class="text-muted">{{ $reply->from_email }}</div>
                </td>
                <td>@include('outbox._reply_badge', ['reply' => $reply])</td>
                <td>
                    <div class="fw-semibold">{{ \Illuminate\Support\Str::limit($reply->subject, 70) }}</div>
                    <div class="text-muted">{{ \Illuminate\Support\Str::limit(trim($reply->body_text), 140) }}</div>
                </td>
                <td class="text-nowrap pe-3">
                    @if($reply->recipient)<a href="{{ route('outbox.show', $reply->recipient) }}" class="btn btn-sm btn-outline-secondary">Conversation</a>@endif
                    @if($reply->lead)<a href="{{ route('assistant.show', $reply->lead) }}" class="btn btn-sm btn-primary-lp">Answer</a>@endif
                </td>
            </tr>
        @empty
            <tr><td colspan="5" class="text-center text-muted py-5">No replies yet.</td></tr>
        @endforelse
        </tbody>
    </table>
@endif
</div>
<div class="mt-3">{{ $items->links() }}</div>
@endsection
