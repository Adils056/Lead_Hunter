@extends('layouts.app')
@section('title', 'Sales Assistant — ' . ($lead->company?->name ?? $lead->full_name))
@section('content')
<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
    <div>
        <nav><ol class="breadcrumb mb-1 small"><li class="breadcrumb-item"><a href="{{ route('leads.index') }}">Leads</a></li><li class="breadcrumb-item"><a href="{{ route('leads.show', $lead) }}">{{ $lead->full_name }}</a></li><li class="breadcrumb-item active">Sales Assistant</li></ol></nav>
        <h3 class="fw-bold mb-0">{{ $lead->company?->name ?? $lead->full_name }}</h3>
        <div class="small text-muted">Score {{ $lead->overall_score }}/100 ({{ $lead->score_breakdown['confidence'] ?? 'n/a' }} confidence) · contact: {{ str_replace('_', ' ', $lead->contact_status) }} · {{ $lead->email ?: 'no email' }} {{ $lead->phone ? '· ' . $lead->phone : '' }}</div>
    </div>
    <div class="d-flex gap-2">
        @if($lead->company && ($lead->company->website || $lead->company->domain))
        <form method="POST" action="{{ route('companies.audit', $lead->company) }}">@csrf<button class="btn btn-sm btn-outline-success"><i class="bi bi-clipboard2-pulse"></i> {{ $lead->company->website_audited_at ? 'Re-audit website' : 'Audit website' }}</button></form>
        @endif
        <form method="POST" action="{{ route('leads.rescore', $lead) }}">@csrf<button class="btn btn-sm btn-outline-primary"><i class="bi bi-arrow-repeat"></i> Re-score</button></form>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-7">
        {{-- 1. Offer --}}
        <div class="card-lp p-4 mb-4">
            <h6 class="fw-bold"><span class="badge bg-primary me-1">1</span> What to offer</h6>
            @if($offer['offer'])
                <div class="fs-5 fw-bold">{{ $offer['offer']['label'] }} <span class="small text-muted fw-normal">typical ${{ number_format($offer['offer']['typical_value_usd'][0]) }}–{{ number_format($offer['offer']['typical_value_usd'][1]) }} (your catalog estimate)</span></div>
                <p class="small mb-2">{{ $offer['why'] }}</p>
                @if($offer['alternatives'])<div class="small text-muted">Also relevant: {{ collect($offer['alternatives'])->pluck('label')->implode(', ') }}</div>@endif
            @else
                <p class="small text-muted mb-0">{{ $offer['why'] }}</p>
            @endif
        </div>

        {{-- 2. Email --}}
        <div class="card-lp p-4 mb-4">
            <h6 class="fw-bold"><span class="badge bg-primary me-1">2</span> First email (evidence-only)</h6>
            @if($email['ok'])
                @foreach($email['warnings'] as $w)<div class="alert alert-warning py-1 px-2 small mb-2">{{ $w }}</div>@endforeach
                <label class="small fw-semibold">Subject</label>
                <input class="form-control form-control-sm mb-2" value="{{ $email['draft']['subject'] }}" id="subj">
                <label class="small fw-semibold">Body <span class="text-muted fw-normal">({{ $email['draft']['word_count'] }} words · {{ $email['draft']['generator'] }} · limit {{ config('leadpilot.outreach.max_words_first_email') }})</span></label>
                <textarea class="form-control form-control-sm mb-2" rows="9" id="body">{{ $email['draft']['body'] }}</textarea>
                @if($aiAvailable)
                <form method="POST" action="{{ route('assistant.polish', $lead) }}" class="d-inline">@csrf<button class="btn btn-sm btn-outline-primary"><i class="bi bi-stars"></i> Polish with AI (facts-only)</button></form>
                @endif
                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="navigator.clipboard.writeText(document.getElementById('subj').value+'\n\n'+document.getElementById('body').value);this.innerText='Copied'">Copy</button>
                <div class="small text-muted mt-3"><strong>Facts used</strong> (each verifiable):
                    <ul class="ps-3 mb-0">@foreach($email['facts_used'] as $f)<li>{{ ucfirst($f['text']) }} <span class="text-muted">— {{ $f['source'] }}, {{ $f['observed'] }}</span></li>@endforeach</ul></div>
            @else
                <p class="small text-muted mb-0">{{ $email['reason'] }}</p>
            @endif
        </div>

        {{-- 3. Follow-up --}}
        <div class="card-lp p-4 mb-4">
            <h6 class="fw-bold"><span class="badge bg-primary me-1">3</span> Follow-up</h6>
            <p class="small mb-2">{{ $followUp['message'] }}</p>
            @if(!empty($followUp['draft']))<textarea class="form-control form-control-sm" rows="6">{{ $followUp['draft'] }}</textarea>@endif
            <div class="small text-muted mt-2">Cadence: touches at +{{ implode(', +', config('leadpilot.outreach.follow_up_days')) }} days, max {{ config('leadpilot.outreach.max_touches') }}; stops on any reply.</div>
        </div>

        {{-- 4. Reply classifier --}}
        <div class="card-lp p-4">
            <h6 class="fw-bold"><span class="badge bg-primary me-1">4</span> They replied? Paste it here</h6>
            <form method="POST" action="{{ route('assistant.classify', $lead) }}">@csrf
                <textarea name="reply" class="form-control form-control-sm mb-2" rows="4" placeholder="Paste the prospect's reply…">{{ $replyText }}</textarea>
                <button class="btn btn-sm btn-primary-lp">Classify & suggest response</button>
            </form>
            @if($classification)
                @php $cc = ['interested'=>'bg-success','not_interested'=>'bg-secondary','referral'=>'bg-info text-dark','objection'=>'bg-warning text-dark','out_of_office'=>'bg-light text-dark border','unsubscribe'=>'bg-danger','uncertain'=>'bg-dark']; @endphp
                <div class="mt-3 p-3 border rounded">
                    <span class="badge {{ $cc[$classification['category']] ?? 'bg-dark' }}">{{ str_replace('_', ' ', $classification['category']) }}{{ !empty($classification['subtype']) && $classification['subtype'] === 'meeting_request' ? ' · meeting request' : '' }}{{ !empty($classification['objection_type']) ? ' · ' . $classification['objection_type'] : '' }}</span>
                    <span class="small text-muted">{{ $classification['confidence'] }}% · {{ $classification['requires_human_review'] ? 'human review' : 'safe to automate' }}</span>
                    <p class="small mt-2 mb-2">{{ $classification['reasoning'] }}</p>
                    @if(!empty($classification['referred_email']))<p class="small">Referred to: <strong>{{ $classification['referred_email'] }}</strong></p>@endif
                    @if($classification['suggested_reply'])<textarea class="form-control form-control-sm" rows="5">{{ $classification['suggested_reply'] }}</textarea>@endif
                </div>
            @endif
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card-lp p-4 mb-4">
            <h6 class="fw-bold mb-3">Why this lead (score evidence)</h6>
            @include('leads._score_panel')
        </div>
        <div class="card-lp p-4 mb-4">
            <h6 class="fw-bold mb-2">All verified observations</h6>
            @forelse($facts as $f)
                <div class="small py-1 border-bottom">{{ ucfirst($f['text']) }}.<div class="text-muted" style="font-size:.72rem">{{ $f['source'] }} · {{ $f['observed'] }}</div></div>
            @empty <p class="small text-muted mb-0">None yet.</p> @endforelse
        </div>
        <div class="card-lp p-4">
            <h6 class="fw-bold mb-2">Discovery-call questions (BANT)</h6>
            @foreach($questions as $k => $qs)
                <div class="small fw-semibold text-uppercase text-muted mt-2">{{ $k }}</div>
                <ul class="small ps-3 mb-0">@foreach($qs as $q)<li>{{ $q }}</li>@endforeach</ul>
            @endforeach
        </div>
    </div>
</div>
@endsection
