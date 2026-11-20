@extends('layouts.app')
@section('title', 'Auto-replies')
@section('content')
@php
    $tz = config('leadpilot.timezone', 'Asia/Karachi');
    $badge = [
        'sent' => ['Answered', 'bg-success'],
        'held' => ['Left to a person', 'bg-warning text-dark'],
        'failed' => ['Not sent yet', 'bg-danger'],
        'skipped' => ['No answer needed', 'bg-light text-dark border'],
    ];
@endphp
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h3 class="fw-bold mb-1"><i class="bi bi-robot text-primary me-1"></i>Auto-replies</h3>
        <p class="text-muted small mb-0">Every e-mail and WhatsApp reply from a prospect, and what LeadPilot did with it: answered it, left it to a person, or decided no answer was needed.</p>
    </div>
    <div class="d-flex align-items-center gap-2">
        @if($stats['on'])
            <span class="badge bg-success py-2 px-3"><i class="bi bi-check-circle me-1"></i>Automatic answers are on</span>
        @else
            <span class="badge bg-secondary py-2 px-3"><i class="bi bi-pause-circle me-1"></i>Automatic answers are off</span>
        @endif
        @if(auth()->user()->isAdmin())
            <a href="{{ route('autopilot.policy') }}" class="btn btn-sm btn-outline-secondary">Change</a>
        @endif
    </div>
</div>

<div class="row g-3 mb-3">
    @foreach([
        ['Answered (7 days)', $stats['sent'], 'bi-send-check-fill', 'text-success'],
        ['Left to a person (7 days)', $stats['held'], 'bi-person-fill-exclamation', $stats['held'] ? 'text-warning' : 'text-secondary'],
        ['Still waiting for you', $stats['waiting_people'], 'bi-hourglass-split', $stats['waiting_people'] ? 'text-danger' : 'text-secondary'],
        ['Not sent yet (7 days)', $stats['failed'], 'bi-arrow-repeat', $stats['failed'] ? 'text-danger' : 'text-secondary'],
        ['No answer needed (7 days)', $stats['skipped'], 'bi-dash-circle', 'text-secondary'],
    ] as [$label, $value, $icon, $color])
        <div class="col-6 col-md-4 col-xl">
            <div class="card-lp p-3 h-100">
                <div class="text-muted small"><i class="bi {{ $icon }} {{ $color }} me-1"></i>{{ $label }}</div>
                <div class="fs-4 fw-bold">{{ $value }}</div>
            </div>
        </div>
    @endforeach
</div>

<ul class="nav nav-tabs mb-0 flex-nowrap overflow-auto">
    @foreach(\App\Http\Controllers\AutoReplyController::TABS as $key => $label)
        <li class="nav-item"><a class="nav-link text-nowrap {{ $tab === $key ? 'active fw-semibold' : '' }}" href="{{ route('auto-replies.index', ['tab' => $key]) }}">{{ $label }}</a></li>
    @endforeach
</ul>

<div class="card-lp p-0 border-top-0">
<div class="table-responsive">
    <table class="table table-lp small align-middle mb-0">
        <thead><tr class="text-muted"><th class="ps-3">When</th><th style="min-width: 170px;">Business</th><th style="min-width: 240px;">They wrote</th><th style="min-width: 200px;">What LeadPilot did</th><th style="min-width: 280px;">Our answer</th></tr></thead>
        <tbody>
        @forelse($items as $a)
            @php
                $src = $a->channel === 'email' ? $emails->get($a->source_id) : $wa->get($a->source_id);
                $said = $a->channel === 'email' ? $src?->body_text : $src?->body;
                $name = $a->lead?->company?->name ?? $a->lead?->full_name ?? '—';
                $link = $a->channel === 'whatsapp' && $src?->whatsapp_message_id ? route('whatsapp.show', $src->whatsapp_message_id) : ($a->lead ? route('leads.show', $a->lead) : null);
            @endphp
            <tr>
                <td class="ps-3 text-nowrap">{{ $a->updated_at->timezone($tz)->format('M d, H:i') }}</td>
                <td>
                    @if($link)<a href="{{ $link }}" class="fw-semibold text-decoration-none">{{ $name }}</a>@else<span class="fw-semibold">{{ $name }}</span>@endif
                    <div class="text-muted">
                        @if($a->channel === 'whatsapp')<i class="bi bi-whatsapp text-success"></i> WhatsApp @else<i class="bi bi-envelope"></i> E-mail @endif
                    </div>
                </td>
                <td class="text-muted">{{ \Illuminate\Support\Str::limit(trim(preg_replace('/\s+/', ' ', (string) $said)), 160) ?: '—' }}</td>
                <td>
                    <span class="badge {{ $badge[$a->status][1] ?? 'bg-light text-dark border' }}">{{ $badge[$a->status][0] ?? $a->status }}</span>
                    @if($a->attempts > 1)<span class="text-muted ms-1">{{ $a->attempts }} tries</span>@endif
                    @if($a->status === 'sent')<div class="text-muted">written by {{ $a->writer === 'template' ? 'the safe fixed answer' : ($a->writer ?: 'AI') }}</div>@endif
                    @if($a->reason)<div class="text-muted">{{ \Illuminate\Support\Str::limit(preg_replace('/^left to a person: /', '', $a->reason), 140) }}</div>@endif
                </td>
                <td>
                    @if($a->body)
                        <details>
                            <summary class="{{ $a->status === 'sent' ? '' : 'text-muted' }}">{{ \Illuminate\Support\Str::limit(trim(preg_replace('/\s+/', ' ', $a->body)), 90) }}</summary>
                            <div class="mt-2 p-2 rounded bg-light" style="white-space: pre-line;">{{ $a->body }}</div>
                        </details>
                    @elseif($a->status === 'held')
                        <span class="text-muted">Answer it yourself: a task is on your list.</span>
                    @else
                        <span class="text-muted">—</span>
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="5" class="text-center text-muted py-5">Nothing here yet. When a prospect replies, it shows up here within minutes.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
</div>
<div class="mt-3">{{ $items->links() }}</div>
@endsection
