@extends('layouts.app')

@section('title', $template->exists ? 'Edit Template' : 'New Template')

@section('content')
<div class="row justify-content-center">
    <div class="col-lg-8">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-2" style="font-size: 0.825rem;">
                <li class="breadcrumb-item"><a href="{{ route('templates.index') }}">Email Templates</a></li>
                <li class="breadcrumb-item active">{{ $template->exists ? $template->name : 'New' }}</li>
            </ol>
        </nav>
        <h3 class="fw-bold mb-3">{{ $template->exists ? 'Edit Template' : 'New Template' }}</h3>

        <div class="card-lp p-4">
            <form action="{{ $template->exists ? route('templates.update', $template) : route('templates.store') }}" method="POST">
                @csrf
                @if($template->exists) @method('PUT') @endif
                <div class="row g-3">
                    <div class="col-md-7">
                        <label class="form-label small fw-semibold">Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control @error('name') is-invalid @enderror" value="{{ old('name', $template->name) }}" required>
                        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-5">
                        <label class="form-label small fw-semibold">Service <span class="text-danger">*</span></label>
                        <select name="service_category" class="form-select" required>
                            @foreach($services as $slug => $label)
                                <option value="{{ $slug }}" {{ old('service_category', $template->service_category) === $slug ? 'selected' : '' }}>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label small fw-semibold">Subject <span class="text-danger">*</span></label>
                        <input type="text" name="subject" class="form-control @error('subject') is-invalid @enderror" value="{{ old('subject', $template->subject) }}" required>
                        @error('subject')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-12">
                        <label class="form-label small fw-semibold">Body <span class="text-danger">*</span></label>
                        <textarea name="body_text" rows="12" class="form-control font-monospace small @error('body_text') is-invalid @enderror" required>{{ old('body_text', $template->body_text) }}</textarea>
                        @error('body_text')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <div class="form-text">Placeholders: @foreach($variables as $v)<code>&#123;&#123;{{ $v }}&#125;&#125;</code>@if(!$loop->last), @endif @endforeach. An opt-out link is added automatically if missing.</div>
                    </div>
                    <div class="col-12">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="is_active" value="1" id="is_active" {{ old('is_active', $template->is_active) ? 'checked' : '' }}>
                            <label class="form-check-label small" for="is_active">Active (shown when creating a campaign)</label>
                        </div>
                    </div>
                </div>
                <div class="d-flex justify-content-end gap-2 pt-3 mt-3 border-top">
                    <a href="{{ route('templates.index') }}" class="btn btn-light border px-4">Cancel</a>
                    <button type="submit" class="btn btn-primary-lp px-4">Save Template</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
