@extends('layouts.app')
@section('title', 'Setup & health')
@section('content')
@php $c = collect($checks); @endphp
<h3 class="fw-bold mb-1">Setup &amp; health</h3>
<p class="text-muted small">Live checks of this installation. Only items marked <strong>FAIL</strong> or <strong>WARN</strong> need you. CLI: <code>php artisan leadpilot:doctor</code> · real-data test: <code>php artisan leadpilot:validate-live</code></p>
<form method="POST" action="{{ route('autopilot.setup.defaults') }}" class="mb-3">@csrf<button class="btn btn-sm btn-primary-lp"><i class="bi bi-magic"></i> Apply recommended defaults</button>
    <span class="small text-muted ms-2">Fixes demo Reply-To, approves services your ICPs target, sets timezone, creates starter ICPs. Never touches credentials or billing.</span></form>
<div class="d-flex gap-2 mb-3"><span class="badge bg-success">{{ $c->where('status','ok')->count() }} OK</span><span class="badge bg-warning text-dark">{{ $c->where('status','warn')->count() }} WARN</span><span class="badge bg-danger">{{ $c->where('status','fail')->count() }} FAIL</span><span class="badge bg-secondary">{{ $c->where('status','info')->count() }} OPTIONAL / PENDING FIRST USE</span></div>
<div class="card-lp p-0">
<table class="table table-lp small align-middle mb-0"><thead><tr class="text-muted"><th class="ps-3">Status</th><th>Check</th><th>Detail</th><th>How to fix</th></tr></thead><tbody>
@foreach($c->sortBy(fn($x) => ['fail'=>0,'warn'=>1,'ok'=>2,'info'=>3][$x['status']] ?? 4) as $x)
    <tr><td class="ps-3"><span class="badge {{ ['ok'=>'bg-success','warn'=>'bg-warning text-dark','fail'=>'bg-danger','info'=>'bg-secondary'][$x['status']] ?? 'bg-light' }}">{{ $x['status'] === 'info' ? 'OPTIONAL' : strtoupper($x['status']) }}</span></td>
        <td class="fw-semibold">{{ $x['label'] }}</td><td>{{ $x['detail'] }}</td><td class="text-muted">{{ $x['fix'] }}</td></tr>
@endforeach
</tbody></table></div>
@endsection
