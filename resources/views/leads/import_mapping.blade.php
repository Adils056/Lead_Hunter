@extends('layouts.app')

@section('title', 'Map CSV Columns')

@section('content')
<div class="row justify-content-center">
    <div class="col-lg-9">
        <div class="mb-4">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-2" style="font-size: 0.825rem;">
                    <li class="breadcrumb-item"><a href="{{ route('leads.index') }}">Leads</a></li>
                    <li class="breadcrumb-item"><a href="{{ route('leads.import') }}">Import</a></li>
                    <li class="breadcrumb-item active">Column Mapping</li>
                </ol>
            </nav>
            <h3 class="fw-bold mb-1">Map CSV Columns</h3>
            <p class="text-muted small">Match each LeadPilot attribute with the corresponding header from your uploaded CSV file.</p>
        </div>

        <form action="{{ route('leads.import.process') }}" method="POST">
            @csrf

            <!-- Mapping Grid -->
            <div class="card-lp p-4 mb-4">
                <h6 class="fw-bold mb-3 border-bottom pb-2">Attribute Mapping</h6>

                @php
                    $fields = [
                        'full_name' => ['label' => 'Full Name', 'required' => true, 'hint' => 'e.g. John Doe'],
                        'email' => ['label' => 'Business Email', 'required' => true, 'hint' => 'e.g. jdoe@example.com'],
                        'company_name' => ['label' => 'Company Name', 'required' => false, 'hint' => 'Auto-creates company record'],
                        'job_title' => ['label' => 'Job Title / Role', 'required' => false, 'hint' => 'Used for decision-maker scoring'],
                        'country' => ['label' => 'Country', 'required' => false, 'hint' => 'Target market jurisdiction'],
                        'industry' => ['label' => 'Industry', 'required' => false, 'hint' => 'Industry vertical'],
                        'website' => ['label' => 'Company Website', 'required' => false, 'hint' => 'Used for domain deduplication'],
                        'phone' => ['label' => 'Phone Number', 'required' => false, 'hint' => 'Direct contact phone'],
                        'service' => ['label' => 'Primary Service Need', 'required' => false, 'hint' => 'Matching agency service'],
                    ];
                @endphp

                <div class="row g-3">
                    @foreach($fields as $key => $meta)
                    <div class="col-md-6">
                        <div class="p-3 rounded border bg-light-subtle h-100">
                            <label class="form-label small fw-semibold text-dark mb-1">
                                {{ $meta['label'] }}
                                @if($meta['required']) <span class="text-danger">*</span> @endif
                            </label>
                            <div class="text-muted small mb-2" style="font-size: 0.75rem;">{{ $meta['hint'] }}</div>

                            <select name="mapping[{{ $key }}]" class="form-select form-select-sm" {{ $meta['required'] ? 'required' : '' }}>
                                <option value="">-- Choose CSV Column --</option>
                                @foreach($headers as $h)
                                    @php
                                        // Auto-detect heuristic
                                        $normalizedHeader = strtolower(str_replace([' ', '_', '-'], '', $h));
                                        $normalizedKey = strtolower(str_replace([' ', '_', '-'], '', $key));
                                        $isSelected = ($normalizedHeader === $normalizedKey)
                                            || ($key === 'full_name' && in_array($normalizedHeader, ['name', 'fullname', 'contactname']))
                                            || ($key === 'company_name' && in_array($normalizedHeader, ['company', 'organization', 'account']))
                                            || ($key === 'job_title' && in_array($normalizedHeader, ['title', 'role', 'position']))
                                            || ($key === 'website' && in_array($normalizedHeader, ['domain', 'url', 'web']));
                                    @endphp
                                    <option value="{{ $h }}" {{ $isSelected ? 'selected' : '' }}>{{ $h }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    @endforeach
                </div>
            </div>

            <!-- CSV Row Sample Preview -->
            @if(!empty($rows))
            <div class="card-lp p-4 mb-4">
                <h6 class="fw-bold mb-3">CSV Data Preview (First {{ count($rows) }} rows)</h6>
                <div class="table-responsive">
                    <table class="table table-sm table-bordered small">
                        <thead class="bg-light">
                            <tr>
                                @foreach($headers as $h)
                                    <th>{{ $h }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($rows as $r)
                            <tr>
                                @foreach($headers as $h)
                                    <td>{{ $r[$h] ?? '' }}</td>
                                @endforeach
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
            @endif

            <div class="d-flex justify-content-between align-items-center">
                <a href="{{ route('leads.import') }}" class="btn btn-light border px-4">Back to Upload</a>
                <button type="submit" class="btn btn-primary-lp px-4">
                    <i class="bi bi-cloud-arrow-up me-1"></i> Start Import Process
                </button>
            </div>
        </form>
    </div>
</div>
@endsection
