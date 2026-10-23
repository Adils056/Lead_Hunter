@extends('layouts.app')

@section('title', 'Create Campaign')

@section('content')
<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="mb-4">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-2" style="font-size: 0.825rem;">
                    <li class="breadcrumb-item"><a href="{{ route('campaigns.index') }}">Campaigns</a></li>
                    <li class="breadcrumb-item active">Create Campaign</li>
                </ol>
            </nav>
            <h3 class="fw-bold mb-1">Configure Outreach Campaign</h3>
            <p class="text-muted small">Select your target market parameters and outreach email template. The engine will automatically generate personalized drafts for review.</p>
        </div>

        <div class="card-lp p-4">
            <form action="{{ route('campaigns.store') }}" method="POST">
                @csrf
                <div class="row g-3 mb-3">
                    <div class="col-12">
                        <label class="form-label small fw-semibold">Campaign Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" value="{{ old('name') }}" placeholder="e.g. Q4 FinTech Laravel Engineering Outbound" required>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Service Being Offered <span class="text-danger">*</span></label>
                        <select name="service_offered" class="form-select" required>
                            @foreach([
                                'Laravel and PHP development',
                                'ERP and CRM development',
                                'Custom software development',
                                'E-commerce development',
                                'API development and integration',
                                'SaaS product development',
                                'Database design, optimization, and system integrations',
                                'Software maintenance and technical consulting'
                            ] as $srv)
                                <option value="{{ $srv }}" {{ old('service_offered') == $srv ? 'selected' : '' }}>{{ $srv }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Email Template <span class="text-danger">*</span></label>
                        <select name="email_template_id" class="form-select" required>
                            <option value="">Choose an approved template...</option>
                            @foreach($templates as $tmpl)
                                <option value="{{ $tmpl->id }}" {{ old('email_template_id') == $tmpl->id ? 'selected' : '' }}>
                                    {{ $tmpl->name }} ({{ $tmpl->service_category }})
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">Minimum Lead Score</label>
                        <input type="number" name="min_lead_score" class="form-control" value="{{ old('min_lead_score', \App\Support\ScoreBands::qualified()) }}" min="1" max="100" required>
                        <div class="form-text" style="font-size: 0.7rem;">Only prospects at or above this score are enrolled.</div>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">Daily Send Limit</label>
                        <input type="number" name="daily_limit" class="form-control" value="{{ old('daily_limit', 30) }}" min="1" max="200" required>
                        <div class="form-text" style="font-size: 0.7rem;">Safe throttling to protect domain reputation.</div>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">Schedule Timezone</label>
                        <select name="timezone" class="form-select">
                            <option value="UTC">UTC</option>
                            <option value="America/New_York">America/New_York (EST)</option>
                            <option value="Europe/London" selected>Europe/London (GMT)</option>
                            <option value="Asia/Karachi">Asia/Karachi (PKT)</option>
                            <option value="Australia/Sydney">Australia/Sydney (AEST)</option>
                        </select>
                    </div>

                    <div class="col-12">
                        <label class="form-label small fw-semibold">Target Countries</label>
                        <div class="row g-2">
                            @foreach(['United Kingdom', 'United States', 'Pakistan', 'Canada', 'Australia', 'Germany'] as $ctry)
                            <div class="col-md-4">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="target_countries[]" value="{{ $ctry }}" id="ctry_{{ md5($ctry) }}" checked>
                                    <label class="form-check-label small" for="ctry_{{ md5($ctry) }}">
                                        {{ $ctry }}
                                    </label>
                                </div>
                            </div>
                            @endforeach
                        </div>
                    </div>

                    <div class="col-12">
                        <div class="form-check form-switch p-3 bg-light rounded border">
                            <input class="form-check-input ms-0 me-2" type="checkbox" name="require_approval" value="1" id="require_approval" checked>
                            <label class="form-check-label fw-semibold small" for="require_approval">
                                Require Administrator Review & Approval Before Dispatch
                            </label>
                            <div class="text-muted small ms-4">
                                Safeguard against unsolicited or improperly personalized emails. Admin must explicitly approve queued drafts.
                            </div>
                        </div>
                    </div>
                </div>

                <div class="d-flex justify-content-end gap-2 pt-3 border-top">
                    <a href="{{ route('campaigns.index') }}" class="btn btn-light border px-4">Cancel</a>
                    <button type="submit" class="btn btn-primary-lp px-4">
                        <i class="bi bi-magic me-1"></i> Build Campaign Drafts
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
