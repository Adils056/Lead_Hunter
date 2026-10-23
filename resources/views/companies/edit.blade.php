@extends('layouts.app')

@section('title', 'Edit ' . $company->name)

@section('content')
<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="mb-4">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-2" style="font-size: 0.825rem;">
                    <li class="breadcrumb-item"><a href="{{ route('companies.index') }}">Companies</a></li>
                    <li class="breadcrumb-item"><a href="{{ route('companies.show', $company) }}">{{ $company->name }}</a></li>
                    <li class="breadcrumb-item active">Edit</li>
                </ol>
            </nav>
            <h3 class="fw-bold mb-1">Edit Company Details</h3>
        </div>

        <div class="card-lp p-4">
            <form action="{{ route('companies.update', $company) }}" method="POST">
                @csrf
                @method('PUT')
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Company Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" value="{{ old('name', $company->name) }}" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Website URL</label>
                        <input type="url" name="website" class="form-control" value="{{ old('website', $company->website) }}">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">Country <span class="text-danger">*</span></label>
                        <input type="text" name="country" class="form-control" value="{{ old('country', $company->country) }}" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">City</label>
                        <input type="text" name="city" class="form-control" value="{{ old('city', $company->city) }}">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">Lifecycle Status</label>
                        <select name="status" class="form-select">
                            <option value="prospect" {{ old('status', $company->status) == 'prospect' ? 'selected' : '' }}>Prospect</option>
                            <option value="active" {{ old('status', $company->status) == 'active' ? 'selected' : '' }}>Active Account</option>
                            <option value="customer" {{ old('status', $company->status) == 'customer' ? 'selected' : '' }}>Paying Customer</option>
                            <option value="archived" {{ old('status', $company->status) == 'archived' ? 'selected' : '' }}>Archived</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Company Size</label>
                        <select name="size_range" class="form-select">
                            <option value="">Select range...</option>
                            @foreach(['1-10', '11-50', '51-200', '201-500', '500+'] as $sz)
                                <option value="{{ $sz }}" {{ old('size_range', $company->size_range) == $sz ? 'selected' : '' }}>{{ $sz }} employees</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Assigned Sales Representative</label>
                        <select name="assigned_user_id" class="form-select">
                            <option value="">Unassigned</option>
                            @foreach($salesReps as $rep)
                                <option value="{{ $rep->id }}" {{ old('assigned_user_id', $company->assigned_user_id) == $rep->id ? 'selected' : '' }}>{{ $rep->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Industry</label>
                        <input type="text" name="industry" class="form-control" value="{{ old('industry', $company->industry) }}">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Public Email</label>
                        <input type="email" name="public_email" class="form-control" value="{{ old('public_email', $company->public_email) }}">
                    </div>
                    <div class="col-12">
                        <label class="form-label small fw-semibold">Technology Stack (Comma separated)</label>
                        <input type="text" name="tech_stack" class="form-control" value="{{ old('tech_stack', is_array($company->tech_stack) ? implode(', ', $company->tech_stack) : '') }}">
                    </div>
                    <div class="col-12">
                        <label class="form-label small fw-semibold">Business Description</label>
                        <textarea name="description" rows="3" class="form-control">{{ old('description', $company->description) }}</textarea>
                    </div>
                    <div class="col-12">
                        <label class="form-label small fw-semibold">Internal Rep Notes</label>
                        <textarea name="notes" rows="2" class="form-control">{{ old('notes', $company->notes) }}</textarea>
                    </div>
                </div>

                <div class="d-flex justify-content-end gap-2 pt-3 border-top">
                    <a href="{{ route('companies.show', $company) }}" class="btn btn-light border px-4">Cancel</a>
                    <button type="submit" class="btn btn-primary-lp px-4">Update Company</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
