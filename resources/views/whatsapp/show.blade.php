@extends('layouts.app')
@section('title', 'WhatsApp conversation')
@section('content')
@php
    $tz = config('leadpilot.timezone', 'Asia/Karachi');
    $lead = $message->lead;
    $company = $message->company ?? $lead?->company;
    $badge = [
        'reply' => ['Real reply', 'bg-success'], 'auto_reply' => ['Automatic answer', 'bg-light text-dark border'],
        'wrong_number' => ['Wrong number', 'bg-warning text-dark'], 'opt_out' => ['Asked to stop', 'bg-danger'],
    ];
@endphp
<style>
    .wa-thread { display: grid; gap: 12px; max-width: 760px; }
    .wa-row { display: flex; }
    .wa-row.out { justify-content: flex-end; }
    .wa-bubble { max-width: min(560px, 92%); border-radius: 12px; padding: 10px 14px; overflow-wrap: anywhere; }
    .wa-text { white-space: pre-line; }
    .wa-row.out .wa-bubble { background: #dcf8c6; color: #1b2a1f; border-bottom-right-radius: 4px; }
    .wa-row.in .wa-bubble { background: #fff; border: 1px solid #e3e7ea; border-bottom-left-radius: 4px; }
    .wa-row.in.muted .wa-bubble { background: #f5f6f7; color: #6b7780; }
    .wa-meta { font-size: 12px; color: #6b7780; margin-top: 6px; }
    .wa-file { display: inline-flex; gap: 8px; align-items: center; background: rgba(255,255,255,.7); border: 1px solid rgba(0,0,0,.08); border-radius: 8px; padding: 6px 10px; font-size: 13px; margin-top: 8px; text-decoration: none; color: inherit; }
</style>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
    <div>
        <a href="{{ route('whatsapp.index') }}" class="text-decoration-none small"><i class="bi bi-arrow-left"></i> WhatsApp</a>
        <h3 class="fw-bold mb-1 mt-1">{{ $company?->name ?? $lead?->full_name ?? 'Conversation' }}</h3>
        <p class="text-muted small mb-0">
            <i class="bi bi-whatsapp text-success"></i> +{{ $message->to_number }}
            @if($company?->city) · {{ $company->city }}, {{ $company->country }}@endif
            @if($company?->website) · <a href="{{ $company->website }}" target="_blank" rel="noopener noreferrer">{{ $company->website }}</a>@endif
        </p>
        <p class="text-muted small mb-0">Number from: {{ ['website_whatsapp_link' => 'a WhatsApp link on their website', 'mobile_number' => 'the mobile number they list', 'given_in_reply' => 'their own reply'][$message->number_source] ?? ($message->number_source ?: 'unknown') }}</p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        @if($lead)
            <a href="{{ route('leads.show', $lead) }}" class="btn btn-sm btn-outline-secondary">Lead</a>
            @if($company)<a href="{{ route('companies.report', $company) }}" class="btn btn-sm btn-outline-secondary">Website report</a>@endif
        @endif
        <a href="https://wa.me/{{ $message->to_number }}" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-success"><i class="bi bi-whatsapp me-1"></i>Answer in WhatsApp</a>
    </div>
</div>

<div class="wa-thread">
@foreach($timeline as $e)
    @php $m = $e['m']; @endphp
    @if($e['out'])
        <div class="wa-row out">
            <div class="wa-bubble"><div class="wa-text">{{ $m->body }}</div>
                @if($m->file_url)<div><a class="wa-file" href="{{ $m->file_url }}" target="_blank" rel="noopener noreferrer"><i class="bi bi-file-earmark-pdf-fill text-danger"></i> Website &amp; competitor report (PDF)</a></div>@endif
                <div class="wa-meta">
                    LeadPilot → +{{ $m->to_number }} ·
                    @if($m->status === 'sent'){{ $m->sent_at?->timezone($tz)->format('M d, H:i') }} · <i class="bi bi-check2-all text-success"></i> sent
                    @elseif($m->status === 'failed')<span class="text-danger">not delivered</span> ({{ \Illuminate\Support\Str::limit((string) $m->provider_response, 120) }})
                    @else{{ str_replace('_', ' ', $m->status) }}@endif
                </div>
            </div>
        </div>
    @else
        <div class="wa-row in {{ $m->action === 'reply' ? '' : 'muted' }}">
            <div class="wa-bubble"><div class="wa-text">{{ $m->body ?: '(no text: a photo, voice note or file)' }}</div>
                <div class="wa-meta d-flex flex-wrap align-items-center gap-2">
                    <span>+{{ $m->from_number }} · {{ $m->created_at->timezone($tz)->format('M d, H:i') }}</span>
                    <span class="badge {{ $badge[$m->action][1] ?? 'bg-light text-dark border' }}">{{ $badge[$m->action][0] ?? $m->action }}</span>
                    @if(in_array($m->action, ['reply', 'auto_reply', 'wrong_number'], true))
                        <form method="POST" action="{{ route('whatsapp.label', $m) }}" class="d-inline-flex gap-1">
                            @csrf
                            @if($m->action !== 'reply')
                                <button name="action" value="reply" class="btn btn-link btn-sm p-0 text-success" title="The filter got this wrong: a person wrote this">Mark as real reply</button>
                            @else
                                <button name="action" value="auto_reply" class="btn btn-link btn-sm p-0 text-muted" title="This was sent by a machine, not a person">Mark as automatic</button>
                                <span class="text-muted">·</span>
                                <button name="action" value="wrong_number" class="btn btn-link btn-sm p-0 text-muted" title="A person, but not the business we wrote to">Wrong number</button>
                            @endif
                        </form>
                    @endif
                </div>
            </div>
        </div>
    @endif
@endforeach
@if($timeline->where('out', false)->isEmpty())
    <p class="text-muted small mb-0">No answer yet. When they write back, the message appears here and the salesperson is alerted.</p>
@endif
</div>

<p class="small text-muted mt-4 mb-0" style="max-width: 760px;">
    Grey messages were sent by a machine (WhatsApp Business greetings, away messages, adverts) or by someone at a wrong number. They do not count as replies, stop no e-mails and alert no one. If LeadPilot read a message wrongly, use the link under it to correct it.
</p>
@endsection
