@extends('layouts.app')
@section('title', 'WhatsApp')
@section('content')
@php
    $tz = config('leadpilot.timezone', 'Asia/Karachi');
    $badge = [
        'reply' => ['Replied', 'bg-success'], 'auto_reply' => ['Automatic', 'bg-light text-dark border'],
        'wrong_number' => ['Wrong number', 'bg-warning text-dark'], 'opt_out' => ['Asked to stop', 'bg-danger'],
    ];
@endphp
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h3 class="fw-bold mb-1"><i class="bi bi-whatsapp text-success me-1"></i>WhatsApp</h3>
        <p class="text-muted small mb-0">Every WhatsApp LeadPilot sent with the website report, and what each business answered.</p>
    </div>
    <form class="d-flex gap-2 flex-nowrap" method="GET" style="min-width: min(100%, 320px);">
        <input type="hidden" name="tab" value="{{ $tab }}">
        <input type="search" name="q" id="wa-search" value="{{ $q }}" class="form-control form-control-sm" placeholder="Search business or number" aria-label="Search business or number">
        <button class="btn btn-sm btn-dark" aria-label="Search"><i class="bi bi-search"></i></button>
    </form>
</div>

<div class="row g-3 mb-3">
    @foreach([
        ['Sent (all time)', $stats['sent'], 'bi-send-fill', 'text-primary'],
        ['Sent today', $stats['today'] . ' / ' . $stats['cap'], 'bi-calendar-check', 'text-secondary'],
        ['Real replies', $stats['replies'], 'bi-reply-fill', 'text-success'],
        ['Reply rate', $stats['rate'] . '%', 'bi-graph-up-arrow', 'text-success'],
        ['Automatic answers', $stats['automatic'], 'bi-robot', 'text-secondary'],
        ['Asked to stop', $stats['stopped'], 'bi-slash-circle', $stats['stopped'] ? 'text-danger' : 'text-secondary'],
    ] as [$label, $value, $icon, $color])
        <div class="col-6 col-md-4 col-xl-2">
            <div class="card-lp p-3 h-100">
                <div class="text-muted small"><i class="bi {{ $icon }} {{ $color }} me-1"></i>{{ $label }}</div>
                <div class="fs-4 fw-bold">{{ $value }}</div>
            </div>
        </div>
    @endforeach
</div>

<ul class="nav nav-tabs mb-0 flex-nowrap overflow-auto">
    @foreach(\App\Http\Controllers\WhatsappController::TABS as $key => $label)
        <li class="nav-item"><a class="nav-link text-nowrap {{ $tab === $key ? 'active fw-semibold' : '' }}" href="{{ route('whatsapp.index', ['tab' => $key, 'q' => $q ?: null]) }}">{{ $label }}
            @if($key === 'replies' && $stats['replies'])<span class="badge bg-success ms-1">{{ $stats['replies'] }}</span>@endif
            @if($key === 'failed' && $stats['failed'])<span class="badge bg-secondary ms-1">{{ $stats['failed'] }}</span>@endif
        </a></li>
    @endforeach
</ul>

<div class="card-lp p-0 border-top-0">
<div class="table-responsive">
@if($tab === 'other')
    <table class="table table-lp small align-middle mb-0">
        <thead><tr class="text-muted"><th class="ps-3">Received</th><th>From</th><th style="min-width: 320px;">Message</th></tr></thead>
        <tbody>
        @forelse($items as $m)
            <tr>
                <td class="ps-3 text-nowrap">{{ $m->created_at->timezone($tz)->format('M d, H:i') }}</td>
                <td class="text-nowrap">+{{ $m->from_number }}</td>
                <td>{{ \Illuminate\Support\Str::limit($m->body, 160) }}</td>
            </tr>
        @empty
            <tr><td colspan="3" class="text-center text-muted py-5">No messages from other numbers.</td></tr>
        @endforelse
        </tbody>
    </table>
    <p class="small text-muted px-3 py-2 mb-0 border-top">Messages from numbers LeadPilot never wrote to, such as your own test messages. They are kept here and never acted on.</p>
@else
    <table class="table table-lp small align-middle mb-0">
        <thead><tr class="text-muted"><th class="ps-3">Sent</th><th>Business</th><th>Number</th><th>Status</th><th style="min-width: 300px;">Their answer</th><th></th></tr></thead>
        <tbody>
        @forelse($items as $m)
            @php
                $real = $m->inbound->firstWhere('action', 'reply') ?? $m->inbound->firstWhere('action', 'opt_out') ?? $m->inbound->firstWhere('action', 'wrong_number');
                $latest = $real ?? $m->inbound->first();
                $name = $m->company?->name ?? $m->lead?->company?->name ?? $m->lead?->full_name ?? '—';
            @endphp
            <tr>
                <td class="ps-3 text-nowrap">{{ ($m->sent_at ?? $m->created_at)->timezone($tz)->format('M d, H:i') }}</td>
                <td>
                    <a href="{{ route('whatsapp.show', $m) }}" class="fw-semibold text-decoration-none">{{ $name }}</a>
                    <div class="text-muted">{{ $m->company?->city }}@if($m->number_source === 'given_in_reply') · <span title="A number the business gave us in a reply">number they gave us</span>@endif</div>
                </td>
                <td class="text-nowrap">+{{ $m->to_number }}</td>
                <td class="text-nowrap">
                    @if($m->status === 'sent')<span class="badge bg-light text-dark border"><i class="bi bi-check2-all text-success"></i> Sent</span>
                    @elseif($m->status === 'failed')<span class="badge bg-secondary" title="{{ $m->provider_response }}">Not delivered</span>
                    @elseif($m->status === 'pending_approval')<span class="badge bg-info text-dark">Waiting for approval</span>
                    @else<span class="badge bg-light text-dark border">{{ str_replace('_', ' ', $m->status) }}</span>@endif
                </td>
                <td>
                    @if($latest)
                        <span class="badge {{ $badge[$latest->action][1] ?? 'bg-light text-dark border' }}">{{ $badge[$latest->action][0] ?? $latest->action }}</span>
                        <span class="text-muted ms-1">{{ $latest->created_at->timezone($tz)->format('M d, H:i') }}</span>
                        <div class="{{ $latest->action === 'reply' ? 'fw-semibold' : 'text-muted' }}">{{ \Illuminate\Support\Str::limit($latest->body, 110) }}</div>
                        @if($m->inbound->count() > 1)<div class="text-muted">+{{ $m->inbound->count() - 1 }} more</div>@endif
                    @elseif($m->status === 'sent')<span class="text-muted">No answer yet</span>
                    @else<span class="text-muted">—</span>@endif
                </td>
                <td class="text-end pe-3"><a href="{{ route('whatsapp.show', $m) }}" class="btn btn-sm btn-outline-secondary text-nowrap">Open</a></td>
            </tr>
        @empty
            <tr><td colspan="6" class="text-center text-muted py-5">No WhatsApp messages here yet.</td></tr>
        @endforelse
        </tbody>
    </table>
@endif
</div>
</div>
<div class="mt-3">{{ $items->links() }}</div>
@endsection
