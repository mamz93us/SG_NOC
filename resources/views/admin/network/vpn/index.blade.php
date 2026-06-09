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
            if (data.status === 'unavailable') {
                logContainer.innerHTML = `<span class="text-warning">${data.logs || 'swanctl service unavailable.'}</span>`;
            } else {
                logContainer.textContent = data.logs || 'No logs returned from server.';
            }
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
                if (data.swanctl_available === false) {
                let msg = '⚠️ swanctl is not responding — last known status: ' + (data.last_known_status || 'unknown').toUpperCase() + '\n\n';
                msg += data.raw_output || '';
                if (data.sophosVpn) {
                    msg += '\n\n📡 Sophos Firewall reports:\n';
                    msg += `  Tunnel: ${data.sophosVpn.name}\n`;
                    msg += `  Status: ${(data.sophosVpn.status || 'unknown').toUpperCase()}\n`;
                    msg += `  Remote GW: ${data.sophosVpn.remote_gateway || 'N/A'}\n`;
                    msg += `  Checked: ${data.sophosVpn.last_checked || 'N/A'}`;
                }
                saContainer.textContent = msg;
            } else {
                const esc = s => String(s).replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
                const kb  = b => (b == null ? '—' : Math.round(b / 1024).toLocaleString() + ' KB');
                let html = '';
                if (Array.isArray(data.children) && data.children.length) {
                    html += '<div class="small text-muted mb-1">Child SAs (per subnet pair):</div>';
                    html += '<table class="table table-sm table-bordered mb-2 small align-middle">'
                          + '<thead><tr><th>Local (NOC)</th><th>Remote (branch)</th><th>Status</th><th class="text-end">In / Out</th></tr></thead><tbody>';
                    data.children.forEach(c => {
                        const dot = c.up
                            ? '<span class="badge rounded-circle bg-success p-1 me-1" style="width:9px;height:9px;"></span><span class="text-success fw-bold">UP</span>'
                            : '<span class="badge rounded-circle bg-danger p-1 me-1" style="width:9px;height:9px;"></span><span class="text-danger fw-bold">DOWN</span>';
                        html += `<tr><td class="font-monospace">${esc(c.local_ts)}</td>`
                              + `<td class="font-monospace">${esc(c.remote_ts)}</td>`
                              + `<td>${dot}</td>`
                              + `<td class="text-end text-muted">${kb(c.bytes_in)} / ${kb(c.bytes_out)}</td></tr>`;
                    });
                    html += '</tbody></table>';
                }
                html += '<pre class="small mb-0" style="white-space:pre-wrap;word-break:break-all;">'
                      + esc(data.raw_output || 'No specific SA info for this tunnel.') + '</pre>';
                saContainer.innerHTML = html;
            }
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
                if (data.swanctl_available === false) {
                    // swanctl not responding — show last known status with warning
                    const lastStatus = (data.last_known_status || 'unknown').toUpperCase();
                    const colorClass = lastStatus === 'UP' ? 'text-success' : (lastStatus === 'DOWN' ? 'text-danger' : 'text-secondary');
                    container.innerHTML = `
                        <span class="badge rounded-circle bg-warning p-1 me-1" style="width:10px;height:10px;" title="swanctl not responding"></span>
                        <span class="${colorClass} small fw-bold" style="cursor:help"
                              title="swanctl unavailable — last known: ${lastStatus}"
                              onclick="showTroubleshoot(${id})">${lastStatus}*</span>
                        <i class="bi bi-exclamation-triangle text-warning ms-1" title="swanctl not responding"></i>
                    `;
                } else {
                    // Per-child aware: green only if ALL children are up,
                    // ORANGE if some are up (partial), red if none.
                    const kids  = Array.isArray(data.children) ? data.children : [];
                    const total = kids.length;
                    const upN   = kids.filter(c => c.up).length;

                    let dotClass, txtClass, label, title;
                    if (total === 0) {
                        // No child detail — fall back to the IKE-level flag.
                        dotClass = data.is_up ? 'bg-success' : 'bg-danger';
                        txtClass = data.is_up ? 'text-success' : 'text-danger';
                        label    = data.is_up ? 'UP' : 'DOWN';
                        title    = 'Click for details';
                    } else if (upN === total) {
                        dotClass = 'bg-success'; txtClass = 'text-success';
                        label = 'UP'; title = `All ${total} subnet(s) up`;
                    } else if (upN > 0) {
                        dotClass = 'bg-warning'; txtClass = 'text-warning';
                        label = `PARTIAL ${upN}/${total}`; title = `${upN} of ${total} subnet(s) up`;
                    } else {
                        dotClass = 'bg-danger'; txtClass = 'text-danger';
                        label = 'DOWN'; title = 'No subnets up';
                    }

                    let kidsHtml = '';
                    if (total > 1) {
                        kidsHtml = '<div class="mt-1" style="line-height:1.25">' + kids.map(c => {
                            const d = c.up ? 'bg-success' : 'bg-danger';
                            return `<div class="text-muted" style="white-space:nowrap;font-size:.7rem">`
                                 + `<span class="badge rounded-circle ${d} p-1 me-1" style="width:7px;height:7px;"></span>`
                                 + `<span class="font-monospace">${c.remote_ts}</span></div>`;
                        }).join('') + '</div>';
                    }

                    container.innerHTML = `
                        <span class="badge rounded-circle ${dotClass} p-1 me-2" style="width:10px;height:10px;"></span>
                        <span class="${txtClass} small fw-bold" style="cursor:help" title="${title}" onclick="showTroubleshoot(${id})">${label}</span>
                        ${kidsHtml}
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
