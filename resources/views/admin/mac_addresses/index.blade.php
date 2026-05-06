@extends('layouts.admin')
@section('title', 'MAC Address Registry')

@section('content')
<div class="container-fluid py-4">

    {{-- ── Header ─────────────────────────────────────────────────── --}}
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-0"><i class="bi bi-fingerprint me-2 text-primary"></i>MAC Address Registry</h4>
            <small class="text-muted">All network adapters registered in the system — used for RADIUS / 802.1X configuration</small>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('admin.itam.mac-address', array_merge(request()->query(), ['export' => 1])) }}"
               class="btn btn-sm btn-outline-success">
                <i class="bi bi-download me-1"></i>Export CSV
            </a>
            <a href="{{ route('admin.itam.dashboard') }}" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i>ITAM
            </a>
        </div>
    </div>

    {{-- ── KPI Cards ────────────────────────────────────────────────── --}}
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-2">
            <div class="card border-0 shadow-sm text-center py-3">
                <div class="display-6 fw-bold text-primary">{{ number_format($stats['total_registered']) }}</div>
                <div class="small text-muted">Total MACs</div>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="card border-0 shadow-sm text-center py-3">
                <div class="display-6 fw-bold text-success">{{ number_format($stats['from_intune']) }}</div>
                <div class="small text-muted">From Intune</div>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="card border-0 shadow-sm text-center py-3">
                <div class="display-6 fw-bold text-primary">{{ number_format($stats['ethernet']) }}</div>
                <div class="small text-muted">Ethernet</div>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="card border-0 shadow-sm text-center py-3">
                <div class="display-6 fw-bold text-info">{{ number_format($stats['wifi']) }}</div>
                <div class="small text-muted">Wi-Fi</div>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="card border-0 shadow-sm text-center py-3">
                <div class="display-6 fw-bold text-warning">{{ number_format($stats['usb_ethernet']) }}</div>
                <div class="small text-muted">USB LAN</div>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="card border-0 shadow-sm text-center py-3">
                <div class="display-6 fw-bold text-secondary">{{ number_format($stats['devices_with_mac']) }}</div>
                <div class="small text-muted">Devices w/ MAC</div>
            </div>
        </div>
    </div>

    {{-- ── Search / Filter bar ─────────────────────────────────────── --}}
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body py-3">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-md-5">
                    <label class="form-label small mb-1">Search</label>
                    <input type="text" name="search" class="form-control form-control-sm"
                           placeholder="MAC address, device name…"
                           value="{{ request('search') }}">
                </div>
                <div class="col-md-3">
                    <label class="form-label small mb-1">Adapter Type</label>
                    <select name="type" class="form-select form-select-sm">
                        <option value="">All Types</option>
                        <option value="ethernet"     {{ request('type') === 'ethernet'     ? 'selected' : '' }}>Ethernet</option>
                        <option value="wifi"         {{ request('type') === 'wifi'         ? 'selected' : '' }}>Wi-Fi</option>
                        <option value="usb_ethernet" {{ request('type') === 'usb_ethernet' ? 'selected' : '' }}>USB Ethernet</option>
                        <option value="management"   {{ request('type') === 'management'   ? 'selected' : '' }}>Management</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1">Source</label>
                    <select name="source" class="form-select form-select-sm">
                        <option value="">All Sources</option>
                        <option value="intune" {{ request('source') === 'intune' ? 'selected' : '' }}>Intune</option>
                        <option value="snmp"   {{ request('source') === 'snmp'   ? 'selected' : '' }}>SNMP</option>
                        <option value="dhcp"   {{ request('source') === 'dhcp'   ? 'selected' : '' }}>DHCP</option>
                        <option value="manual" {{ request('source') === 'manual' ? 'selected' : '' }}>Manual</option>
                    </select>
                </div>
                <div class="col-md-2 d-flex gap-2">
                    <button type="submit" class="btn btn-primary btn-sm flex-grow-1">
                        <i class="bi bi-search me-1"></i>Search
                    </button>
                    <a href="{{ route('admin.itam.mac-address') }}" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-x-lg"></i>
                    </a>
                </div>
            </form>
        </div>
    </div>

    {{-- ══════════════════════════════════════════════════════════════ --}}
    {{-- Section 1: device_macs registry (Intune + future sources)     --}}
    {{-- ══════════════════════════════════════════════════════════════ --}}
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header d-flex align-items-center justify-content-between">
            <span class="fw-semibold">
                <i class="bi bi-microsoft me-2 text-primary"></i>
                MAC Registry
                <span class="badge bg-secondary ms-1">{{ $deviceMacs->total() }}</span>
            </span>
            <small class="text-muted">Populated by Intune sync &amp; future SNMP/DHCP collectors</small>
        </div>
        <div class="card-body p-0">
            @if($deviceMacs->isEmpty())
            <div class="text-center py-5 text-muted">
                <i class="bi bi-fingerprint fs-1 d-block mb-2 opacity-25"></i>
                No MAC addresses synced yet.<br>
                <small>Run <code>php artisan intune:sync-net-data</code> after deploying the Intune script.</small>
            </div>
            @else
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" style="font-size:.84rem">
                    <thead class="table-light sticky-top">
                        <tr>
                            <th class="ps-3" style="width:170px">MAC Address</th>
                            <th style="width:130px">Type</th>
                            <th>Adapter / Hardware</th>
                            <th>Owner Device</th>
                            <th style="width:120px">IP Address</th>
                            <th style="width:100px">Source</th>
                            <th style="width:130px">Last Seen</th>
                            <th style="width:60px">Primary</th>
                            @can('manage-radius')
                            <th style="width:140px">RADIUS</th>
                            @endcan
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($deviceMacs as $mac)
                        @php
                            $macNorm = strtoupper(preg_replace('/[^a-fA-F0-9]/', '', $mac->mac_address ?? ''));
                            $macIp   = $mac->device?->ip_address ?: ($dhcpByMac[$macNorm] ?? null);
                        @endphp
                        <tr>
                            <td class="ps-3 font-monospace fw-semibold">{{ $mac->mac_address }}</td>
                            <td>
                                <span class="badge bg-{{ $mac->adapterTypeBadge() }}">
                                    {{ $mac->adapterTypeLabel() }}
                                </span>
                            </td>
                            <td>
                                <div>{{ $mac->adapter_name ?: '—' }}</div>
                                @if($mac->adapter_description)
                                <div class="text-muted small">{{ $mac->adapter_description }}</div>
                                @endif
                            </td>
                            <td>
                                @if($mac->azureDevice)
                                    <a href="{{ route('admin.itam.azure.show', $mac->azureDevice) }}" class="text-decoration-none">
                                        <i class="bi bi-microsoft me-1 text-primary" style="font-size:.75rem"></i>
                                        {{ $mac->azureDevice->display_name }}
                                    </a>
                                    @if($mac->azureDevice->upn)
                                    <div class="text-muted small">{{ $mac->azureDevice->upn }}</div>
                                    @endif
                                @elseif($mac->device)
                                    <a href="{{ route('admin.devices.show', $mac->device) }}" class="text-decoration-none">
                                        <i class="bi bi-hdd me-1 text-secondary" style="font-size:.75rem"></i>
                                        {{ $mac->device->name }}
                                    </a>
                                    @if($mac->device->branch)
                                    <div class="text-muted small">{{ $mac->device->branch->name }}</div>
                                    @endif
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td class="font-monospace small">
                                @if($macIp)
                                    {{ $macIp }}
                                    @if(! $mac->device?->ip_address)
                                        <span class="badge bg-info text-dark ms-1" style="font-size:.62rem">DHCP</span>
                                    @endif
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td>
                                <span class="badge bg-{{ $mac->sourceBadge() }} text-dark" style="font-size:.7rem">
                                    {{ ucfirst($mac->source) }}
                                </span>
                            </td>
                            <td class="text-muted small">
                                {{ $mac->last_seen_at?->diffForHumans() ?: '—' }}
                            </td>
                            <td class="text-center">
                                @if($mac->is_primary)
                                <i class="bi bi-check-circle-fill text-success" title="Primary MAC for RADIUS"></i>
                                @else
                                <i class="bi bi-dash text-muted"></i>
                                @endif
                            </td>
                            @can('manage-radius')
                            <td>
                                @php
                                    $ovr = $mac->radiusOverride;
                                    $enabled = $ovr ? $ovr->radius_enabled : true;
                                    $vlanOverride = $ovr?->vlan_override;
                                @endphp
                                <button type="button"
                                        class="btn btn-sm btn-link p-0 text-decoration-none"
                                        data-bs-toggle="modal" data-bs-target="#radiusModal"
                                        data-id="{{ $mac->id }}"
                                        data-mac="{{ $mac->mac_address }}"
                                        data-enabled="{{ $enabled ? 1 : 0 }}"
                                        data-vlan="{{ $vlanOverride }}"
                                        data-notes="{{ $ovr?->notes }}"
                                        title="Edit RADIUS override">
                                    @if(!$enabled)
                                        <span class="badge bg-danger">Denied</span>
                                    @elseif($vlanOverride !== null)
                                        <span class="badge bg-warning text-dark">VLAN {{ $vlanOverride }}</span>
                                    @elseif($ovr)
                                        <span class="badge bg-info text-dark">Override</span>
                                    @else
                                        <span class="badge bg-light text-muted border">Default</span>
                                    @endif
                                    <i class="bi bi-pencil-square ms-1 text-muted" style="font-size:.75rem"></i>
                                </button>
                            </td>
                            @endcan
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if($deviceMacs->hasPages())
            <div class="px-3 py-2 border-top">
                {{ $deviceMacs->links() }}
            </div>
            @endif
            @endif
        </div>
    </div>

    {{-- ══════════════════════════════════════════════════════════════ --}}
    {{-- Section 2: Devices with mac_address (phones, printers, etc.)  --}}
    {{-- ══════════════════════════════════════════════════════════════ --}}
    @if($deviceRows->count() > 0)
    <div class="card border-0 shadow-sm">
        <div class="card-header d-flex align-items-center justify-content-between">
            <span class="fw-semibold">
                <i class="bi bi-hdd me-2 text-secondary"></i>
                Other Assets with MAC Address
                <span class="badge bg-secondary ms-1">{{ $deviceRows->count() }}</span>
            </span>
            <small class="text-muted">
                Phones, printers, switches — stored directly on the device record
            </small>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" style="font-size:.84rem">
                    <thead class="table-light sticky-top">
                        <tr>
                            <th class="ps-3" style="width:60px">Adapter</th>
                            <th style="width:170px">MAC Address</th>
                            <th>Device Name</th>
                            <th style="width:120px">Type</th>
                            <th>Branch</th>
                            <th style="width:120px">Asset Code</th>
                            <th style="width:130px">IP Address</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($deviceRows as $device)
                        @php
                            $lanNorm  = strtoupper(preg_replace('/[^a-fA-F0-9]/', '', $device->mac_address ?? ''));
                            $lanMacFmt = $lanNorm ? strtoupper(implode(':', str_split($lanNorm, 2))) : null;
                            $wifiNorm = $device->wifi_mac ? strtoupper(preg_replace('/[^a-fA-F0-9]/', '', $device->wifi_mac)) : null;
                            $wifiMacFmt = $wifiNorm ? strtoupper(implode(':', str_split($wifiNorm, 2))) : null;
                            $devIp    = $device->ip_address
                                ?: ($dhcpByMac[$lanNorm]  ?? null)
                                ?: ($wifiNorm ? ($dhcpByMac[$wifiNorm] ?? null) : null);
                            $ipSource = !$device->ip_address && $devIp ? 'DHCP' : null;
                            $rowspan  = $wifiMacFmt ? 2 : 1;
                        @endphp
                        {{-- LAN row --}}
                        <tr>
                            <td class="ps-3">
                                <span class="badge bg-primary" style="font-size:.68rem">LAN</span>
                            </td>
                            <td class="font-monospace fw-semibold">{{ $lanMacFmt ?? '—' }}</td>
                            <td @if($rowspan==2) rowspan="2" @endif>
                                <a href="{{ route('admin.devices.show', $device) }}" class="text-decoration-none fw-semibold">
                                    {{ $device->name }}
                                </a>
                            </td>
                            <td @if($rowspan==2) rowspan="2" @endif>
                                <span class="badge bg-secondary">{{ ucfirst($device->type) }}</span>
                            </td>
                            <td @if($rowspan==2) rowspan="2" @endif class="text-muted small">
                                {{ $device->branch?->name ?: '—' }}
                            </td>
                            <td @if($rowspan==2) rowspan="2" @endif class="font-monospace small">
                                {{ $device->asset_code ?: '—' }}
                            </td>
                            <td @if($rowspan==2) rowspan="2" @endif class="font-monospace small">
                                @if($devIp)
                                    {{ $devIp }}
                                    @if($ipSource)
                                        <span class="badge bg-info text-dark ms-1" style="font-size:.62rem">{{ $ipSource }}</span>
                                    @endif
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                        </tr>
                        {{-- WiFi row (only for phones/devices with wifi_mac) --}}
                        @if($wifiMacFmt)
                        <tr>
                            <td class="ps-3">
                                <span class="badge bg-info text-dark" style="font-size:.68rem">
                                    <i class="bi bi-wifi me-1"></i>WiFi
                                </span>
                            </td>
                            <td class="font-monospace fw-semibold">{{ $wifiMacFmt }}</td>
                        </tr>
                        @endif
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    @endif

    @can('manage-radius')
    {{-- ── RADIUS override modal (rendered once, populated by JS) ─── --}}
    <div class="modal fade" id="radiusModal" tabindex="-1">
        <div class="modal-dialog">
            <form id="radiusModalForm" method="POST" action="">
                @csrf
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">
                            <i class="bi bi-shield-lock me-2"></i>RADIUS Override —
                            <code id="radiusModalMac" class="ms-1"></code>
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <div class="form-check form-switch">
                                <input type="hidden" name="radius_enabled" value="0">
                                <input type="checkbox" name="radius_enabled" id="radiusEnabled"
                                       value="1" class="form-check-input" checked>
                                <label class="form-check-label fw-semibold" for="radiusEnabled">
                                    Allow RADIUS authentication for this MAC
                                </label>
                            </div>
                            <small class="text-muted">When off, RADIUS rejects this MAC even if <code>device_macs.is_active=1</code>.</small>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">VLAN override (optional)</label>
                            <input type="number" name="vlan_override" id="radiusVlan"
                                   class="form-control" min="1" max="4094"
                                   placeholder="Leave blank to use branch VLAN policy">
                            <small class="text-muted">When set, wins over branch policy on Access-Accept.</small>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Notes</label>
                            <input type="text" name="notes" id="radiusNotes" class="form-control" maxlength="255"
                                   placeholder="e.g. quarantine until investigation closes">
                        </div>
                        <div class="alert alert-secondary small mb-0">
                            <i class="bi bi-info-circle me-1"></i>
                            Saving with default settings (allowed, no VLAN override, no notes)
                            removes the override row entirely.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <script>
    (function () {
        const modal      = document.getElementById('radiusModal');
        const form       = document.getElementById('radiusModalForm');
        const macLabel   = document.getElementById('radiusModalMac');
        const enabledChk = document.getElementById('radiusEnabled');
        const vlanInput  = document.getElementById('radiusVlan');
        const notesInput = document.getElementById('radiusNotes');
        // {macId} placeholder swapped at trigger time.
        const urlTemplate = "{{ route('admin.radius.mac-overrides.upsert', ['deviceMac' => '__ID__']) }}";

        modal.addEventListener('show.bs.modal', (event) => {
            const btn = event.relatedTarget;
            const id      = btn.getAttribute('data-id');
            const mac     = btn.getAttribute('data-mac');
            const enabled = btn.getAttribute('data-enabled') === '1';
            const vlan    = btn.getAttribute('data-vlan');
            const notes   = btn.getAttribute('data-notes');

            form.action       = urlTemplate.replace('__ID__', id);
            macLabel.textContent = mac;
            enabledChk.checked   = enabled;
            vlanInput.value      = vlan && vlan !== 'null' ? vlan : '';
            notesInput.value     = notes && notes !== 'null' ? notes : '';
        });
    })();
    </script>
    @endcan

</div>
@endsection
