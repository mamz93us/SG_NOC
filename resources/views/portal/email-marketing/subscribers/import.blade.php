@extends('layouts.portal')

@section('title', 'Import subscribers')

@section('content')
<div class="container-fluid py-4">
    <h3 class="mb-3"><i class="bi bi-envelope-paper me-2"></i>Email Marketing</h3>
    @include('portal.email-marketing._nav')

    @if (session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif
    @if ($errors->any())
        <div class="alert alert-danger"><ul class="mb-0">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
    @endif

    @if (empty($storedPath ?? null))
        <div class="alert alert-info d-flex justify-content-between align-items-center mb-3">
            <div>
                <i class="bi bi-info-circle me-1"></i>
                <strong>Not sure of the format?</strong>
                Download our template, fill it in with your subscribers, then upload it below.
            </div>
            <a href="{{ route('portal.marketing.subscribers.import.template') }}"
               class="btn btn-outline-primary btn-sm">
                <i class="bi bi-download me-1"></i>Download CSV template
            </a>
        </div>

        <form class="card shadow-sm" method="POST" action="{{ route('portal.marketing.subscribers.import.map') }}" enctype="multipart/form-data">
            @csrf
            <div class="card-body">
                <h5 class="mb-3"><i class="bi bi-upload me-2"></i>Step 1 — Upload a CSV or XLSX</h5>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Destination list</label>
                        <select name="email_list_id" class="form-select" required>
                            <option value="">Select list…</option>
                            @foreach ($lists as $l)
                                <option value="{{ $l->id }}">{{ $l->name }}{{ $l->double_opt_in ? ' (double opt-in)' : '' }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">File</label>
                        <input type="file" name="file" class="form-control" accept=".csv,.txt,.xlsx,.xls" required>
                        <small class="text-muted">
                            Accepted: CSV, XLSX, XLS. Up to 20 MB.
                            Expected columns: <code>email</code>, <code>first_name</code>, <code>last_name</code>
                            (only <code>email</code> is required).
                        </small>
                    </div>
                </div>
            </div>
            <div class="card-footer d-flex justify-content-end">
                <button class="btn btn-primary">Continue → Map columns</button>
            </div>
        </form>
    @else
        <form class="card shadow-sm" method="POST" action="{{ route('portal.marketing.subscribers.import.store') }}">
            @csrf
            <input type="hidden" name="stored_path" value="{{ $storedPath }}">
            <input type="hidden" name="email_list_id" value="{{ $email_list_id }}">
            <div class="card-body">
                <h5 class="mb-3"><i class="bi bi-diagram-2 me-2"></i>Step 2 — Map columns</h5>
                <div class="alert alert-info">
                    Detected {{ count($headers) }} columns. Choose which holds the email address (required).
                </div>
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Email column</label>
                        <select name="email_col" class="form-select" required>
                            @foreach ($headers as $i => $h)
                                <option value="{{ $i }}" @selected(stripos((string) $h, 'email') !== false)>#{{ $i }} — {{ $h }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">First name column (optional)</label>
                        <select name="first_name_col" class="form-select">
                            <option value="">— none —</option>
                            @foreach ($headers as $i => $h)
                                <option value="{{ $i }}" @selected(stripos((string) $h, 'first') !== false)>#{{ $i }} — {{ $h }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Last name column (optional)</label>
                        <select name="last_name_col" class="form-select">
                            <option value="">— none —</option>
                            @foreach ($headers as $i => $h)
                                <option value="{{ $i }}" @selected(stripos((string) $h, 'last') !== false)>#{{ $i }} — {{ $h }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="form-check mt-3">
                    <input type="checkbox" id="skip_header" name="skip_header" value="1" class="form-check-input" checked>
                    <label class="form-check-label" for="skip_header">First row contains column headers (skip it)</label>
                </div>
            </div>
            <div class="card-footer d-flex justify-content-end">
                <a href="{{ route('portal.marketing.subscribers.import.form') }}" class="btn btn-link">Cancel</a>
                <button class="btn btn-primary"><i class="bi bi-cloud-upload me-1"></i>Import</button>
            </div>
        </form>
    @endif
</div>
@endsection
