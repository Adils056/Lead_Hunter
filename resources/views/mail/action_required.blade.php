{{ $payload['title'] ?? 'Action required' }}
Priority: {{ strtoupper($payload['priority'] ?? 'normal') }}

WHAT HAPPENED
{{ $payload['what'] ?? '' }}

WHY YOU ARE NEEDED
{{ $payload['why'] ?? '' }}

AFFECTED
{{ $payload['affected'] ?? '' }}
@if(!empty($payload['evidence']))

EVIDENCE
@foreach((array) $payload['evidence'] as $k => $v)
- {{ is_string($k) ? $k . ': ' : '' }}{{ is_scalar($v) ? $v : json_encode($v) }}
@endforeach
@endif
@if(!empty($payload['attempted']))

ALREADY ATTEMPTED
@foreach((array) $payload['attempted'] as $a)
- {{ $a }}
@endforeach
@endif
@if(!empty($payload['options']))

OPTIONS
@foreach((array) $payload['options'] as $o)
- {{ $o }}
@endforeach
@endif

RECOMMENDED
{{ $payload['recommended'] ?? '' }}
@if(!empty($payload['deadline']))

DEADLINE: {{ $payload['deadline'] }}
@endif

Open (login required): {{ $payload['url'] ?? config('app.url') }}

— LeadPilot Autopilot. You receive this because you are the responsible {{ $payload['role'] ?? 'user' }}.
