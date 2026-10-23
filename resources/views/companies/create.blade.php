@extends('layouts.app')

@section('title', 'Add Company')

@section('content')
<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="mb-4">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-2" style="font-size: 0.825rem;">
                    <li class="breadcrumb-item"><a href="{{ route('companies.index') }}">Companies</a></li>
                    <li class="breadcrumb-item active">Add Company</li>
                </ol>
            </nav>
            <h3 class="fw-bold mb-1">Add New Company</h3>
            <p class="text-muted small">Enter business details. Domain normalization and duplicate detection are applied automatically.</p>
        </div>

        <div class="card-lp p-4">
            <form action="{{ route('companies.store') }}" method="POST">
                @csrf
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Company Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" value="{{ old('name') }}" placeholder="e.g. Stripe Inc" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Website URL</label>
                        <input type="url" name="website" class="form-control" value="{{ old('website') }}" placeholder="https://example.com">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">Country <span class="text-danger">*</span></label>
                        <input type="text" name="country" class="form-control" value="{{ old('country', 'United States') }}" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">City</label>
                        <input type="text" name="city" class="form-control" value="{{ old('city') }}" placeholder="e.g. Austin, TX">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">Company Size</label>
                        <select name="size_range" class="form-select">
                            <option value="">Select range...</option>
                            <option value="1-10">1-10 employees</option>
                            <option value="11-50">11-50 employees</option>
                            <option value="51-200">51-200 employees</option>
                            <option value="201-500">201-500 employees</option>
                            <option value="500+">500+ employees</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Industry</label>
                        <input type="text" name="industry" class="form-control" value="{{ old('industry') }}" placeholder="e.g. Financial Technology">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Assigned Sales Representative</label>
                        <select name="assigned_user_id" class="form-select">
                            <option value="">Unassigned</option>
                            @foreach($salesReps as $rep)
                                <option value="{{ $rep->id }}" {{ old('assigned_user_id', Auth::id()) == $rep->id ? 'selected' : '' }}>{{ $rep->name }} ({{ ucfirst($rep->role) }})</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Public Business Email</label>
                        <input type="email" name="public_email" class="form-control" value="{{ old('public_email') }}" placeholder="contact@example.com">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Public Business Phone</label>
                        <input type="text" name="public_phone" class="form-control" value="{{ old('public_phone') }}" placeholder="+1 (555) 000-0000">
                    </div>
                    <div class="col-12">
                        <label class="form-label small fw-semibold">Technology Stack (Comma separated)</label>
                        <input type="text" name="tech_stack" class="form-control" value="{{ old('tech_stack') }}" placeholder="Laravel, PHP, Vue.js, MySQL, AWS">
                    </div>
                    <div class="col-12">
                        <label class="form-label small fw-semibold">Business Description / Discovery Notes</label>
                        <textarea name="description" rows="3" class="form-control" placeholder="Overview of business model, services offered, and observed software opportunities...">{{ old('description') }}</textarea>
                    </div>
                </div>

                <div class="d-flex justify-content-end gap-2 pt-3 border-top">
                    <a href="{{ route('companies.index') }}" class="btn btn-light border px-4">Cancel</a>
                    <button type="submit" class="btn btn-primary-lp px-4">Save Company</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
