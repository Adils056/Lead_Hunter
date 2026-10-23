@php
    $bd = $lead->score_breakdown ?? [];
    $weights = $bd['weights'] ?? config('leadpilot.scoring.weights');
    $scores = $bd['scores'] ?? [];
    $reasons = $bd['factor_reasons'] ?? [];
    $labels = [
        'explicit_request' => 'Explicit project / RFP / enquiry',
        'software_need' => 'Active software need (hiring, stack change)',
        'website_gap' => 'Website gaps (audited evidence)',
        'growth_initiative' => 'Growth / activity signals',
        'icp_match' => 'Ideal Customer Profile match',
        'contact_role' => 'Decision-maker reachable',
        'service_fit' => 'Fit with a service we sell',
        'contact_verification' => 'Contact verification & source trust',
        'data_completeness' => 'Data completeness',
        'signal_freshness' => 'Evidence freshness',
    ];
    $conf = $bd['confidence'] ?? null;
@endphp
<div class="d-flex flex-wrap gap-2 mb-3 small">
    <span class="badge bg-secondary-subtle text-secondary-emphasis border" title="{{ $bd['score_note'] ?? '' }}"><i class="bi bi-info-circle me-1"></i>Heuristic score — not a conversion probability</span>
    @if($conf)
        <span class="badge {{ $conf === 'high' ? 'bg-success' : ($conf === 'medium' ? 'bg-warning text-dark' : 'bg-danger') }}">Confidence: {{ ucfirst($conf) }} ({{ $bd['confidence_score'] ?? '?' }}%)</span>
    @endif
    @if(!empty($bd['data_freshness']))
        <span class="badge bg-light text-dark border">Newest evidence: {{ $bd['data_freshness']['newest_evidence_date'] }} ({{ $bd['data_freshness']['age_days'] }}d)</span>
    @endif
    @if(!empty($bd['icp_profile']))
        <span class="badge bg-primary-subtle text-primary-emphasis border">ICP: {{ $bd['icp_profile']['name'] }}</span>
    @endif
</div>

<div class="d-flex flex-column gap-2 mb-3">
    @foreach($weights as $key => $w)
        @php $p = $scores[$key] ?? null; @endphp
        <div>
            <div class="d-flex justify-content-between small fw-semibold mb-1">
                <span>{{ $labels[$key] ?? ucwords(str_replace('_', ' ', $key)) }}</span>
                <span class="{{ $p === null ? 'text-muted fst-italic' : '' }}">{{ $p === null ? 'not evaluated' : $p . ' / ' . $w }}</span>
            </div>
            <div class="progress" style="height: 6px;">
                <div class="progress-bar bg-primary" style="width: {{ $p === null || !$w ? 0 : ($p / $w) * 100 }}%"></div>
            </div>
            @if(!empty($reasons[$key]))<div class="text-muted" style="font-size:.72rem">{{ $reasons[$key] }}</div>@endif
        </div>
    @endforeach
</div>

@if(!empty($bd['evidence']))
    <strong class="d-block small fw-bold mb-1"><i class="bi bi-link-45deg text-success"></i> Supporting evidence</strong>
    <ul class="small mb-3 ps-3">
        @foreach($bd['evidence'] as $e)
            @php $u = \App\Services\Audit\SafeFetcher::safeUrl($e['source_url'] ?? null); @endphp
            <li>{{ $e['title'] }} @if($u)<a href="{{ $u }}" target="_blank" rel="noopener noreferrer">source</a>@endif <span class="text-muted">({{ $e['observed_date'] ?? '' }})</span></li>
        @endforeach
    </ul>
@endif
@if(!empty($bd['missing_information']))
    <strong class="d-block small fw-bold mb-1"><i class="bi bi-question-circle text-warning"></i> Missing information</strong>
    <ul class="small mb-3 ps-3 text-muted">@foreach($bd['missing_information'] as $m)<li>{{ $m }}</li>@endforeach</ul>
@endif
@if(!empty($bd['disqualifications']))
    <div class="alert alert-warning small py-2 mb-2">@foreach($bd['disqualifications'] as $d)<div>{{ $d }}</div>@endforeach</div>
@endif
@if(!empty($bd['suggested_next_action']))
    <div class="p-2 rounded border bg-light small"><strong>Next action:</strong> {{ $bd['suggested_next_action'] }}
        @if(!empty($bd['recommended_offers'][0])) <br><strong>Offer:</strong> {{ $bd['recommended_offers'][0]['label'] }} @endif
    </div>
@endif
