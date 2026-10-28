@extends('layouts.app')
@section('title', 'Performance & learning')
@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <div><h3 class="fw-bold mb-1">Performance &amp; learning</h3><p class="text-muted small mb-0">Real outcomes only, last {{ $r['days'] }} days. Autopilot uses these numbers to decide where to search next.</p></div>
    <form><select name="days" class="form-select form-select-sm" onchange="this.form.submit()">@foreach([30,90,180,365] as $d)<option value="{{ $d }}" @selected($r['days']==$d)>{{ $d }} days</option>@endforeach</select></form>
</div>
<div class="row g-3 mb-4">
    @foreach($r['totals'] as $k => $v)<div class="col-6 col-md-2"><div class="card-lp p-3"><div class="small text-muted">{{ ucwords(str_replace('_',' ',$k)) }}</div><div class="fs-4 fw-bold">{{ $v }}</div></div></div>@endforeach
</div>
<div class="card-lp p-3 mb-4">
    <h6 class="fw-bold mb-1"><i class="bi bi-trophy-fill text-warning me-1"></i>Winning segments</h6>
    <p class="small text-muted mb-2">Of the prospects e-mailed in this period: who replied, and who replied with interest. Segments that reply get more searches (and a "lookalike" profile); segments that stay silent for two weeks get fewer. Fewer than {{ $r['min_sample'] }} e-mails is too early to judge.</p>
    <table class="table table-sm small align-middle mb-0">
        <thead><tr class="text-muted"><th>Segment</th><th>E-mailed</th><th>Replied</th><th>Interested</th><th style="min-width: 160px;">Reply rate</th><th>Interest rate</th></tr></thead>
        <tbody>
        @forelse($r['winning'] as $w)
            <tr>
                <td>{{ $w['key'] }} @unless($w['enough_data'])<span class="badge bg-light text-muted border ms-1">too early</span>@endunless</td>
                <td>{{ $w['contacted'] }}</td><td>{{ $w['replied'] }}</td><td>{{ $w['positive'] }}</td>
                <td>
                    <div class="d-flex align-items-center gap-2">
                        <div class="progress flex-grow-1" style="height: 6px;"><div class="progress-bar {{ $w['positive'] ? 'bg-success' : 'bg-secondary' }}" style="width: {{ min(100, $w['reply_rate'] * 4) }}%"></div></div>
                        <span>{{ $w['reply_rate'] }}%</span>
                    </div>
                </td>
                <td class="{{ $w['positive'] ? 'fw-bold text-success' : '' }}">{{ $w['positive_rate'] }}%</td>
            </tr>
        @empty <tr><td colspan="6" class="text-muted">No e-mails sent in this period yet.</td></tr> @endforelse
        </tbody>
    </table>
</div>
<div class="card-lp p-3 mb-4">
    <h6 class="fw-bold">Cost (actual vs estimated)</h6>
    <div class="small mb-2">Actual: <strong>${{ $r['costs']['actual_usd'] }}</strong> · Estimated: <strong>${{ $r['costs']['estimated_usd'] }}</strong> <span class="text-muted">(estimates come from list prices; actual = provider-reported)</span></div>
    <div class="d-flex flex-wrap gap-2 small">
        @foreach(['per_unique_lead','per_qualified_lead','per_verified_contact','per_positive_reply','per_meeting','per_sql','per_won_client'] as $k)
            <span class="badge bg-light text-dark border p-2">{{ ucwords(str_replace(['per_','_'],['cost per ',' '],$k)) }}: {{ $r['costs'][$k] !== null ? '$'.$r['costs'][$k] : 'n/a' }}</span>
        @endforeach
    </div>
</div>
@foreach(['source' => 'By source', 'icp' => 'By ICP', 'industry' => 'By industry', 'offer' => 'By offer'] as $k => $t)
<div class="card-lp p-3 mb-3">
    <h6 class="fw-bold">{{ $t }}</h6>
    <table class="table table-sm small mb-0"><thead><tr class="text-muted"><th>{{ ucfirst($k) }}</th><th>Leads</th><th>Qualified</th><th>Verified contact</th><th>Contacted</th><th>Positive replies</th><th>Meetings</th><th>Won</th></tr></thead><tbody>
    @forelse(array_slice($r['by'][$k], 0, 12) as $row)
        <tr><td>{{ $row['key'] }}</td><td>{{ $row['leads'] }}</td><td>{{ $row['qualified'] }}</td><td>{{ $row['verified_contact'] }}</td><td>{{ $row['contacted'] }}</td><td>{{ $row['positive_replies'] }}</td><td>{{ $row['meetings'] }}</td><td>{{ $row['won'] }}</td></tr>
    @empty <tr><td colspan="8" class="text-muted">No data yet.</td></tr> @endforelse
    </tbody></table>
</div>
@endforeach
<div class="card-lp p-3"><h6 class="fw-bold">Sequence steps</h6>
    <table class="table table-sm small mb-0"><thead><tr class="text-muted"><th>Step</th><th>Sent</th><th>Got a reply after</th></tr></thead><tbody>
    @forelse($r['by_step'] as $s)<tr><td>{{ $s['step'] }}</td><td>{{ $s['sent'] }}</td><td>{{ $s['replied_after'] }}</td></tr>@empty <tr><td colspan="3" class="text-muted">No emails sent yet.</td></tr>@endforelse
    </tbody></table></div>
@endsection
