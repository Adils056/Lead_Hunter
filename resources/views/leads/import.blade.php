@extends('layouts.app')

@section('title', 'Import Leads CSV')

@section('content')
<div class="row justify-content-center">
    <div class="col-lg-7">
        <div class="mb-4">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-2" style="font-size: 0.825rem;">
                    <li class="breadcrumb-item"><a href="{{ route('leads.index') }}">Leads</a></li>
                    <li class="breadcrumb-item active">Import CSV</li>
                </ol>
            </nav>
            <h3 class="fw-bold mb-1">Import Prospects via CSV</h3>
            <p class="text-muted small">Upload your contact list. In the next step, you will map your CSV columns to LeadPilot intelligence fields.</p>
        </div>

        <div class="card-lp p-4 mb-4">
            <form action="{{ route('leads.import.preview') }}" method="POST" enctype="multipart/form-data">
                @csrf
                <div class="p-4 border-2 border-dashed rounded text-center bg-light mb-4">
                    <i class="bi bi-file-earmark-spreadsheet fs-1 text-primary d-block mb-2"></i>
                    <label class="form-label fw-bold fs-6 mb-1">Select CSV Document</label>
                    <p class="text-muted small mb-3">Maximum file size: 5MB (.csv, .txt with header row)</p>
                    <input type="file" name="csv_file" class="form-control" accept=".csv, text/csv" required>
                </div>

                <div class="alert alert-info border-0 py-2 small d-flex gap-2">
                    <i class="bi bi-shield-check fs-5 text-info"></i>
                    <div>
                        <strong>Built-in Deduplication & Suppression:</strong> Existing emails and suppressed do-not-contact domains are automatically skipped to safeguard sender reputation.
                    </div>
                </div>

                <div class="d-flex justify-content-end gap-2 pt-2">
                    <a href="{{ route('leads.index') }}" class="btn btn-light border px-4">Cancel</a>
                    <button type="submit" class="btn btn-primary-lp px-4">
                        <i class="bi bi-arrow-right me-1"></i> Continue to Column Mapping
                    </button>
                </div>
            </form>
        </div>

        <!-- Sample CSV Format Guidance -->
        <div class="card-lp p-4">
            <h6 class="fw-bold mb-2">Recommended CSV Structure</h6>
            <p class="text-muted small mb-3">Any column names are supported as you can map them in the next step. A standard format:</p>
            <div class="bg-dark text-light p-3 rounded font-monospace small">
                Full Name,Email,Company,Job Title,Country,Industry,Website,Phone<br>
                Alexander Wright,awright@techcorp.com,TechCorp Global,CTO,United Kingdom,Financial Technology,https://techcorp.com,+44 20 7123 4567<br>
                Elena Rostova,elena@apexlogistics.pk,Apex Logistics,Head of IT,Pakistan,Logistics,https://apexlogistics.pk,+92 42 3578 9012
            </div>
        </div>
    </div>
</div>
@endsection
