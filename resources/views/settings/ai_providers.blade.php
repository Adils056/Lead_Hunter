@extends('layouts.app')
@section('title', 'AI Models')
@section('content')
@php
    $badge = ['ready' => ['Ready', 'bg-success-subtle text-success'], 'off' => ['Turned off', 'bg-secondary-subtle text-secondary'], 'no_key' => ['Needs key', 'bg-warning-subtle text-warning'],
        'limit' => ['Daily limit reached', 'bg-info-subtle text-info'], 'cooling' => ['Resting (rate limit)', 'bg-info-subtle text-info'], 'error' => ['Error', 'bg-danger-subtle text-danger']];
@endphp
<div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
    <div>
        <h3 class="fw-bold mb-1">AI Models</h3>
        <p class="text-muted small mb-0">Tried top to bottom for email drafts and website analysis. A model is skipped when it is off, at its daily limit, or resting after a rate limit, and the next one answers. Keys are stored encrypted and never shown again.</p>
    </div>
    <form method="POST" action="{{ route('settings.ai-providers.recommended') }}">@csrf<button class="btn btn-sm btn-light border"><i class="bi bi-list-check me-1"></i>Add recommended list</button></form>
</div>

<div class="card-lp p-0 mb-4">
    <div class="table-responsive"><table class="table table-lp align-middle mb-0 small">
        <thead><tr><th class="ps-3">#</th><th>Provider</th><th>Model</th><th>Key</th><th>Today</th><th>Status</th><th class="text-end pe-3"></th></tr></thead>
        <tbody>
        @forelse($providers as $p)
            @php([$label, $cls] = $badge[$p->state()])
            <tr>
                <td class="ps-3 text-muted">{{ $p->priority }}</td>
                <td><div class="fw-semibold">{{ $p->name }}</div><div class="text-muted" style="font-size:11px">{{ $p->kind === 'gemini' ? 'gemini' : 'openai-compatible' }}</div></td>
                <td class="font-monospace">{{ $p->model }}</td>
                <td class="font-monospace">{{ $p->maskedKey() }}</td>
                <td class="font-monospace">{{ $p->usedToday() }}{{ $p->daily_limit ? ' / ' . $p->daily_limit : '' }}</td>
                <td><span class="badge {{ $cls }}" @if($p->last_error) title="{{ $p->last_error }}" @endif>{{ $label }}</span></td>
                <td class="text-end pe-3 text-nowrap">
                    <form method="POST" action="{{ route('settings.ai-providers.test', $p) }}" class="d-inline">@csrf<button class="btn btn-sm btn-light border">Test</button></form>
                    <form method="POST" action="{{ route('settings.ai-providers.toggle', $p) }}" class="d-inline">@csrf<button class="btn btn-sm btn-light border">{{ $p->is_active ? 'Turn off' : 'Turn on' }}</button></form>
                    <a href="{{ route('settings.ai-providers', ['edit' => $p->id]) }}#form" class="btn btn-sm btn-light border">Edit</a>
                    <form method="POST" action="{{ route('settings.ai-providers.destroy', $p) }}" class="d-inline" onsubmit="return confirm('Remove {{ addslashes($p->name) }}?')">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger">Remove</button></form>
                </td>
            </tr>
            @if($p->last_error && in_array($p->state(), ['error', 'cooling'], true))
                <tr><td></td><td colspan="6" class="text-danger pt-0" style="font-size:11px">{{ \Illuminate\Support\Str::limit($p->last_error, 220) }}</td></tr>
            @endif
        @empty
            <tr><td colspan="7" class="text-center text-muted py-4">No AI models yet. Use "Add recommended list" or the form below.</td></tr>
        @endforelse
        </tbody>
    </table></div>
</div>

<div class="card-lp p-4" id="form">
    <h6 class="fw-bold mb-3">{{ $edit ? 'Edit ' . $edit->name : 'Add a model' }}</h6>
    <form method="POST" action="{{ $edit ? route('settings.ai-providers.update', $edit) : route('settings.ai-providers.store') }}" class="row g-3">
        @csrf
        @if($edit) @method('PUT') @endif
        <div class="col-md-3">
            <label class="form-label small" for="preset">Service</label>
            <select name="preset" id="preset" class="form-select form-select-sm">
                @if($edit)<option value="">(keep)</option>@endif
                @foreach($presets as $k => $pr)<option value="{{ $k }}" @selected(old('preset') === $k)>{{ $pr['label'] }}</option>@endforeach
            </select>
        </div>
        <div class="col-md-3"><label class="form-label small" for="model">Model</label>
            <input name="model" id="model" class="form-control form-control-sm font-monospace" value="{{ old('model', $edit?->model) }}" placeholder="openai/gpt-oss-120b" required></div>
        <div class="col-md-3"><label class="form-label small" for="name">Display name (optional)</label>
            <input name="name" id="name" class="form-control form-control-sm" value="{{ old('name', $edit?->name) }}"></div>
        <div class="col-md-3"><label class="form-label small" for="base_url">API base URL (only for "Other")</label>
            <input name="base_url" id="base_url" class="form-control form-control-sm" value="{{ old('base_url', $edit?->base_url) }}" placeholder="https://…/v1"></div>
        <div class="col-md-5"><label class="form-label small" for="api_key">API key {{ $edit ? '(leave empty to keep ' . $edit->maskedKey() . ')' : '' }}</label>
            <input type="password" name="api_key" id="api_key" class="form-control form-control-sm font-monospace" autocomplete="off"></div>
        <div class="col-md-2"><label class="form-label small" for="daily_limit">Requests / day</label>
            <input type="number" name="daily_limit" id="daily_limit" class="form-control form-control-sm" value="{{ old('daily_limit', $edit?->daily_limit) }}" placeholder="no limit"></div>
        <div class="col-md-2"><label class="form-label small" for="priority">Order (#)</label>
            <input type="number" name="priority" id="priority" class="form-control form-control-sm" value="{{ old('priority', $edit?->priority ?? (($providers->max('priority') ?? 0) + 10)) }}" required></div>
        @if($edit)
            <div class="col-md-3 d-flex align-items-end"><div class="form-check small"><input class="form-check-input" type="checkbox" name="same_key_for_service" value="1" id="same_key_for_service" checked>
                <label class="form-check-label" for="same_key_for_service">Use this key for every model of this service</label></div></div>
        @endif
        <div class="col-12 d-flex gap-2">
            <button class="btn btn-primary-lp btn-sm">{{ $edit ? 'Save' : 'Add model' }}</button>
            @if($edit)<a href="{{ route('settings.ai-providers') }}" class="btn btn-light border btn-sm">Cancel</a>@endif
        </div>
        @if($errors->any())<div class="col-12 small text-danger">{{ $errors->first() }}</div>@endif
    </form>
</div>
@endsection
