@extends('layouts.app')
@section('title', 'WhatsApp Templates')
@section('content')
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h3 class="fw-bold mb-1">WhatsApp Templates</h3>
        <p class="text-muted small mb-0">Open any lead and use "WhatsApp" to fill one of these with the lead's details. It opens in WhatsApp for you to check and send; nothing is sent automatically, which keeps your number safe. Message people who replied or shared their number for contact.</p>
    </div>
    @if(Auth::user()->isAdmin())
        <a href="{{ route('whatsapp-templates.create') }}" class="btn btn-primary-lp"><i class="bi bi-plus-lg me-1"></i>New Template</a>
    @endif
</div>
<div class="card-lp p-0">
    <div class="table-responsive"><table class="table table-lp align-middle mb-0">
        <thead><tr><th class="ps-3">Name</th><th>Use</th><th>Message</th><th>Status</th><th></th></tr></thead>
        <tbody>
        @forelse($templates as $t)
            <tr>
                <td class="ps-3 fw-semibold">{{ $t->name }}</td>
                <td class="small">{{ \App\Models\WhatsappTemplate::PURPOSES[$t->purpose] ?? $t->purpose }}</td>
                <td class="small text-muted" style="max-width: 420px">{{ \Illuminate\Support\Str::limit($t->body, 140) }}</td>
                <td><span class="badge {{ $t->is_active ? 'bg-success-subtle text-success' : 'bg-secondary-subtle text-secondary' }}">{{ $t->is_active ? 'Active' : 'Off' }}</span></td>
                <td class="text-end pe-3 text-nowrap">
                    @if(Auth::user()->isAdmin())
                        <a href="{{ route('whatsapp-templates.edit', $t) }}" class="btn btn-sm btn-light border">Edit</a>
                        <form method="POST" action="{{ route('whatsapp-templates.destroy', $t) }}" class="d-inline" onsubmit="return confirm('Remove this template?')">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger">Remove</button></form>
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="5" class="text-center text-muted py-4">No templates yet.</td></tr>
        @endforelse
        </tbody>
    </table></div>
</div>
@endsection
