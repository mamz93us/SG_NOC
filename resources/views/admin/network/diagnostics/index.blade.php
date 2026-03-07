@extends('layouts.admin')

@section('content')
<div class="mb-4">
    <h2 class="h3 mb-1">Network Diagnostics</h2>
    <p class="text-muted small">Real-time connectivity tests and latency analysis.</p>
</div>

<div class="row g-4 mb-4">
    <!-- Quick Ping Tool -->
    <div class="col-md-6">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-white py-3">
                <h5 class="card-title mb-0 d-flex align-items-center">
                    <i class="bi bi-broadcast me-2 text-primary"></i> ICMP Ping
                </h5>
            </div>
            <div class="card-body">
                <div class="input-group mb-3">
                    <input type="text" id="ping-host" class="form-control" placeholder="IP Address or Hostname">
                    <button class="btn btn-primary px-4" type="button" id="btn-ping">Ping</button>
                </div>
                <div id="ping-result" class="mt-3 p-3 bg-light rounded d-none">
                    <div class="d-flex justify-content-between mb-2">
                        <span class="fw-bold">Result:</span>
                        <span id="ping-status"></span>
                    </div>
                    <div class="small text-muted" id="ping-stats"></div>
                    <pre id="ping-output" class="small mt-2 mb-0 bg-dark text-white p-2 rounded" style="max-height: 150px; overflow-y: auto;"></pre>
                </div>
            </div>
        </div>
    </div>

    <!-- Quick TCP Check Tool -->
    <div class="col-md-6">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-white py-3">
                <h5 class="card-title mb-0 d-flex align-items-center">
                    <i class="bi bi-door-open me-2 text-success"></i> TCP Port Check
                </h5>
            </div>
            <div class="card-body">
                <div class="row g-2">
                    <div class="col-8">
                        <input type="text" id="tcp-host" class="form-control" placeholder="Target IP/Host">
                    </div>
                    <div class="col-4">
                        <input type="number" id="tcp-port" class="form-control" placeholder="Port" value="80">
                    </div>
                    <div class="col-12 mt-2">
                        <button class="btn btn-success w-100" type="button" id="btn-tcp">Check Port</button>
                    </div>
                </div>
                <div id="tcp-result" class="mt-3 p-3 bg-light rounded d-none">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <span class="fw-bold d-block">Status:</span>
                            <span id="tcp-status" class="small"></span>
                        </div>
                        <div class="text-end">
                            <span class="fw-bold d-block">Latency:</span>
                            <span id="tcp-latency" class="small"></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Monitored Hosts Activity -->
<div class="card shadow-sm border-0">
    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0">Monitored Hosts</h5>
        <a href="{{ route('admin.network.monitoring.index') }}" class="btn btn-sm btn-link text-decoration-none p-0">Manage Hosts</a>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="bg-light">
                    <tr>
                        <th class="ps-4">Host Name</th>
                        <th>IP Address</th>
                        <th>Type</th>
                        <th>Last Status</th>
                        <th class="text-end pe-4">Quick Action</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($hosts as $host)
                        <tr>
                            <td class="ps-4">
                                <div class="fw-bold">{{ $host->name }}</div>
                                <span class="small text-muted">{{ $host->branch?->name ?? 'Standalone' }}</span>
                            </td>
                            <td>
                                <code class="small">{{ $host->ip }}</code>
                            </td>
                            <td>
                                <span class="badge bg-light text-dark border">{{ strtoupper($host->type) }}</span>
                            </td>
                            <td>
                                <span class="badge bg-{{ $host->status == 'up' ? 'success' : 'danger' }}-subtle text-{{ $host->status == 'up' ? 'success' : 'danger' }} border border-{{ $host->status == 'up' ? 'success' : 'danger' }}-subtle">
                                    {{ strtoupper($host->status) }}
                                </span>
                            </td>
                            <td class="text-end pe-4">
                                <button type="button" class="btn btn-sm btn-outline-primary btn-quick-ping" data-ip="{{ $host->ip }}">
                                    <i class="bi bi-lightning-fill"></i> Ping
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center py-4 text-muted small">No monitored hosts available.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    const btnPing = document.getElementById('btn-ping');
    const btnTcp = document.getElementById('btn-tcp');

    // Ping Functionality
    btnPing.addEventListener('click', function() {
        const host = document.getElementById('ping-host').value;
        if (!host) return;

        showLoading('ping');
        fetch("{{ route('admin.network.diagnostics.ping') }}", {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': '{{ csrf_token() }}'
            },
            body: JSON.stringify({ host: host })
        })
        .then(response => response.json())
        .then(data => {
            const resultBox = document.getElementById('ping-result');
            const statusLabel = document.getElementById('ping-status');
            const statsLabel = document.getElementById('ping-stats');
            const outputBox = document.getElementById('ping-output');

            resultBox.classList.remove('d-none');
            if (data.success) {
                statusLabel.innerHTML = '<span class="badge bg-success">REACHABLE</span>';
                statsLabel.innerHTML = `Latency: <strong>${data.latency}ms</strong> | Loss: <strong>${data.packet_loss}%</strong>`;
            } else {
                statusLabel.innerHTML = '<span class="badge bg-danger">UNREACHABLE</span>';
                statsLabel.innerHTML = 'Request timed out or host unknown.';
            }
            outputBox.textContent = data.output;
            hideLoading('ping');
        });
    });

    // TCP Check Functionality
    btnTcp.addEventListener('click', function() {
        const host = document.getElementById('tcp-host').value;
        const port = document.getElementById('tcp-port').value;
        if (!host || !port) return;

        showLoading('tcp');
        fetch("{{ route('admin.network.diagnostics.tcp-check') }}", {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': '{{ csrf_token() }}'
            },
            body: JSON.stringify({ host: host, port: port })
        })
        .then(response => response.json())
        .then(data => {
            const resultBox = document.getElementById('tcp-result');
            const statusLabel = document.getElementById('tcp-status');
            const latencyLabel = document.getElementById('tcp-latency');

            resultBox.classList.remove('d-none');
            if (data.success) {
                statusLabel.innerHTML = '<span class="text-success fw-bold">OPEN</span>';
                latencyLabel.textContent = `${data.latency}ms`;
            } else {
                statusLabel.innerHTML = '<span class="text-danger fw-bold">CLOSED / TIMEOUT</span>';
                latencyLabel.textContent = 'N/A';
            }
            hideLoading('tcp');
        });
    });

    // Quick Ping from Table
    document.querySelectorAll('.btn-quick-ping').forEach(btn => {
        btn.addEventListener('click', function() {
            const ip = this.getAttribute('data-ip');
            document.getElementById('ping-host').value = ip;
            btnPing.click();
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });
    });

    function showLoading(type) {
        if (type === 'ping') {
            btnPing.disabled = true;
            btnPing.innerHTML = '<span class="spinner-border spinner-border-sm" role="status"></span>';
        } else {
            btnTcp.disabled = true;
            btnTcp.innerHTML = '<span class="spinner-border spinner-border-sm" role="status"></span> Checking...';
        }
    }

    function hideLoading(type) {
        if (type === 'ping') {
            btnPing.disabled = false;
            btnPing.innerHTML = 'Ping';
        } else {
            btnTcp.disabled = false;
            btnTcp.innerHTML = 'Check Port';
        }
    }
});
</script>
@endpush
@endsection
