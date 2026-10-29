@extends('layouts.app')

@section('title', 'Log Buying Signal')

@section('content')
<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="mb-4">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-2" style="font-size: 0.825rem;">
                    <li class="breadcrumb-item"><a href="{{ route('buying-signals.index') }}">Buying Signals</a></li>
                    <li class="breadcrumb-item active">Log Signal</li>
                </ol>
            </nav>
            <h3 class="fw-bold mb-1">Log Intent Signal</h3>
            <p class="text-muted small">Record external procurement, tech hiring, or business expansion evidence. Company leads will be automatically rescored.</p>
        </div>

        <div class="card-lp p-4">
            <form action="{{ route('buying-signals.store') }}" method="POST">
                @csrf
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Target Company <span class="text-danger">*</span></label>
                        <x-company-picker :selected="$selectedCompany" empty-label="Select Company..." :required="true" />
                    </div>

                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Signal Category <span class="text-danger">*</span></label>
                        <select name="signal_type" class="form-select" required>
                            <option value="developer_hiring">Developer Hiring Signal</option>
                            <option value="project_requirements">Software Project Requirements</option>
                            <option value="erp_crm_request">ERP / CRM Solution Request</option>
                            <option value="procurement_rfp">Public Procurement / RFP</option>
                            <option value="funding_announcement">Funding Announcement</option>
                            <option value="business_expansion">Business Expansion</option>
                            <option value="vendor_request">Public Request for Vendor</option>
                            <option value="tech_stack_change">Technology Stack Change</option>
                        </select>
                    </div>

                    <div class="col-12">
                        <label class="form-label small fw-semibold">Signal Headline / Title <span class="text-danger">*</span></label>
                        <input type="text" name="title" class="form-control" value="{{ old('title') }}" placeholder="e.g. Urgent need for 2 Senior Laravel Engineers" required>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Matching Agency Service <span class="text-danger">*</span></label>
                        <select name="relevant_service" class="form-select" required>
                            <option value="Laravel and PHP development">Laravel and PHP development</option>
                            <option value="ERP and CRM development">ERP and CRM development</option>
                            <option value="Custom software development">Custom software development</option>
                            <option value="E-commerce development">E-commerce development</option>
                            <option value="API development and integration">API development and integration</option>
                            <option value="SaaS product development">SaaS product development</option>
                            <option value="Database design, optimization, and system integrations">Database design & optimization</option>
                        </select>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label small fw-semibold">Confidence (1-100%)</label>
                        <input type="number" name="confidence" class="form-control" value="{{ old('confidence', 85) }}" min="1" max="100" required>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label small fw-semibold">Observed Date <span class="text-danger">*</span></label>
                        <input type="date" name="observed_date" class="form-control" value="{{ old('observed_date', date('Y-m-d')) }}" required>
                    </div>

                    <div class="col-12">
                        <label class="form-label small fw-semibold">Source URL (Job posting, RFP link, news item)</label>
                        <input type="url" name="source_url" class="form-control" value="{{ old('source_url') }}" placeholder="https://example.com/posting">
                    </div>

                    <div class="col-12">
                        <label class="form-label small fw-semibold">Evidence Snippet / Details <span class="text-danger">*</span></label>
                        <textarea name="evidence_text" rows="3" class="form-control" placeholder="Direct quote or factual excerpt demonstrating software buying intent..." required>{{ old('evidence_text') }}</textarea>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Verification Status</label>
                        <select name="verification_status" class="form-select">
                            <option value="verified">Verified (Direct Source Confirmed)</option>
                            <option value="unverified">Unverified (Pending Review)</option>
                        </select>
                    </div>
                </div>

                <div class="d-flex justify-content-end gap-2 pt-3 border-top">
                    <a href="{{ route('buying-signals.index') }}" class="btn btn-light border px-4">Cancel</a>
                    <button type="submit" class="btn btn-primary-lp px-4">Log & Rescore Leads</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
