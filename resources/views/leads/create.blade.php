@extends('layouts.app')

@section('title', 'Add Prospect')

@section('content')
<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="mb-4">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-2" style="font-size: 0.825rem;">
                    <li class="breadcrumb-item"><a href="{{ route('leads.index') }}">Leads</a></li>
                    <li class="breadcrumb-item active">Add Prospect</li>
                </ol>
            </nav>
            <h3 class="fw-bold mb-1">Create Lead Prospect</h3>
            <p class="text-muted small">Enter prospect credentials. Automatic 100-point scoring and email deliverability checks will run upon save.</p>
        </div>

        <div class="card-lp p-4">
            <form action="{{ route('leads.store') }}" method="POST">
                @csrf
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Full Name <span class="text-danger">*</span></label>
                        <input type="text" name="full_name" class="form-control" value="{{ old('full_name') }}" placeholder="e.g. Sarah Jenkins" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Job Title</label>
                        <input type="text" name="job_title" class="form-control" value="{{ old('job_title') }}" placeholder="e.g. VP of Engineering, CTO, Product Lead">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Business Email</label>
                        <input type="email" name="email" class="form-control" value="{{ old('email') }}" placeholder="name@company.com">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Phone Number</label>
                        <input type="text" name="phone" class="form-control" value="{{ old('phone') }}" placeholder="+1 (555) 123-4567">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Company</label>
                        <select name="company_id" class="form-select">
                            <option value="">Select Company (or leave unassigned)...</option>
                            @foreach($companies as $comp)
                                <option value="{{ $comp->id }}" {{ old('company_id', request('company_id')) == $comp->id ? 'selected' : '' }}>
                                    {{ $comp->name }} ({{ $comp->country }})
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Lead Source</label>
                        <select name="lead_source_id" class="form-select">
                            <option value="">Select Discovery Source...</option>
                            @foreach($sources as $src)
                                <option value="{{ $src->id }}" {{ old('lead_source_id') == $src->id ? 'selected' : '' }}>
                                    {{ $src->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Target Country</label>
                        <input type="text" name="country" class="form-control" value="{{ old('country', 'United States') }}" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Industry</label>
                        <input type="text" name="industry" class="form-control" value="{{ old('industry') }}" placeholder="e.g. Financial Technology, SaaS">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">LinkedIn Profile URL</label>
                        <input type="url" name="profile_url" class="form-control" value="{{ old('profile_url') }}" placeholder="https://linkedin.com/in/prospect">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Assigned Sales Representative</label>
                        <select name="assigned_user_id" class="form-select">
                            @foreach($salesReps as $rep)
                                <option value="{{ $rep->id }}" {{ old('assigned_user_id', Auth::id()) == $rep->id ? 'selected' : '' }}>
                                    {{ $rep->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label small fw-semibold">Relevant Agency Services</label>
                        <div class="row g-2">
                            @foreach([
                                'Laravel and PHP development',
                                'ERP and CRM development',
                                'Custom software development',
                                'E-commerce development',
                                'API development and integration',
                                'SaaS product development',
                                'AI automation and business workflow automation'
                            ] as $srv)
                            <div class="col-md-6">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="relevant_services[]" value="{{ $srv }}" id="srv_{{ md5($srv) }}" {{ $loop->first ? 'checked' : '' }}>
                                    <label class="form-check-label small" for="srv_{{ md5($srv) }}">
                                        {{ $srv }}
                                    </label>
                                </div>
                            </div>
                            @endforeach
                        </div>
                    </div>
                    <div class="col-12">
                        <label class="form-label small fw-semibold">Prospect Research Notes</label>
                        <textarea name="notes" rows="2" class="form-control" placeholder="Observations, public requirements or outreach angles...">{{ old('notes') }}</textarea>
                    </div>
                </div>

                <div class="d-flex justify-content-end gap-2 pt-3 border-top">
                    <a href="{{ route('leads.index') }}" class="btn btn-light border px-4">Cancel</a>
                    <button type="submit" class="btn btn-primary-lp px-4">Save & Calculate Score</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
