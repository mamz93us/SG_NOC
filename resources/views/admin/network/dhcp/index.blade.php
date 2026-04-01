@extends('layouts.admin')
@section('title', 'DHCP Leases')

@section('content')
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0"><i class="bi bi-hdd-network me-2"></i>DHCP Leases</h4>
    </div>

    {{-- Summary Cards --}}
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card text-center border-0 shadow-sm">
                <div class="card-body">
                    <div class="fs-2 fw-bold text-primary">{{ number_format($totalLeases) }}</div>
                    <div class="text-muted small">Total Leases</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-center border-0 shadow-sm">
                <div class="card-body">
                    <div class="fs-2 fw-bold text-info">{{ number_format($merakiLeases) }}</div>
                    <div class="text-muted small">Meraki</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-center border-0 shadow-sm">
                <div class="card-body">
                    <div class="fs-2 fw-bold text-warning">{{ number_format($snmpLeases) }}</div>
                    <div class="text-muted small">Sophos / SNMP</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-center border-0 shadow-sm {{ $conflictCount > 0 ? 'border-danger' : '' }}">
                <div class="card-body">
                    <div class="fs-2 fw-bold text-danger">{{ $conflictCount }}</div>
                    <div class="text-muted small">Conflicts</div>
                </div>
            </div>
        </div>
    </div>

    {{-- Filters --}}
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body py-2">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-md-2">
                    <label class="form-label small mb-0">Branch</label>
                    <select name="branch" class="form-select form-select-sm">
                        <option value="">All Branches</option>
                        @foreach($branches as $b)
                        <option value="{{ $b->id }}" {{ request('branch') == $b->id ? 'selected' : '' }}>{{ $b->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-0">Source</label>
                    <select name="source" class="form-select form-select-sm">
                        <option value="">All Sources</option>
                        <option value="meraki" {{ request('source') == 'meraki' ? 'selected' : '' }}>Meraki</option>
                        <option value="sophos" {{ request('source') == 'sophos' ? 'selected' : '' }}>Sophos</option>
                        <option value="snmp" {{ request('source') == 'snmp' ? 'selected' : '' }}>SNMP</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-0">VLAN</label>
                    <input type="number" name="vlan" class="form-control form-control-sm" value="{{ request('vlan') }}" placeholder="VLAN">
                </div>
                <div class="col-md-3">
                    <label class="form-label small mb-0">Search</label>
                    <input type="text" name="search" class="form-control form-control-sm" value="{{ request('search') }}" placeholder="IP, MAC, or hostname">
                </div>
                <div class="col-md-1">
                    <div class="form-check">
                        <input type="checkbox" name="conflicts" value="1" class="form-check-input" {{ request('conflicts') ? 'checked' : '' }}>
                        <label class="form-check-label small">Conflicts</label>
                    </div>
                </div>
                <div class="col-md-2 d-flex gap-1">
                    <button class="btn btn-sm btn-primary flex-fill"><i class="bi bi-search"></i> Filter</button>
                    <a href="{{ route('admin.network.dhcp.index') }}" class="btn btn-sm btn-outline-secondary">Clear</a>
                </div>
            </form>
        </div>
    </div>

    {{-- Leases Table --}}
    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover table-sm mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>IP Address</th>
                        <th>MAC Address</th>
                        <th>Hostname</th>
                        <th>VLAN</th>
                        <th>Source</th>
                        <th>Branch</th>
                        <th>Switch / Port</th>
                        <th>Linked Asset</th>
                        <th>Last Seen</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                @forelse($leases as $lease)
                    <tr class="{{ $lease->is_conflict ? 'table-danger' : '' }}">
                        <td>
                            <code>{{ $lease->ip_address }}</code>
                            @if($lease->is_conflict)
                                <span class="badge bg-danger ms-1">CONFLICT</span>
                            @endif
                        </td>
                        <td><code class="text-muted">{{ $lease->mac_address }}</code></td>
                        <td>{{ $lease->hostname ?? '-' }}</td>
                        <td>{{ $lease->vlan ?? '-' }}</td>
                        <td><span class="badge {{ $lease->sourceBadgeClass() }}">{{ ucfirst($lease->source) }}</span></td>
                        <td>{{ $lease->branch?->name ?? '-' }}</td>
                        <td>
                            @if($lease->switch_serial)
                                {{ $lease->switch_serial }}
                                @if($lease->port_id) / {{ $lease->port_id }} @endif
                            @else
                                -
                            @endif
                        </td>
                        <td style="min-width:160px">
                            @if($lease->device)
                                <a href="{{ route('admin.devices.show', $lease->device) }}"
                                   class="text-decoration-none small fw-semibold">
                                    <i class="bi bi-hdd me-1 text-success"></i>{{ $lease->device->name }}
                                </a>
                                @if($lease->device->asset_code)
                                <div class="text-muted" style="font-size:.72rem">{{ $lease->device->asset_code }}</div>
                                @endif
                                @can('manage-assets')
                                <form method="POST" action="{{ route('admin.network.dhcp.link-asset', $lease) }}" class="d-inline">
                                    @csrf
                                    <input type="hidden" name="device_id" value="unlink">
                                    <button type="submit" class="btn btn-sm btn-link text-danger p-0" style="font-size:.72rem"
                                            onclick="return confirm('Remove asset link?')">
                                        <i class="bi bi-x-circle me-1"></i>Unlink
                                    </button>
                                </form>
                                @endcan
                            @else
                                @can('manage-assets')
                                <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2"
                                        style="font-size:.75rem"
                                        data-bs-toggle="modal"
                                        data-bs-target="#linkModal"
                                        data-lease-id="{{ $lease->id }}"
                                        data-lease-mac="{{ $lease->mac_address }}"
                                        data-lease-ip="{{ $lease->ip_address }}"
                                        data-link-url="{{ route('admin.network.dhcp.link-asset', $lease) }}">
                                    <i class="bi bi-link-45deg me-1"></i>Link Asset
                                </button>
                                @else
                                <span class="text-muted small">—</span>
                                @endcan
                            @endif
                        </td>
                        <td>{{ $lease->last_seen?->diffForHumans() ?? '-' }}</td>
                        <td>
                            <a href="{{ route('admin.network.dhcp.show', $lease) }}" class="btn btn-sm btn-outline-primary">
                                <i class="bi bi-eye"></i>
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="10" class="text-center text-muted py-4">No DHCP leases found.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if($leases->hasPages())
        <div class="card-footer border-0">{{ $leases->links() }}</div>
        @endif
    </div>
</div>

{{-- ── Link Asset Modal ────────────────────────────────────── --}}
@can('manage-assets')
<div class="modal fade" id="linkModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" id="linkAssetForm" action="">
                @csrf
                <div class="modal-header py-2">
                    <h6 class="modal-title fw-semibold"><i class="bi bi-link-45deg me-1"></i>Link to Asset</h6>
                    <button type="button" class="btn-close btn-sm" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="small text-muted mb-2">
                        Lease: <code id="link-modal-ip"></code> &mdash; MAC: <code id="link-modal-mac"></code>
                    </p>
                    <label class="form-label fw-semibold small">Search Asset</label>
                    <input type="text" id="link-device-search" class="form-control form-control-sm mb-2"
                           placeholder="Type device name, asset code, IP or MAC…" autocomplete="off">
                    <div id="link-device-results" class="list-group" style="max-height:200px;overflow-y:auto"></div>
                    <input type="hidden" name="device_id" id="link-device-id">
                    <div id="link-selected" class="alert alert-success py-1 small d-none mt-2"></div>
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-sm btn-primary" id="link-submit-btn" disabled>
                        <i class="bi bi-link-45deg me-1"></i>Link
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

@push('scripts')
<script>
(function () {
    const modal       = document.getElementById('linkModal');
    const searchInput = document.getElementById('link-device-search');
    const results     = document.getElementById('link-device-results');
    const hiddenId    = document.getElementById('link-device-id');
    const selected    = document.getElementById('link-selected');
    const submitBtn   = document.getElementById('link-submit-btn');
    const form        = document.getElementById('linkAssetForm');
    const searchUrl   = '{{ route('admin.network.dhcp.device-search') }}';
    let debounce;

    modal.addEventListener('show.bs.modal', function (e) {
        const btn = e.relatedTarget;
        document.getElementById('link-modal-ip').textContent  = btn.dataset.leaseIp;
        document.getElementById('link-modal-mac').textContent = btn.dataset.leaseMac;
        form.action = btn.dataset.linkUrl;
        searchInput.value = '';
        results.innerHTML = '';
        hiddenId.value = '';
        selected.classList.add('d-none');
        submitBtn.disabled = true;
        // Pre-fill search with MAC to find matching device
        searchInput.value = btn.dataset.leaseMac;
        triggerSearch(btn.dataset.leaseMac);
    });

    searchInput.addEventListener('input', function () {
        clearTimeout(debounce);
        debounce = setTimeout(() => triggerSearch(this.value), 300);
    });

    function triggerSearch(q) {
        if (q.length < 2) { results.innerHTML = ''; return; }
        fetch(searchUrl + '?q=' + encodeURIComponent(q))
            .then(r => r.json())
            .then(data => {
                results.innerHTML = '';
                if (!data.length) {
                    results.innerHTML = '<div class="list-group-item text-muted small">No assets found</div>';
                    return;
                }
                data.forEach(d => {
                    const item = document.createElement('button');
                    item.type = 'button';
                    item.className = 'list-group-item list-group-item-action py-1';
                    item.innerHTML = `<span class="fw-semibold">${d.name}</span>`
                        + (d.asset_code ? ` <span class="badge bg-secondary" style="font-size:.68rem">${d.asset_code}</span>` : '')
                        + `<div class="text-muted small">${d.type}${d.ip ? ' · ' + d.ip : ''}${d.mac ? ' · ' + d.mac : ''}</div>`;
                    item.addEventListener('click', () => {
                        hiddenId.value = d.id;
                        selected.textContent = '✓ Selected: ' + d.name + (d.asset_code ? ' (' + d.asset_code + ')' : '');
                        selected.classList.remove('d-none');
                        submitBtn.disabled = false;
                        results.innerHTML = '';
                    });
                    results.appendChild(item);
                });
            });
    }
})();
</script>
@endpush
@endcan

@endsection
