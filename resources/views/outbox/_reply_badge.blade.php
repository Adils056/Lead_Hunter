@php
    $cat = $reply->category ?: $reply->classification ?: 'uncertain';
    $cls = ['interested' => 'bg-success', 'request_info' => 'bg-info text-dark', 'referral' => 'bg-primary', 'objection' => 'bg-warning text-dark',
        'out_of_office' => 'bg-secondary', 'not_interested' => 'bg-dark', 'unsubscribe' => 'bg-danger', 'negative' => 'bg-dark', 'positive' => 'bg-success'][$cat] ?? 'bg-light text-dark border';
@endphp
<span class="badge {{ $cls }}">{{ ucfirst(str_replace('_', ' ', $cat)) }}</span>
