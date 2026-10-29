@extends('layouts.app')

@section('title', 'Email Templates')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h3 class="fw-bold mb-1">Email Templates</h3>
        <p class="text-muted small mb-0">Used by manual campaigns. Autopilot writes its own draft from each lead's evidence and does not use these.</p>
    </div>
    @if(Auth::user()->isAdmin())
        <a href="{{ route('templates.create') }}" class="btn btn-primary-lp"><i class="bi bi-plus-lg me-1"></i>New Template</a>
    @endif
</div>

<div class="card-lp p-0">
    <table class="table table-lp align-middle mb-0">
        <thead><tr><th class="ps-3">Name</th><th>Service</th><th>Subject</th><th>Campaigns</th><th>Status</th><th></th></tr></thead>
        <tbody>
        @forelse($templates as $t)
            <tr>
                <td class="ps-3 fw-semibold">{{ $t->name }}</td>
                <td class="small">{{ $services[$t->service_category] ?? $t->service_category }}</td>
                <td class="small text-muted">{{ \Illuminate\Support\Str::limit($t->subject, 70) }}</td>
                <td>{{ $t->campaigns_count }}</td>
                <td><span class="badge {{ $t->is_active ? 'bg-success-subtle text-success' : 'bg-secondary-subtle text-secondary' }}">{{ $t->is_active ? 'Active' : 'Off' }}</span></td>
                <td class="text-end pe-3">
                    @if(Auth::user()->isAdmin())
                        <a href="{{ route('templates.edit', $t) }}" class="btn btn-sm btn-light border">Edit</a>
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="6" class="text-center text-muted py-4">No templates yet.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection
