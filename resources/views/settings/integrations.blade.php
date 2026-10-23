@extends('layouts.app')

@section('title', 'Integrations & API Settings')

@section('content')
<div class="mb-4">
    <h3 class="fw-bold mb-1">Integrations & External APIs</h3>
    <p class="text-muted small mb-0">Configure credentials for AI intelligence, B2B email enrichment, and outbound transactional mailers.</p>
</div>

<!-- Integrations Grid -->
<div class="row g-4 mb-4">
    @foreach($integrations as $integ)
    <div class="col-lg-6">
        <div class="card-lp p-4 h-100 d-flex flex-column justify-content-between">
            <div>
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <div>
                        <h5 class="fw-bold text-dark fs-6 mb-1">{{ $integ->name }}</h5>
                        <span class="badge {{ $integ->status === 'connected' ? 'bg-success' : ($integ->status === 'error' ? 'bg-danger' : 'bg-warning text-dark') }}">
                            {{ ucwords(str_replace('_', ' ', $integ->status)) }}
                        </span>
                        @if($integ->is_active)
                            <span class="badge bg-primary-subtle text-primary ms-1">Active</span>
                        @else
                            <span class="badge bg-light text-secondary ms-1 border">Inactive</span>
                        @endif
                    </div>

                    <div class="rounded-circle bg-light border p-2 text-primary">
                        <i class="bi {{ $integ->provider === 'openai' ? 'bi-cpu' : ($integ->provider === 'hunter' ? 'bi-search' : ($integ->provider === 'apollo' ? 'bi-database' : 'bi-envelope')) }} fs-4"></i>
                    </div>
                </div>

                <p class="text-muted small mb-3" style="font-size: 0.85rem; line-height: 1.5;">
                    {{ $integ->description }}
                </p>

                <div class="p-3 bg-light rounded border small mb-3">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <span class="text-muted">Stored API Key:</span>
                        <code class="text-dark fw-bold">{{ $integ->getMaskedKey() }}</code>
                    </div>
                    @if($integ->last_tested_at)
                    <div class="d-flex justify-content-between align-items-center text-muted" style="font-size: 0.725rem;">
                        <span>Last Tested:</span>
                        <span>{{ $integ->last_tested_at->diffForHumans() }}</span>
                    </div>
                    @endif
                    @if($integ->last_error)
                    <div class="text-danger mt-1 pt-1 border-top" style="font-size: 0.75rem;">
                        <i class="bi bi-exclamation-circle me-1"></i>{{ Str::limit($integ->last_error, 90) }}
                    </div>
                    @endif
                </div>
            </div>

            <!-- Configuration & Actions -->
            <div class="pt-2 border-top">
                <div class="d-flex justify-content-between align-items-center">
                    <button class="btn btn-sm btn-light border" data-bs-toggle="collapse" data-bs-target="#config_{{ $integ->id }}">
                        <i class="bi bi-gear me-1"></i>Configure Credentials
                    </button>

                    <form action="{{ route('settings.integrations.test', $integ) }}" method="POST">
                        @csrf
                        <button type="submit" class="btn btn-sm btn-outline-primary">
                            <i class="bi bi-lightning-charge me-1"></i>Test Connection
                        </button>
                    </form>
                </div>

                <!-- Collapsible Settings Form -->
                <div class="collapse mt-3" id="config_{{ $integ->id }}">
                    <form action="{{ route('settings.integrations.update', $integ) }}" method="POST" class="p-3 bg-light-subtle rounded border">
                        @csrf
                        @method('PUT')

                        <div class="mb-3">
                            <label class="form-label small fw-semibold">API Key / Token</label>
                            <input type="password" name="api_key" class="form-control form-control-sm" placeholder="Enter new API key to update...">
                            <div class="form-text" style="font-size: 0.7rem;">Credentials are encrypted with AES-256 before storage.</div>
                        </div>

                        @if($integ->provider === 'openai')
                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <label class="form-label small fw-semibold">Model</label>
                                <select name="settings[model]" class="form-select form-select-sm">
                                    <option value="gpt-4o-mini" {{ ($integ->settings['model'] ?? '') === 'gpt-4o-mini' ? 'selected' : '' }}>gpt-4o-mini (Cost-optimized)</option>
                                    <option value="gpt-4o" {{ ($integ->settings['model'] ?? '') === 'gpt-4o' ? 'selected' : '' }}>gpt-4o (Deep reasoning)</option>
                                </select>
                            </div>
                            <div class="col-6">
                                <label class="form-label small fw-semibold">Max Tokens</label>
                                <input type="number" name="settings[max_tokens]" class="form-control form-control-sm" value="{{ $integ->settings['max_tokens'] ?? 1200 }}">
                            </div>
                        </div>
                        @endif

                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" name="is_active" value="1" id="active_{{ $integ->id }}" {{ $integ->is_active ? 'checked' : '' }}>
                            <label class="form-check-label small fw-semibold" for="active_{{ $integ->id }}">Enable this integration</label>
                        </div>

                        <div class="text-end">
                            <button type="submit" class="btn btn-sm btn-primary-lp px-3">Save Settings</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    @endforeach
</div>

<!-- API Usage Logs & Security Audit Log -->
<div class="row g-4">
    <!-- API Usage Logs -->
    <div class="col-lg-6">
        <div class="card-lp p-4 h-100">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h6 class="fw-bold mb-0"><i class="bi bi-clock-history text-primary me-1"></i> API Consumption Tracking</h6>
                <span class="small fw-bold text-dark">Total Spend: ${{ number_format($totalSpend, 4) }}</span>
            </div>
            <div class="table-responsive">
                <table class="table table-sm align-middle table-lp">
                    <thead>
                        <tr class="text-muted small">
                            <th>Service</th>
                            <th>Tokens / Units</th>
                            <th>Est. Cost</th>
                            <th>Logged At</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($recentUsage as $u)
                        <tr>
                            <td>
                                <span class="badge bg-light text-dark border">{{ strtoupper($u->provider) }}</span>
                                <span class="small text-muted">{{ $u->service_type }}</span>
                            </td>
                            <td>{{ $u->tokens_used ?: $u->units_consumed }}</td>
                            <td>${{ number_format($u->cost_estimated, 4) }}</td>
                            <td class="text-muted small">{{ $u->occurred_at->diffForHumans() }}</td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="4" class="text-center text-muted py-3">No API consumption logged.</td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Security Audit Trail -->
    <div class="col-lg-6">
        <div class="card-lp p-4 h-100">
            <h6 class="fw-bold mb-3"><i class="bi bi-shield-lock text-success me-1"></i> Security & Audit Trail</h6>
            <div class="table-responsive">
                <table class="table table-sm align-middle table-lp">
                    <thead>
                        <tr class="text-muted small">
                            <th>User</th>
                            <th>Action</th>
                            <th>IP Address</th>
                            <th>Timestamp</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($recentAudits as $a)
                        <tr>
                            <td class="small fw-semibold text-dark">{{ $a->user?->name ?? 'System' }}</td>
                            <td><span class="badge bg-light text-secondary border">{{ $a->action }}</span></td>
                            <td class="small text-muted font-monospace">{{ $a->ip_address ?: '127.0.0.1' }}</td>
                            <td class="small text-muted">{{ $a->created_at->diffForHumans() }}</td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="4" class="text-center text-muted py-3">No audit actions logged.</td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection
