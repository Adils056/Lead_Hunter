@extends('layouts.app')
@section('title', $template->exists ? 'Edit WhatsApp Template' : 'New WhatsApp Template')
@section('content')
<div class="row justify-content-center"><div class="col-lg-8">
    <nav aria-label="breadcrumb"><ol class="breadcrumb mb-2" style="font-size: 0.825rem;">
        <li class="breadcrumb-item"><a href="{{ route('whatsapp-templates.index') }}">WhatsApp Templates</a></li>
        <li class="breadcrumb-item active">{{ $template->exists ? $template->name : 'New' }}</li>
    </ol></nav>
    <h3 class="fw-bold mb-3">{{ $template->exists ? 'Edit WhatsApp Template' : 'New WhatsApp Template' }}</h3>
    <div class="card-lp p-4">
        <form method="POST" action="{{ $template->exists ? route('whatsapp-templates.update', $template) : route('whatsapp-templates.store') }}">
            @csrf @if($template->exists) @method('PUT') @endif
            <div class="row g-3">
                <div class="col-md-7"><label class="form-label small fw-semibold" for="name">Name</label>
                    <input type="text" name="name" id="name" class="form-control @error('name') is-invalid @enderror" value="{{ old('name', $template->name) }}" required>
                    @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                <div class="col-md-5"><label class="form-label small fw-semibold" for="purpose">Use</label>
                    <select name="purpose" id="purpose" class="form-select">
                        @foreach(\App\Models\WhatsappTemplate::PURPOSES as $k => $label)<option value="{{ $k }}" @selected(old('purpose', $template->purpose) === $k)>{{ $label }}</option>@endforeach
                    </select></div>
                <div class="col-12"><label class="form-label small fw-semibold" for="body">Message</label>
                    <textarea name="body" id="body" rows="6" class="form-control @error('body') is-invalid @enderror" maxlength="1000" required>{{ old('body', $template->body) }}</textarea>
                    @error('body')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    <div class="form-text">Keep it short and personal. Placeholders: @foreach(\App\Models\WhatsappTemplate::PLACEHOLDERS as $p)<code>&#123;{{ $p }}&#125;</code>@if(!$loop->last), @endif @endforeach. <code>&#123;finding&#125;</code> is the lead's top verified website / signal finding.</div></div>
                <div class="col-12"><div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" name="is_active" value="1" id="is_active" @checked(old('is_active', $template->is_active))>
                    <label class="form-check-label small" for="is_active">Active (offered on lead pages)</label></div></div>
            </div>
            <div class="d-flex justify-content-end gap-2 pt-3 mt-3 border-top">
                <a href="{{ route('whatsapp-templates.index') }}" class="btn btn-light border px-4">Cancel</a>
                <button type="submit" class="btn btn-primary-lp px-4">Save</button>
            </div>
        </form>
    </div>
</div></div>
@endsection
