<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Website report — {{ $company->name }}</title>
<style>
    @page { margin: 34px 38px 54px; }
    body { font-family: "DejaVu Sans", sans-serif; font-size: 10.5px; color: #1f2937; line-height: 1.45; }
    h1 { font-size: 21px; margin: 0 0 4px; color: #111827; }
    h2 { font-size: 13.5px; margin: 20px 0 8px; color: #312e81; border-bottom: 1.5px solid #c7d2fe; padding-bottom: 4px; }
    .muted { color: #6b7280; }
    .brand { color: #4f46e5; font-weight: bold; font-size: 11px; letter-spacing: .5px; text-transform: uppercase; }
    .box { background: #eef2ff; border-left: 4px solid #4f46e5; padding: 9px 12px; margin: 10px 0; }
    table { width: 100%; border-collapse: collapse; }
    th { text-align: left; font-size: 9px; text-transform: uppercase; color: #6b7280; border-bottom: 1px solid #e5e7eb; padding: 5px 6px; }
    td { padding: 6px; border-bottom: 1px solid #f1f5f9; vertical-align: top; }
    .num { text-align: right; white-space: nowrap; }
    .bar { background: #e5e7eb; height: 7px; width: 100%; }
    .fill { height: 7px; }
    .good { background: #16a34a; } .low { background: #f59e0b; }
    .tag { font-size: 8.5px; padding: 1px 5px; border-radius: 3px; }
    .fail { background: #fee2e2; color: #b91c1c; } .warn { background: #fef3c7; color: #92400e; }
    .two td { width: 50%; }
    footer { position: fixed; bottom: -36px; left: 0; right: 0; font-size: 9px; color: #6b7280; border-top: 1px solid #e5e7eb; padding-top: 6px; }
</style>
</head>
<body>
@php($ai = $report->ai)
<footer>{{ $agency['name'] }} · {{ $agency['website'] }}@if($settings->contact_phone) · {{ $settings->contact_phone }}@endif @if($settings->booking_url) · Book a call: {{ $settings->booking_url }}@endif</footer>

<div class="brand">{{ $agency['name'] }} · Website & competitor report</div>
<h1>{{ $company->name }}</h1>
<div class="muted">{{ $company->website }} · {{ $company->industry_group ?: $company->industry }} · {{ trim($company->city . ', ' . $company->country, ', ') }} · {{ $report->created_at->format('j F Y') }}</div>

@if($ai && ($ai['summary'] ?? null))
<div class="box"><strong>Summary.</strong> {{ $ai['summary'] }} @if($ai['competitor_insight'] ?? null)<br>{{ $ai['competitor_insight'] }}@endif</div>
@else
<div class="box"><strong>Summary.</strong> We checked {{ $company->website }} with {{ count($audit?->checks ?? []) }} automated tests and compared it with {{ count($b['competitors']) }} local {{ strtolower($company->industry_group ?: 'similar') }} business(es).@if($b['rank']) Overall it ranks {{ $b['rank'] }} of {{ $b['field'] }}.@endif</div>
@endif

<h2>How your website compares with local competitors</h2>
<table>
    <thead><tr><th style="width: 34%">Area (0-100)</th><th class="num">You</th><th class="num">Average</th><th class="num">Best</th><th style="width: 34%"></th></tr></thead>
    <tbody>
    @foreach($b['areas'] as $a)
        <tr>
            <td><strong>{{ $a['label'] }}</strong></td>
            <td class="num"><strong>{{ $a['you'] ?? '–' }}</strong></td><td class="num">{{ $a['average'] ?? '–' }}</td><td class="num">{{ $a['best'] ?? '–' }}</td>
            <td><div class="bar"><div class="fill {{ ($a['you'] ?? 0) >= ($a['average'] ?? 0) ? 'good' : 'low' }}" style="width: {{ $a['you'] ?? 0 }}%"></div></div></td>
        </tr>
    @endforeach
    </tbody>
</table>

<table class="two" style="margin-top: 10px"><tr>
    <td><strong>Where you are ahead</strong><ul style="margin: 4px 0 0 14px; padding: 0">@forelse($b['leads_in'] as $t)<li>{{ $t }}</li>@empty<li class="muted">No area clearly ahead yet.</li>@endforelse</ul></td>
    <td><strong>Where competitors are ahead</strong><ul style="margin: 4px 0 0 14px; padding: 0">@forelse($b['behind_in'] as $t)<li>{{ $t }}</li>@empty<li class="muted">No area clearly behind.</li>@endforelse</ul></td>
</tr></table>

<h2>What to fix first</h2>
<table>
    <thead><tr><th style="width: 38%">Finding</th><th class="num">Competitors who have it</th><th>How to fix</th></tr></thead>
    <tbody>
    @forelse(array_slice($b['gaps'], 0, 10) as $g)
        <tr><td><span class="tag {{ $g['status'] }}">{{ $g['status'] === 'fail' ? 'Problem' : 'Improve' }}</span> <strong>{{ $g['label'] }}</strong><br><span class="muted">{{ \Illuminate\Support\Str::limit($g['evidence'], 150) }}</span></td>
            <td class="num">{{ $g['competitors_with_it'] }} of {{ $g['competitors'] }}</td><td>{{ $g['fix'] }}</td></tr>
    @empty <tr><td colspan="3" class="muted">No problems found by the automated checks.</td></tr> @endforelse
    </tbody>
</table>

<h2>Software that would help you win customers from competitors</h2>
<table>
    @foreach(($ai['needs'] ?? []) ?: array_map(fn ($s) => ['solution' => $s['solution'], 'why' => $s['why']], $report->solutions ?? []) as $n)
        <tr><td style="width: 38%"><strong>{{ $n['solution'] }}</strong></td><td>{{ $n['why'] }}</td></tr>
    @endforeach
</table>

@if(count($b['competitors']))
<h2>Competitors compared</h2>
<table>
    <thead><tr><th>Business</th><th>Website</th><th class="num">Overall score</th><th class="num">Google reviews</th></tr></thead>
    <tbody>
    @foreach($b['competitors'] as $c)
        <tr><td>{{ $c['name'] }}</td><td class="muted">{{ preg_replace('#^https?://(www\.)?#', '', (string) $c['website']) }}</td><td class="num">{{ $c['scores']['overall'] ?? '–' }}</td><td class="num">{{ $c['reviews'] ?? '–' }}</td></tr>
    @endforeach
    </tbody>
</table>
@endif

<div class="box" style="margin-top: 16px"><strong>Next step.</strong> {{ $settings->ctaLine(true) }}<br>{{ config('leadpilot.agency.sender_name') }}, {{ $agency['name'] }}@if($settings->contact_phone) · {{ $settings->contact_phone }}@endif</div>
<p class="muted" style="font-size: 8.5px">How this was measured: automated checks of each public homepage (security, speed, mobile, Google visibility, enquiry paths) on the date above. Scores count only checks that could be run; a competitor whose site could not be checked is left out.</p>
</body>
</html>
