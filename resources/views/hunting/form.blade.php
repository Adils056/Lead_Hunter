@extends('layouts.app')
@section('title', $icp->exists ? 'Edit ICP' : 'New ICP')
@section('content')
@php
    $csv = fn($f) => old($f, implode(', ', $icp->list($f)));
    $sel = fn($f) => (array) old($f, $icp->{$f} ?? []);
    $services = config('leadpilot.services');
@endphp
<div class="mb-4">
    <h3 class="fw-bold mb-1">{{ $icp->exists ? 'Edit' : 'New' }} Ideal Customer Profile</h3>
    <p class="text-muted small mb-0">Comma-separated lists. Leave a field empty to not filter on it. Nothing here invents data — it only decides what to search for and what to keep.</p>
</div>
@if($errors->any())<div class="alert alert-danger small">{{ implode(' ', $errors->all()) }}</div>@endif
<form method="POST" action="{{ $icp->exists ? route('hunting.update', $icp) : route('hunting.store') }}">
    @csrf @if($icp->exists) @method('PUT') @endif
    <div class="row g-4">
        <div class="col-lg-8">
            <div class="card-lp p-4 mb-4">
                <h6 class="fw-bold">1. Who & where</h6>
                <div class="row g-3">
                    <div class="col-md-8"><label class="form-label small fw-semibold">Profile name *</label><input name="name" class="form-control form-control-sm" required value="{{ old('name', $icp->name) }}" placeholder="Dental clinics in Lahore with weak websites"></div>
                    <div class="col-md-4 d-flex align-items-end"><div class="form-check"><input type="checkbox" class="form-check-input" name="is_active" value="1" id="act" {{ old('is_active', $icp->is_active) ? 'checked' : '' }}><label class="form-check-label small" for="act">Active</label></div></div>
                    <div class="col-md-4"><label class="form-label small fw-semibold">Countries</label><input name="countries" class="form-control form-control-sm" value="{{ $csv('countries') }}" placeholder="Pakistan"></div>
                    <div class="col-md-4"><label class="form-label small fw-semibold">Regions / states</label><input name="regions" class="form-control form-control-sm" value="{{ $csv('regions') }}" placeholder="Punjab"></div>
                    <div class="col-md-4"><label class="form-label small fw-semibold">Cities</label><input name="cities" class="form-control form-control-sm" value="{{ $csv('cities') }}" placeholder="Lahore, Islamabad"></div>
                    <div class="col-md-6"><label class="form-label small fw-semibold">Industries</label><input name="industries" class="form-control form-control-sm" value="{{ $csv('industries') }}" placeholder="Healthcare"></div>
                    <div class="col-md-6"><label class="form-label small fw-semibold">Niches (search terms)</label><input name="niches" class="form-control form-control-sm" value="{{ $csv('niches') }}" placeholder="dental clinic, dentist"></div>
                    <div class="col-md-6"><label class="form-label small fw-semibold">Company sizes</label><input name="company_sizes" class="form-control form-control-sm" value="{{ $csv('company_sizes') }}" placeholder="1-10, 11-50"></div>
                    <div class="col-md-6"><label class="form-label small fw-semibold">Business types</label>
                        <select name="business_types[]" multiple class="form-select form-select-sm" size="3">@foreach(\App\Models\IcpProfile::BUSINESS_TYPES as $k => $v)<option value="{{ $k }}" @selected(in_array($k, $sel('business_types')))>{{ $v }}</option>@endforeach</select></div>
                </div>
            </div>
            <div class="card-lp p-4 mb-4">
                <h6 class="fw-bold">2. Need & fit</h6>
                <div class="row g-3">
                    <div class="col-md-6"><label class="form-label small fw-semibold">Services to sell</label>
                        <select name="target_services[]" multiple class="form-select form-select-sm" size="6">@foreach($services as $k => $s)<option value="{{ $k }}" @selected(in_array($k, $sel('target_services')))>{{ $s['label'] }}</option>@endforeach</select></div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Website</label>
                        <select name="website_requirement" class="form-select form-select-sm mb-2">@foreach(\App\Models\IcpProfile::WEBSITE_REQUIREMENTS as $k => $v)<option value="{{ $k }}" @selected(old('website_requirement', $icp->website_requirement) === $k)>{{ $v }}</option>@endforeach</select>
                        <label class="form-label small fw-semibold">Tech must include</label><input name="tech_include" class="form-control form-control-sm mb-2" value="{{ $csv('tech_include') }}" placeholder="WordPress, jQuery">
                        <label class="form-label small fw-semibold">Tech must NOT include</label><input name="tech_exclude" class="form-control form-control-sm" value="{{ $csv('tech_exclude') }}" placeholder="Shopify">
                    </div>
                    <div class="col-md-6"><label class="form-label small fw-semibold">Pain points (used in messaging notes)</label><textarea name="pain_points" rows="2" class="form-control form-control-sm" placeholder="no online booking, poor mobile site">{{ $csv('pain_points') }}</textarea></div>
                    <div class="col-md-6"><label class="form-label small fw-semibold">Keywords / watchlist</label><textarea name="keywords" rows="2" class="form-control form-control-sm" placeholder="website redesign, greenhouse:acme, lever:globex|globex.com">{{ $csv('keywords') }}</textarea></div>
                    <div class="col-md-4"><label class="form-label small fw-semibold">Est. purchase ability</label><select name="min_purchase_ability" class="form-select form-select-sm"><option value="">Any</option>@foreach(['low','medium','high'] as $v)<option @selected(old('min_purchase_ability', $icp->min_purchase_ability) === $v)>{{ $v }}</option>@endforeach</select></div>
                    <div class="col-md-4"><label class="form-label small fw-semibold">Min Google rating</label><input type="number" step="0.1" min="0" max="5" name="min_google_rating" class="form-control form-control-sm" value="{{ old('min_google_rating', $icp->min_google_rating ? $icp->min_google_rating / 10 : '') }}"></div>
                    <div class="col-md-4"><label class="form-label small fw-semibold">Min reviews</label><input type="number" min="0" name="min_review_count" class="form-control form-control-sm" value="{{ old('min_review_count', $icp->min_review_count) }}"></div>
                </div>
            </div>
            <div class="card-lp p-4">
                <h6 class="fw-bold">3. Contacts, quality & exclusions</h6>
                <div class="row g-3">
                    <div class="col-md-6"><label class="form-label small fw-semibold">Preferred contact roles</label><input name="contact_roles" class="form-control form-control-sm" value="{{ $csv('contact_roles') }}" placeholder="Owner, Practice Manager, Marketing Manager"></div>
                    <div class="col-md-3"><label class="form-label small fw-semibold">Min lead score</label><input type="number" min="0" max="100" name="min_lead_score" class="form-control form-control-sm" value="{{ old('min_lead_score', $icp->min_lead_score) }}"></div>
                    <div class="col-md-3 d-flex align-items-end"><div class="form-check"><input type="checkbox" class="form-check-input" name="exclude_existing_customers" value="1" id="exc" {{ old('exclude_existing_customers', $icp->exclude_existing_customers) ? 'checked' : '' }}><label class="form-check-label small" for="exc">Exclude customers</label></div></div>
                    <div class="col-md-6"><label class="form-label small fw-semibold">Exclude domains</label><input name="exclude_domains" class="form-control form-control-sm" value="{{ $csv('exclude_domains') }}" placeholder="competitor.com"></div>
                    <div class="col-md-6"><label class="form-label small fw-semibold">Exclude keywords</label><input name="exclude_keywords" class="form-control form-control-sm" value="{{ $csv('exclude_keywords') }}" placeholder="hospital, franchise"></div>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card-lp p-4 mb-4">
                <h6 class="fw-bold">4. Sources & campaign</h6>
                <label class="form-label small fw-semibold">Discovery sources (empty = auto)</label>
                @foreach($adapters as $slug => $a)
                    <div class="form-check small"><input class="form-check-input" type="checkbox" name="sources[]" value="{{ $slug }}" id="s{{ $slug }}" @checked(in_array($slug, $sel('sources')))>
                        <label class="form-check-label" for="s{{ $slug }}">{{ $a->getSourceName() }} @unless($a->isConfigured())<span class="text-muted">(not configured)</span>@endunless</label></div>
                @endforeach
                <label class="form-label small fw-semibold mt-3">Channels</label>
                @foreach(['email' => 'Email', 'phone' => 'Phone call', 'linkedin_manual' => 'LinkedIn (manual)', 'whatsapp_manual' => 'WhatsApp (manual, opted-in only)'] as $k => $v)
                    <div class="form-check small"><input class="form-check-input" type="checkbox" name="channels[]" value="{{ $k }}" id="c{{ $k }}" @checked(in_array($k, $sel('channels')))><label class="form-check-label" for="c{{ $k }}">{{ $v }}</label></div>
                @endforeach
                <div class="row g-2 mt-2">
                    <div class="col-6"><label class="form-label small fw-semibold">Max results / run</label><input type="number" min="1" max="200" name="max_results_per_run" class="form-control form-control-sm" value="{{ old('max_results_per_run', $icp->max_results_per_run) }}"></div>
                    <div class="col-6"><label class="form-label small fw-semibold">Monthly budget ($)</label><input type="number" step="1" min="0" name="monthly_budget" class="form-control form-control-sm" value="{{ old('monthly_budget', $icp->monthly_budget_cents ? $icp->monthly_budget_cents / 100 : '') }}"></div>
                </div>
                <label class="form-label small fw-semibold mt-2">Objective</label><input name="objective" class="form-control form-control-sm" value="{{ old('objective', $icp->objective) }}" placeholder="5 discovery calls / month">
                <label class="form-label small fw-semibold mt-2">Notes</label><textarea name="description" rows="2" class="form-control form-control-sm">{{ old('description', $icp->description) }}</textarea>
            </div>
            <button class="btn btn-primary-lp w-100">Save profile</button>
        </div>
    </div>
</form>
@endsection
