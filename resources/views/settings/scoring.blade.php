@extends('layouts.app')
@section('title', 'Lead Scoring Settings')
@section('content')
@php
    $labels = ['explicit_request' => 'Explicit project / RFP / enquiry', 'software_need' => 'Active software need', 'website_gap' => 'Website gaps (audit)', 'growth_initiative' => 'Growth / activity', 'icp_match' => 'ICP match', 'contact_role' => 'Decision-maker reachable', 'service_fit' => 'Service fit', 'contact_verification' => 'Contact verification', 'data_completeness' => 'Data completeness', 'signal_freshness' => 'Evidence freshness'];
    $w = old('weights', $effective['weights']); $t = old('thresholds', $effective['thresholds']);
@endphp
<div class="mb-4">
    <h3 class="fw-bold mb-1">Lead Scoring</h3>
    <p class="text-muted small mb-0">Weights are points out of 100. The score is a <strong>heuristic ranking</strong>; the calibration panel shows whether it actually predicts replies and deals.</p>
</div>
<div class="row g-4">
    <div class="col-lg-6">
        <form method="POST" action="{{ route('settings.scoring.update') }}" class="card-lp p-4">
            @csrf @method('PUT')
            <h6 class="fw-bold">Factor weights <span class="small text-muted fw-normal">(must total 100 — now <span id="tot">{{ array_sum($w) }}</span>)</span></h6>
            @foreach($effective['weights'] as $k => $_)
                <div class="d-flex align-items-center gap-2 mb-2">
                    <label class="small flex-grow-1">{{ $labels[$k] ?? $k }} <span class="text-muted">(default {{ $defaults['weights'][$k] }})</span></label>
                    <input type="number" min="0" max="100" name="weights[{{ $k }}]" value="{{ $w[$k] }}" class="form-control form-control-sm w-25 wt">
                </div>
            @endforeach
            <h6 class="fw-bold mt-4">Thresholds</h6>
            <div class="row g-2">
                @foreach(['high' => 'High priority ≥', 'qualified' => 'Qualified ≥', 'nurture' => 'Nurture ≥'] as $k => $l)
                    <div class="col-4"><label class="small">{{ $l }}</label><input type="number" min="0" max="100" name="thresholds[{{ $k }}]" value="{{ $t[$k] }}" class="form-control form-control-sm"></div>
                @endforeach
            </div>
            <div class="form-check mt-3"><input class="form-check-input" type="checkbox" name="rescore" value="1" id="rs" checked><label class="form-check-label small" for="rs">Re-score all leads after saving</label></div>
            <button class="btn btn-primary-lp mt-3">Save</button>
        </form>
    </div>
    <div class="col-lg-6">
        <div class="card-lp p-4">
            <h6 class="fw-bold">Does the score predict outcomes?</h6>
            <div class="alert {{ $calibration['sufficient'] ? (($calibration['auc'] ?? 0) >= 0.7 ? 'alert-success' : 'alert-warning') : 'alert-secondary' }} small">{{ $calibration['verdict'] }}</div>
            <table class="table table-sm small">
                <thead><tr class="text-muted"><th>Score band</th><th>Resolved leads</th><th>Positive</th><th>Positive rate</th></tr></thead>
                <tbody>@foreach($calibration['bands'] as $b)<tr><td>{{ $b['band'] }}</td><td>{{ $b['n'] }}</td><td>{{ $b['positives'] }}</td><td>{{ $b['rate'] !== null ? $b['rate'] . '%' : '—' }}</td></tr>@endforeach</tbody>
            </table>
            <div class="small text-muted">AUC {{ $calibration['auc'] ?? '—' }} (0.5 = random, 1 = perfect) · Brier {{ $calibration['brier'] ?? '—' }} · n={{ $calibration['n'] }}.<br>
                Positive = interested/meeting/proposal/won or a positive reply. Negative = lost/not a fit or no positive outcome 21+ days after contact. Uses the score recorded before first contact.</div>
        </div>
    </div>
</div>
<script>document.querySelectorAll('.wt').forEach(i=>i.addEventListener('input',()=>{let s=0;document.querySelectorAll('.wt').forEach(x=>s+=+x.value||0);const t=document.getElementById('tot');t.innerText=s;t.className=s===100?'text-success':'text-danger';}));</script>
@endsection
