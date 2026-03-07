@extends('layouts.admin')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="h3 mb-1">VPN Hub</h2>
        <p class="text-muted small mb-0">Central IPsec VPN Hub & Tunnel Orchestration</p>
    </div>
    <div class="d-flex gap-2">
        <form action="{{ route('admin.network.vpn.reload') }}" method="POST">
            @csrf
            <button type="submit" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-clockwise me-1"></i> Reload Config
            </button>
        </form>
        <a href="{{ route('admin.network.vpn.create') }}" class="btn btn-primary">
            <i class="bi bi-plus-lg me-1"></i> Add VPN Tunnel
        </a>
    </div>
</div>

<div class="card shadow-sm border-0">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="bg-light">
                    <tr>
                        <th class="ps-4">Branch</th>
                        <th>Tunnel Name</th>
                        <th>Remote IP</th>
                        <th>Subnets (Remote / Local)</th>
                        <th>Status</th>
                        <th>Last Checked</th>
                        <th class="text-end pe-4">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($tunnels as $tunnel)
                        <tr>
                            <td class="ps-4">
                                <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle">
                                    {{ $tunnel->branch->name }}
                                </span>
                            </td>
                            <td>
                                <div class="fw-bold">{{ $tunnel->name }}</div>
                            </td>
                            <td>
                                <code class="small">{{ $tunnel->remote_public_ip }}</code>
                            </td>
                            <td>
                                <div class="small">
                                    <span class="text-primary">{{ $tunnel->remote_subnet }}</span>
                                    <span class="text-muted mx-1">/</span>
                                    <span class="text-success">{{ $tunnel->local_subnet }}</span>
                                </div>
                            </td>
                            <td>
                                <div id="status-{{ $tunnel->id }}" class="d-flex align-items-center">
                                    <span class="spinner-border spinner-border-sm text-muted me-2" role="status"></span>
                                    <span class="text-muted small">Checking...</span>
                                </div>
                            </td>
                            <td>
                                <span class="text-muted small">
                                    {{ $tunnel->last_checked_at ? $tunnel->last_checked_at->diffForHumans() : 'Never' }}
                                </span>
                            </td>
                            <td class="text-end pe-4">
                                <div class="btn-group btn-group-sm">
                                    <form action="{{ route('admin.network.vpn.up', $tunnel) }}" method="POST" class="d-inline">
                                        @csrf
                                        <button type="submit" class="btn btn-outline-success" title="Initiate Tunnel">
                                            <i class="bi bi-play-fill"></i>
                                        </button>
                                    </form>
                                    <form action="{{ route('admin.network.vpn.down', $tunnel) }}" method="POST" class="d-inline">
                                        @csrf
                                        <button type="submit" class="btn btn-outline-danger" title="Terminate Tunnel">
                                            <i class="bi bi-stop-fill"></i>
                                        </button>
                                    </form>
                                    <button type="button" class="btn btn-outline-info" title="Troubleshoot" onclick="showTroubleshoot({{ $tunnel->id }})">
                                        <i class="bi bi-bug"></i>
                                    </button>
                                    <a href="{{ route('admin.network.vpn.edit', $tunnel) }}" class="btn btn-outline-primary" title="Edit">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <form action="{{ route('admin.network.vpn.destroy', $tunnel) }}" method="POST"
                                          class="d-inline"
                                          onsubmit="return confirm('Permanently delete this VPN tunnel?');">
                                        @csrf
                                        @method('DELETE')
                                        <button class="btn btn-outline-danger" title="Delete">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center py-5">
                                <div class="text-muted mb-3">
                                    <i class="bi bi-diagram-3 fs-1 d-block mb-3 opacity-25"></i>
                                    No VPN tunnels configured yet.
                                </div>
                                <a href="{{ route('admin.network.vpn.create') }}" class="btn btn-primary btn-sm">Add Your First Tunnel</a>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Troubleshooting Modal -->
<div class="modal fade" id="troubleshootModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">VPN Troubleshooting</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label fw-bold">System IPsec Logs (Last 50 lines)</label>
                    <pre id="ipsecLogs" class="bg-dark text-light p-3 small overflow-auto" style="max-height: 300px;">Loading logs...</pre>
                </div>
                <div id="saDetailsContainer" class="d-none">
                    <label class="form-label fw-bold">Security Association (SA) Details</label>
                    <pre id="saDetails" class="bg-light p-3 small border">Checking SA status...</pre>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" onclick="refreshTroubleshoot()">
                    <i class="bi bi-arrow-clockwise me-1"></i> Refresh
                </button>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
let currentTunnelId = null;

function showTroubleshoot(tunnelId) {
    currentTunnelId = tunnelId;
    const modal = new bootstrap.Modal(document.getElementById('troubleshootModal'));
    modal.show();
    refreshTroubleshoot();
}

function refreshTroubleshoot() {
    const logContainer = document.getElementById('ipsecLogs');
    const saContainer = document.getElementById('saDetails');
    const saWrapper = document.getElementById('saDetailsContainer');
    
    logContainer.textContent = 'Loading system logs...';
    
    // Fetch broad system logs with timeout
    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), 10000);

    fetch(`{{ route('admin.network.vpn.logs') }}`, { signal: controller.signal })
        .then(r => {
            if (!r.ok) throw new Error(`HTTP ${r.status}`);
            return r.json();
        })
        .then(data => {
            clearTimeout(timeoutId);
            logContainer.textContent = data.logs || 'No logs returned from server.';
        })
        .catch(err => {
            clearTimeout(timeoutId);
            logContainer.innerHTML = `<span class="text-danger">Failed to load logs: ${err.message}</span>\n\nPossible causes:\n1. Outdated script in /usr/local/bin\n2. Sudoers permissions missing for 'logs' action\n3. Web server connection error`;
        });

    if (currentTunnelId) {
        saWrapper.classList.remove('d-none');
        saContainer.textContent = 'Checking tunnel-specific SA status...';
        fetch(`{{ url('admin/network/vpn') }}/${currentTunnelId}/status`)
            .then(r => r.json())
            .then(data => {
                saContainer.textContent = data.raw_output || 'No specific SA info for this tunnel.';
            })
            .catch(err => {
                saContainer.textContent = 'Error checking tunnel status: ' + err.message;
            });
    }
}

document.addEventListener('DOMContentLoaded', function() {
    const tunnels = @json($tunnels->pluck('id'));

    window.fetchStatus = function(id) {
        const container = document.getElementById(`status-${id}`);
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), 8000);

        fetch(`{{ url('admin/network/vpn') }}/${id}/status`, { signal: controller.signal })
            .then(response => {
                clearTimeout(timeoutId);
                if (!response.ok) throw new Error(`HTTP ${response.status}`);
                return response.json();
            })
            .then(data => {
                if (data.is_up) {
                    container.innerHTML = `
                        <span class="badge rounded-circle bg-success p-1 me-2" style="width: 10px; height: 10px;"></span>
                        <span class="text-success small fw-bold" style="cursor:help" title="Click for details" onclick="showTroubleshoot(${id})">UP</span>
                    `;
                } else {
                    container.innerHTML = `
                        <span class="badge rounded-circle bg-danger p-1 me-2" style="width: 10px; height: 10px;"></span>
                        <span class="text-danger small fw-bold" style="cursor:help" title="Click for details" onclick="showTroubleshoot(${id})">DOWN</span>
                    `;
                }
            })
            .catch(error => {
                clearTimeout(timeoutId);
                container.innerHTML = `<span class="text-warning small fw-bold">Timeout</span>`;
            });
    };

    tunnels.forEach(id => {
        fetchStatus(id);
    });
});
</script>
@endpush
@endsection
