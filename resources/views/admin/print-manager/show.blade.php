@extends('layouts.admin')

@section('content')
<div class="mb-4">
    <a href="{{ route('admin.print-manager.index') }}" class="text-decoration-none">
        <i class="bi bi-arrow-left me-1"></i>Back to Print Manager
    </a>
</div>

<div class="d-flex justify-content-between align-items-start mb-4">
    <div>
        <h1 class="h3 mb-1">{{ $cupsPrinter->name }}</h1>
        <span class="badge {{ $cupsPrinter->statusBadgeClass() }} me-1">{{ ucfirst($cupsPrinter->status) }}</span>
        @unless($cupsPrinter->is_active)
            <span class="badge bg-warning text-dark">Disabled</span>
        @endunless
        @if($cupsPrinter->is_shared)
            <span class="badge bg-info">Shared</span>
        @endif
    </div>
    @can('manage-print-manager')
    <div class="d-flex gap-2">
        <form action="{{ route('admin.print-manager.refresh', $cupsPrinter) }}" method="POST">
            @csrf
            <button type="submit" class="btn btn-sm btn-outline-primary" title="Refresh Status">
                <i class="bi bi-arrow-clockwise me-1"></i>Refresh
            </button>
        </form>
        <form action="{{ route('admin.print-manager.test', $cupsPrinter) }}" method="POST">
            @csrf
            <button type="submit" class="btn btn-sm btn-outline-success" title="Send Test Page"
                    onclick="return confirm('Send a test page to this printer?');">
                <i class="bi bi-printer me-1"></i>Test Print
            </button>
        </form>
        <button type="button" class="btn btn-sm btn-outline-info" data-bs-toggle="modal" data-bs-target="#sendSetupModal">
            <i class="bi bi-envelope me-1"></i>Send Setup Email
        </button>
        <a href="{{ route('admin.print-manager.edit', $cupsPrinter) }}" class="btn btn-sm btn-outline-warning">
            <i class="bi bi-pencil me-1"></i>Edit
        </a>
        <form action="{{ route('admin.print-manager.destroy', $cupsPrinter) }}" method="POST">
            @csrf
            @method('DELETE')
            <button type="submit" class="btn btn-sm btn-outline-danger"
                    onclick="return confirm('Remove this printer from CUPS and database?');">
                <i class="bi bi-trash me-1"></i>Delete
            </button>
        </form>
    </div>
    @endcan
</div>

<div class="row g-4">
    {{-- Printer Details --}}
    <div class="col-lg-6">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-transparent"><strong>Printer Details</strong></div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <tbody>
                        <tr><th class="ps-3" style="width:40%">Queue Name</th><td><code>{{ $cupsPrinter->queue_name }}</code></td></tr>
                        <tr><th class="ps-3">Target IP</th><td>{{ $cupsPrinter->ip_address }}:{{ $cupsPrinter->port }}</td></tr>
                        <tr><th class="ps-3">Protocol</th><td class="text-uppercase">{{ $cupsPrinter->protocol }}</td></tr>
                        @if(in_array($cupsPrinter->protocol, ['ipp', 'ipps']))
                        <tr><th class="ps-3">IPP Path</th><td><code>{{ $cupsPrinter->ipp_path }}</code></td></tr>
                        @endif
                        <tr><th class="ps-3">CUPS URI</th><td><code class="small">{{ $cupsPrinter->getCupsUri() }}</code></td></tr>
                        <tr><th class="ps-3">Driver</th><td>{{ $cupsPrinter->driver }}</td></tr>
                        <tr><th class="ps-3">Branch</th><td>{{ $cupsPrinter->branch?->name ?? '—' }}</td></tr>
                        <tr><th class="ps-3">Location</th><td>{{ $cupsPrinter->location ?? '—' }}</td></tr>
                        <tr><th class="ps-3">Last Checked</th><td>{{ $cupsPrinter->last_checked_at?->diffForHumans() ?? '—' }}</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- Client Connection Info --}}
    <div class="col-lg-6">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-transparent"><strong><i class="bi bi-link-45deg me-1"></i>Client Connection</strong></div>
            <div class="card-body">
                <p class="text-muted small mb-2">Use this address to add the printer on any device:</p>
                <div class="bg-dark text-light rounded p-3 font-monospace mb-3 position-relative">
                    <span id="ipp-address">{{ $cupsPrinter->getIppAddress() }}</span>
                    <button type="button" class="btn btn-sm btn-outline-light position-absolute top-50 end-0 translate-middle-y me-2"
                            onclick="navigator.clipboard.writeText(document.getElementById('ipp-address').textContent).then(()=>this.innerHTML='<i class=\'bi bi-check\'></i> Copied')">
                        <i class="bi bi-clipboard"></i>
                    </button>
                </div>

                <h6 class="fw-semibold mt-3 mb-2"><i class="bi bi-apple me-1"></i>AirPrint (iPhone / iPad)</h6>
                <div class="row align-items-start g-3 mb-3">
                    <div class="col-auto">
                        <div id="airprint-qr" class="border rounded bg-white p-2 d-inline-block"></div>
                    </div>
                    <div class="col">
                        <p class="text-muted small mb-2">
                            Scan this QR code with your iPhone camera to install the AirPrint profile.
                            The printer will appear automatically in the print menu.
                        </p>
                        <a href="{{ route('admin.print-manager.airprint', $cupsPrinter) }}"
                           class="btn btn-dark btn-sm">
                            <i class="bi bi-download me-1"></i>Download .mobileconfig
                        </a>
                        <div class="mt-2">
                            <span class="badge bg-light text-dark border small">
                                <i class="bi bi-info-circle me-1"></i>Settings &rarr; General &rarr; VPN &amp; Device Management &rarr; Install
                            </span>
                        </div>
                    </div>
                </div>

                <h6 class="fw-semibold mt-3 mb-2">Manual Setup</h6>
                <div class="accordion accordion-flush" id="setupGuide">
                    <div class="accordion-item">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed py-2" type="button" data-bs-toggle="collapse" data-bs-target="#setupWindows">
                                <i class="bi bi-windows me-2"></i>Windows
                            </button>
                        </h2>
                        <div id="setupWindows" class="accordion-collapse collapse" data-bs-parent="#setupGuide">
                            <div class="accordion-body small">
                                <ol class="mb-0">
                                    <li>Settings &rarr; Printers &amp; Scanners &rarr; Add a printer</li>
                                    <li>"The printer I want isn't listed" &rarr; "Select a shared printer by name"</li>
                                    <li>Enter: <code>{{ str_replace('ipp://', 'http://', $cupsPrinter->getIppAddress()) }}</code></li>
                                </ol>
                            </div>
                        </div>
                    </div>
                    <div class="accordion-item">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed py-2" type="button" data-bs-toggle="collapse" data-bs-target="#setupMac">
                                <i class="bi bi-apple me-2"></i>macOS / iOS
                            </button>
                        </h2>
                        <div id="setupMac" class="accordion-collapse collapse" data-bs-parent="#setupGuide">
                            <div class="accordion-body small">
                                <p class="fw-semibold mb-1">iPhone / iPad (easiest):</p>
                                <ol class="mb-2">
                                    <li>Tap the "Download AirPrint Profile" button above from your device</li>
                                    <li>Go to Settings &rarr; General &rarr; VPN &amp; Device Management</li>
                                    <li>Tap the downloaded profile &rarr; Install</li>
                                    <li>The printer will appear automatically when you tap Print in any app</li>
                                </ol>
                                <p class="fw-semibold mb-1">macOS:</p>
                                <ol class="mb-0">
                                    <li>System Settings &rarr; Printers &amp; Scanners &rarr; Add Printer</li>
                                    <li>Enter address: <code>{{ $cupsPrinter->getIppAddress() }}</code></li>
                                </ol>
                            </div>
                        </div>
                    </div>
                    <div class="accordion-item">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed py-2" type="button" data-bs-toggle="collapse" data-bs-target="#setupAndroid">
                                <i class="bi bi-phone me-2"></i>Android
                            </button>
                        </h2>
                        <div id="setupAndroid" class="accordion-collapse collapse" data-bs-parent="#setupGuide">
                            <div class="accordion-body small">
                                <ol class="mb-0">
                                    <li>Settings &rarr; Connected Devices &rarr; Printing</li>
                                    <li>Add printer service &rarr; enter URL:</li>
                                    <li><code>{{ $cupsPrinter->getIppAddress() }}</code></li>
                                </ol>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- CUPS Queue Output --}}
@if(!empty($cupsJobs))
<div class="card shadow-sm border-0 mt-4">
    <div class="card-header bg-transparent"><strong>CUPS Queue (Live)</strong></div>
    <div class="card-body">
        <pre class="mb-0 small bg-light p-3 rounded">@foreach($cupsJobs as $line){{ $line }}
@endforeach</pre>
    </div>
</div>
@endif

{{-- Print Jobs History --}}
<div class="card shadow-sm border-0 mt-4">
    <div class="card-header bg-transparent d-flex justify-content-between align-items-center">
        <strong>Print Jobs</strong>
        <div class="d-flex align-items-center gap-2">
            @can('manage-print-manager')
            <form action="{{ route('admin.print-manager.sync-jobs', $cupsPrinter) }}" method="POST" class="d-inline">
                @csrf
                <button type="submit" class="btn btn-sm btn-outline-primary">
                    <i class="bi bi-arrow-repeat me-1"></i>Sync from CUPS
                </button>
            </form>
            @endcan
            <span class="badge bg-secondary">{{ $jobs->total() }} total</span>
        </div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>ID</th>
                        <th>CUPS Job</th>
                        <th>Title</th>
                        <th>User</th>
                        <th>Status</th>
                        <th>Pages</th>
                        <th>Submitted</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($jobs as $job)
                    <tr>
                        <td>{{ $job->id }}</td>
                        <td>{{ $job->cups_job_id ?? '—' }}</td>
                        <td>{{ $job->title ?? '—' }}</td>
                        <td>{{ $job->user?->name ?? '—' }}</td>
                        <td><span class="badge {{ $job->statusBadgeClass() }}">{{ ucfirst($job->status) }}</span></td>
                        <td>{{ $job->pages ?? '—' }}</td>
                        <td>{{ $job->created_at->diffForHumans() }}</td>
                        <td class="text-end">
                            @can('manage-print-manager')
                            @if(in_array($job->status, ['pending', 'processing']))
                            <form action="{{ route('admin.print-manager.cancel-job', [$cupsPrinter, $job]) }}" method="POST" class="d-inline">
                                @csrf
                                <button type="submit" class="btn btn-sm btn-outline-danger"
                                        onclick="return confirm('Cancel this print job?');">
                                    <i class="bi bi-x-circle"></i> Cancel
                                </button>
                            </form>
                            @endif
                            @endcan
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="8" class="text-center text-muted py-3">No print jobs recorded yet.</td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

{{ $jobs->links('pagination::bootstrap-5') }}

@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const qrEl = document.getElementById('airprint-qr');
    if (qrEl) {
        new QRCode(qrEl, {
            text: @json(route('admin.print-manager.airprint', $cupsPrinter)),
            width: 160,
            height: 160,
            correctLevel: QRCode.CorrectLevel.M,
        });
    }
});
</script>
@endpush
