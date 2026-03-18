@extends('layouts.admin')
@section('content')

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-file-earmark-spreadsheet me-2 text-primary"></i>Import & Add Devices</h4>
        <small class="text-muted">Upload an Excel file, paste batch data, or add devices manually</small>
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

{{-- Tabs --}}
<ul class="nav nav-tabs mb-3" role="tablist">
    <li class="nav-item">
        <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-excel" type="button">
            <i class="bi bi-file-earmark-excel me-1"></i>Excel Import
        </button>
    </li>
    <li class="nav-item">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-manual" type="button">
            <i class="bi bi-plus-circle me-1"></i>Manual Add
        </button>
    </li>
    <li class="nav-item">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-batch" type="button">
            <i class="bi bi-list-ul me-1"></i>Batch Add
        </button>
    </li>
</ul>

<div class="tab-content">
    {{-- TAB 1: Excel Import --}}
    <div class="tab-pane fade show active" id="tab-excel">
        <div class="row g-4">
            <div class="col-lg-7">
                <div class="card shadow-sm">
                    <div class="card-header bg-transparent"><strong>Upload Excel File</strong></div>
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
            <div class="col-lg-5">
                <div class="card shadow-sm border-info">
                    <div class="card-header bg-transparent"><strong><i class="bi bi-info-circle me-1 text-info"></i>Instructions</strong></div>
                    <div class="card-body small">
                        <p class="mb-2">Your Excel file should have a <strong>header row</strong> with these columns:</p>
                        <table class="table table-sm table-bordered mb-3">
                            <thead class="table-light">
                                <tr><th>Column</th><th>Required</th><th>Example</th></tr>
                            </thead>
                            <tbody>
                                <tr><td><code>MAC</code></td><td><span class="badge bg-warning text-dark">MAC or IP</span></td><td>EC:74:D7:80:04:74</td></tr>
                                <tr><td><code>Serial</code></td><td><span class="badge bg-secondary">Optional</span></td><td>353015D8DC</td></tr>
                                <tr><td><code>Model</code></td><td><span class="badge bg-secondary">Optional</span></td><td>GRP2614</td></tr>
                                <tr><td><code>IP</code></td><td><span class="badge bg-warning text-dark">MAC or IP</span></td><td>10.1.8.140</td></tr>
                            </tbody>
                        </table>
                        <ul class="mb-0 small">
                            <li>At least <strong>MAC</strong> or <strong>IP</strong> column is required</li>
                            <li>MAC exists → <span class="text-primary fw-semibold">serial &amp; model updated</span></li>
                            <li>MAC new → <span class="text-success fw-semibold">device created</span></li>
                            <li>If MAC not found, IP is used as fallback to match devices</li>
                            <li>All MAC formats accepted (colons, dashes, or plain)</li>
                            <li>You'll see a preview before anything is saved</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- TAB 2: Manual Add (single) --}}
    <div class="tab-pane fade" id="tab-manual">
        <div class="row g-4">
            <div class="col-lg-6">
                <div class="card shadow-sm">
                    <div class="card-header bg-transparent"><strong>Add Single Device</strong></div>
                    <div class="card-body">
                        <form method="POST" action="{{ route('admin.devices.import.manual') }}">
                            @csrf
                            <div class="mb-3">
                                <label class="form-label fw-semibold">MAC Address</label>
                                <input type="text" name="mac_address" class="form-control font-monospace"
                                       placeholder="EC:74:D7:80:04:74" value="{{ old('mac_address') }}">
                                <div class="form-text">Any format: colons, dashes, or plain hex</div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-semibold">IP Address</label>
                                <input type="text" name="ip_address" class="form-control font-monospace"
                                       placeholder="10.1.8.140" value="{{ old('ip_address') }}">
                                <div class="form-text">Local/private IP (e.g. 10.x.x.x). Used as fallback if MAC not provided</div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Serial Number</label>
                                <input type="text" name="serial_number" class="form-control"
                                       placeholder="353015D8DC" value="{{ old('serial_number') }}">
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Model</label>
                                <input type="text" name="model" class="form-control"
                                       placeholder="GRP2614" value="{{ old('model') }}">
                            </div>
                            <button type="submit" class="btn btn-success">
                                <i class="bi bi-plus-lg me-1"></i>Add Device
                            </button>
                        </form>
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="card shadow-sm border-info">
                    <div class="card-header bg-transparent"><strong><i class="bi bi-info-circle me-1 text-info"></i>Info</strong></div>
                    <div class="card-body small">
                        <ul class="mb-0">
                            <li>Provide at least a <strong>MAC address</strong> or <strong>IP address</strong></li>
                            <li>If a device with this MAC/IP exists, its serial and model will be <strong>updated</strong></li>
                            <li>Otherwise a new device will be <strong>created</strong></li>
                            <li>MAC addresses are stored as lowercase hex without separators</li>
                            <li>If both MAC and IP provided, MAC is used first for matching</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- TAB 3: Batch Add (paste) --}}
    <div class="tab-pane fade" id="tab-batch">
        <div class="row g-4">
            <div class="col-lg-7">
                <div class="card shadow-sm">
                    <div class="card-header bg-transparent"><strong>Batch Add Devices</strong></div>
                    <div class="card-body">
                        <form method="POST" action="{{ route('admin.devices.import.batch') }}">
                            @csrf
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Paste Data <span class="text-danger">*</span></label>
                                <textarea name="batch_data" class="form-control font-monospace" rows="12"
                                          placeholder="EC:74:D7:80:03:94  353015D8DC  GRP2614  10.1.8.140
EC:74:D7:80:04:74  3530155BC9  GRP2614
                                 35205G19B0  GRP2612  10.1.8.142" required>{{ old('batch_data') }}</textarea>
                            </div>
                            <button type="submit" class="btn btn-success">
                                <i class="bi bi-check-lg me-1"></i>Process Batch
                            </button>
                        </form>
                    </div>
                </div>
            </div>
            <div class="col-lg-5">
                <div class="card shadow-sm border-info">
                    <div class="card-header bg-transparent"><strong><i class="bi bi-info-circle me-1 text-info"></i>Format</strong></div>
                    <div class="card-body small">
                        <p class="mb-2">One device per line. Columns separated by <strong>tab</strong>, <strong>comma</strong>, <strong>semicolon</strong>, or <strong>2+ spaces</strong>:</p>
                        <pre class="bg-light p-2 rounded small mb-2">MAC_ADDRESS    SERIAL    MODEL    IP
EC:74:D7:80:03:94  353015D8DC  GRP2614  10.1.8.140
EC:74:D7:80:04:74  3530155BC9
               35205G19B0  GRP2612  10.1.8.142</pre>
                        <ul class="mb-0">
                            <li><strong>Column 1</strong>: MAC address (or empty if IP provided)</li>
                            <li><strong>Column 2</strong>: Serial number (optional)</li>
                            <li><strong>Column 3</strong>: Model (optional)</li>
                            <li><strong>Column 4</strong>: IP address (optional, used if MAC missing)</li>
                            <li>Existing devices matched by MAC first, then IP fallback</li>
                            <li>No header row needed — just paste the data</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

@endsection
