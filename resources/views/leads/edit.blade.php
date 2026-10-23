@extends('layouts.app')

@section('title', 'Edit ' . $lead->full_name)

@section('content')
<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="mb-4">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-2" style="font-size: 0.825rem;">
                    <li class="breadcrumb-item"><a href="{{ route('leads.index') }}">Leads</a></li>
                    <li class="breadcrumb-item"><a href="{{ route('leads.show', $lead) }}">{{ $lead->full_name }}</a></li>
                    <li class="breadcrumb-item active">Edit</li>
                </ol>
            </nav>
            <h3 class="fw-bold mb-1">Edit Prospect Information</h3>
        </div>

        <div class="card-lp p-4">
            <form action="{{ route('leads.update', $lead) }}" method="POST">
                @csrf
                @method('PUT')
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Full Name <span class="text-danger">*</span></label>
                        <input type="text" name="full_name" class="form-control" value="{{ old('full_name', $lead->full_name) }}" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Job Title</label>
                        <input type="text" name="job_title" class="form-control" value="{{ old('job_title', $lead->job_title) }}">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Business Email</label>
                        <input type="email" name="email" class="form-control" value="{{ old('email', $lead->email) }}">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Phone Number</label>
                        <input type="text" name="phone" class="form-control" value="{{ old('phone', $lead->phone) }}">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Company</label>
                        <select name="company_id" class="form-select">
                            <option value="">Unassigned</option>
                            @foreach($companies as $comp)
                                <option value="{{ $comp->id }}" {{ old('company_id', $lead->company_id) == $comp->id ? 'selected' : '' }}>
                                    {{ $comp->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Lifecycle Stage</label>
                        <select name="lifecycle_stage" class="form-select">
                            @foreach([
                                'new' => 'New',
                                'researching' => 'Researching',
                                'qualified' => 'Qualified',
                                'ready_for_review' => 'Ready for Review',
                                'approved' => 'Approved for Outreach',
                                'contacted' => 'Contacted',
                                'replied' => 'Replied',
                                'interested' => 'Interested',
                                'meeting_booked' => 'Meeting Booked',
                                'proposal_sent' => 'Proposal Sent',
                                'negotiation' => 'Negotiation',
                                'won' => 'Won Deal',
                                'lost' => 'Lost Deal',
                                'not_a_fit' => 'Not a Fit / Suppressed'
                            ] as $stgKey => $stgLabel)
                                <option value="{{ $stgKey }}" {{ old('lifecycle_stage', $lead->lifecycle_stage) == $stgKey ? 'selected' : '' }}>{{ $stgLabel }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Assigned Representative</label>
                        <select name="assigned_user_id" class="form-select">
                            <option value="">Unassigned</option>
                            @foreach($salesReps as $rep)
                                <option value="{{ $rep->id }}" {{ old('assigned_user_id', $lead->assigned_user_id) == $rep->id ? 'selected' : '' }}>
                                    {{ $rep->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Next Action Date</label>
                        <input type="date" name="next_action_date" class="form-control" value="{{ old('next_action_date', $lead->next_action_date?->format('Y-m-d')) }}">
                    </div>
                    <div class="col-12">
                        <label class="form-label small fw-semibold">Next Action Note</label>
                        <input type="text" name="next_action_note" class="form-control" value="{{ old('next_action_note', $lead->next_action_note) }}" placeholder="e.g. Follow up on proposal milestones">
                    </div>
                    <div class="col-12">
                        <label class="form-label small fw-semibold">Notes</label>
                        <textarea name="notes" rows="3" class="form-control">{{ old('notes', $lead->notes) }}</textarea>
                    </div>
                </div>

                <div class="d-flex justify-content-end gap-2 pt-3 border-top">
                    <a href="{{ route('leads.show', $lead) }}" class="btn btn-light border px-4">Cancel</a>
                    <button type="submit" class="btn btn-primary-lp px-4">Update Lead</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
