@extends('layouts.app')
@section('title', 'Weekly review')
@section('content')
@php($tz = config('leadpilot.timezone', 'Asia/Karachi'))
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div><h3 class="fw-bold mb-1">Weekly review</h3>
        <p class="text-muted small mb-0">{{ $r['from']->timezone($tz)->format('D j M') }} – {{ $r['end']->timezone($tz)->format('D j M Y') }}. Real outcomes only; sent to admins every Monday 09:20.</p></div>
    <a href="{{ route('autopilot.performance') }}" class="btn btn-sm btn-light border">Performance</a>
</div>

<div class="card-lp p-3 mb-4 border-start border-4 border-primary">
    <div class="small text-muted text-uppercase fw-semibold mb-1">Focus this week</div>
    <div class="fw-semibold">{{ $r['focus'] }}</div>
</div>

<div class="row g-3 mb-4">
    @foreach(['first_emails' => 'First emails', 'follow_ups' => 'Follow-ups', 'replies' => 'Replies', 'interested' => 'Interested', 'meetings' => 'Meetings', 'bounced' => 'Bounced'] as $k => $label)
        <div class="col-6 col-md-2"><div class="card-lp p-3"><div class="small text-muted">{{ $label }}</div><div class="fs-4 fw-bold">{{ $r['activity'][$k] }}</div></div></div>
    @endforeach
</div>

<div class="card-lp p-3 mb-4">
    <h6 class="fw-bold mb-1">Success rate by kind of lead</h6>
    <p class="small text-muted mb-2">Your own results (prospects answered, or e-mailed 14+ days ago) against the cold-email benchmark the success rate starts from. Below {{ \App\Services\Outreach\WinChance::OWN_DATA_MIN }} sends it is too early to judge.</p>
    <div class="table-responsive"><table class="table table-sm small align-middle mb-0">
        <thead><tr class="text-muted"><th>Kind of lead</th><th class="text-end">Sent</th><th class="text-end">Replied</th><th class="text-end">Your reply %</th><th class="text-end">Benchmark</th><th>Verdict</th><th class="text-end">Ready (A/B)</th></tr></thead>
        <tbody>
        @foreach($r['segments'] as $s)
            <tr>
                <td>{{ $s['label'] }}</td>
                <td class="text-end">{{ $s['sent'] }}</td>
                <td class="text-end">{{ $s['replied'] }}</td>
                <td class="text-end">{{ $s['reply_rate'] === null ? '–' : $s['reply_rate'] . '%' }}</td>
                <td class="text-end text-muted">{{ $s['benchmark_reply'] }}%</td>
                <td>
                    @if($s['verdict'] === 'beats_benchmark')<span class="badge bg-success-subtle text-success border">beats benchmark</span>
                    @elseif($s['verdict'] === 'below_benchmark')<span class="badge bg-warning-subtle text-warning border">below benchmark</span>
                    @else<span class="badge bg-light text-muted border">too early</span>@endif
                </td>
                <td class="text-end">{{ $s['ready'] }}</td>
            </tr>
        @endforeach
        </tbody>
    </table></div>
</div>

<div class="row g-3">
    <div class="col-md-6"><div class="card-lp p-3 h-100">
        <h6 class="fw-bold mb-2">Lead pool growth</h6>
        <table class="table table-sm small mb-2">
            <tr><td>New leads</td><td class="text-end fw-semibold">{{ $r['growth']['new_leads'] }}</td></tr>
            <tr><td>Websites found (website finder)</td><td class="text-end fw-semibold">{{ $r['growth']['websites_found'] }}</td></tr>
            <tr><td>Named decision-makers added</td><td class="text-end fw-semibold">{{ $r['growth']['named_contacts'] }}</td></tr>
            <tr><td>Mailboxes proven (SMTP / Hunter)</td><td class="text-end fw-semibold">{{ $r['growth']['emails_proven'] }}</td></tr>
        </table>
        @if($r['growth']['by_source'])
            <div class="small text-muted">By source: @foreach($r['growth']['by_source'] as $src => $n){{ $src ?: 'Manual' }} {{ $n }}@if(!$loop->last) · @endif @endforeach</div>
        @endif
    </div></div>
    <div class="col-md-6"><div class="card-lp p-3 h-100">
        <h6 class="fw-bold mb-2">Win Ladder now</h6>
        <table class="table table-sm small mb-0">
            @foreach(\App\Services\Outreach\WinLadderService::ORDER as $t)
                <tr><td><span class="badge bg-{{ \App\Services\Outreach\WinLadderService::TIERS[$t]['class'] }}">{{ $t }}</span> {{ \App\Services\Outreach\WinLadderService::TIERS[$t]['short'] }}</td><td class="text-end fw-semibold">{{ $r['ladder'][$t] }}</td></tr>
            @endforeach
        </table>
    </div></div>
</div>
@endsection
