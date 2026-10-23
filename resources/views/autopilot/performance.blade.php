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
