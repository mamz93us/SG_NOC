@extends('layouts.admin')
@section('content')

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-file-earmark-spreadsheet me-2 text-primary"></i>Import MAC & Serial</h4>
        <small class="text-muted">Upload an Excel file to update or create devices with MAC addresses and serial numbers</small>
    </div>
    <a href="{{ route('admin.devices.index') }}" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i>Back to Devices
    </a>
</div>

@if(session('success'))
<div class="alert alert-success alert-dismissible fade show py-2" role="alert">
    {{ session('success') }}<button type="button" class="btn-close btn-sm" data-bs-dismiss="alert"></button>
</div>
@endif
@if(session('error'))
<div class="alert alert-danger alert-dismissible fade show py-2" role="alert">
    {{ session('error') }}<button type="button" class="btn-close btn-sm" data-bs-dismiss="alert"></button>
</div>
@endif

<div class="row g-4">
    {{-- Upload Form --}}
    <div class="col-lg-7">
        <div class="card shadow-sm">
            <div class="card-header bg-transparent"><strong>Upload File</strong></div>
            <div class="card-body">
                <form method="POST" action="{{ route('admin.devices.import.preview') }}" enctype="multipart/form-data">
                    @csrf
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Excel File <span class="text-danger">*</span></label>
                        <input type="file" name="file" class="form-control" accept=".xlsx,.xls,.csv" required>
                        @error('file')
                        <div class="text-danger small mt-1">{{ $message }}</div>
                        @enderror
                    </div>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-eye me-1"></i>Preview Import
                    </button>
                </form>
            </div>
        </div>
    </div>

    {{-- Instructions --}}
    <div class="col-lg-5">
        <div class="card shadow-sm border-info">
            <div class="card-header bg-transparent"><strong><i class="bi bi-info-circle me-1 text-info"></i>Instructions</strong></div>
            <div class="card-body small">
                <p class="mb-2">Your Excel file should have a <strong>header row</strong> with at least these columns:</p>
                <table class="table table-sm table-bordered mb-3">
                    <thead class="table-light">
                        <tr><th>Column</th><th>Example</th></tr>
                    </thead>
                    <tbody>
                        <tr><td><code>MAC</code> (or MAC Address)</td><td>EC:74:D7:80:04:74</td></tr>
                        <tr><td><code>Serial</code> (or Serial Number)</td><td>23G1A2B3C4D5</td></tr>
                    </tbody>
                </table>
                <p class="mb-2"><strong>What happens:</strong></p>
                <ul class="mb-0">
                    <li>If a device with the same MAC exists &rarr; <span class="text-primary fw-semibold">serial number is updated</span></li>
                    <li>If no device matches &rarr; <span class="text-success fw-semibold">a new device is created</span></li>
                    <li>MAC formats like <code>EC:74:D7:80:04:74</code>, <code>EC-74-D7-80-04-74</code>, or <code>ec74d7800474</code> are all accepted</li>
                    <li>You'll see a preview before anything is saved</li>
                </ul>
            </div>
        </div>
    </div>
</div>

@endsection
