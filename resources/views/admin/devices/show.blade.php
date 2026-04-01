@extends('layouts.admin')
@section('content')

@php
    $canManage = auth()->user()->can('manage-assets');
    $isUserEquipment = $device->isUserEquipment();
    $assigned = $device->currentAssignment;
@endphp

{{-- ── Header ── --}}
<div class="d-flex justify-content-between align-items-start mb-4">
    <div class="d-flex align-items-center gap-2 flex-wrap">
        <a href="{{ route('admin.devices.index') }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i></a>
        <span class="badge {{ $device->typeBadgeClass() }} fs-6">
            <i class="bi {{ $device->typeIcon() }} me-1"></i>{{ $device->typeLabel() }}
        </span>
        <h4 class="mb-0 fw-bold">{{ $device->name }}</h4>
        <span class="badge {{ $device->statusBadgeClass() }}">{{ ucfirst($device->status) }}</span>
        @if($device->condition)
        <span class="badge {{ $device->conditionBadgeClass() }}">{{ $device->conditionLabel() }}</span>
        @endif
    </div>
    <div class="d-flex gap-2">
        @if($device->asset_code)
        <a href="{{ route('admin.devices.label', $device) }}" target="_blank" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-qr-code me-1"></i>Label
        </a>
        @endif
        @can('manage-assets')
        <a href="{{ route('admin.devices.edit', $device) }}" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-pencil me-1"></i>Edit
        </a>
        @endcan
    </div>
</div>

{{-- ── Asset Code Banner ── --}}
@if($device->asset_code)
<div class="alert alert-light border d-flex align-items-center gap-3 mb-3 py-2">
    <canvas id="showQrCanvas" style="width:60px;height:60px"></canvas>
    <div>
        <div class="text-muted small">Asset Code</div>
        <div class="font-monospace fw-bold fs-5">{{ $device->asset_code }}</div>
    </div>
</div>
@endif

{{-- ── Assign / Return bar (for user equipment) ── --}}
@if($isUserEquipment && $canManage)
<div class="card shadow-sm mb-3">
    <div class="card-body py-2 d-flex align-items-center justify-content-between gap-3">
        <div class="d-flex align-items-center gap-2">
            <i class="bi bi-person-fill-check text-{{ $assigned ? 'primary' : 'muted' }} fs-5"></i>
            @if($assigned)
            <span>
                Assigned to 
                <a href="{{ route('admin.employees.show', $assigned->employee->id) }}" class="text-decoration-none fw-bold">
                    {{ $assigned->employee->name }}
                </a>
                since {{ $assigned->assigned_date->format('d M Y') }}
            </span>
            @else
            <span class="text-muted">Not assigned to any employee.</span>
            @endif
        </div>
        <div class="d-flex gap-2">
            @if(!$assigned)
            <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#assignModal">
                <i class="bi bi-person-plus me-1"></i>Assign
            </button>
            @else
            <button class="btn btn-sm btn-outline-warning" data-bs-toggle="modal" data-bs-target="#returnModal">
                <i class="bi bi-box-arrow-left me-1"></i>Return
            </button>
            @endif
        </div>
    </div>
</div>
@endif

<div class="row g-3">

    {{-- ── Column 1: Device Info + ITAM ── --}}
    <div class="col-md-5">

        {{-- Device Info --}}
        <div class="card shadow-sm mb-3">
            <div class="card-header py-2">
                <h6 class="mb-0 fw-semibold"><i class="bi bi-info-circle me-2"></i>Device Info</h6>
            </div>
            <div class="card-body p-0">
                <table class="table table-sm table-borderless small mb-0">
                    <tr><th class="text-muted ps-3" style="width:40%">Model</th>
                        <td>{{ $device->deviceModel ? $device->deviceModel->displayName() : ($device->model ?: '—') }}</td></tr>
                    @if(!$device->device_model_id && $device->manufacturer)
                    <tr><th class="text-muted ps-3">Manufacturer</th>
                        <td>{{ $device->manufacturer }}</td></tr>
                    @endif
                    <tr><th class="text-muted ps-3">Serial</th>
                        <td class="font-monospace">{{ $device->serial_number ?: '—' }}</td></tr>
                    @php
                        // Normalize any MAC to AA:BB:CC:DD:EE:FF for display
                        $normMacFn = fn(?string $m) => $m
                            ? strtoupper(implode(':', str_split(strtoupper(preg_replace('/[^a-fA-F0-9]/','',$m)),2)))
                            : null;
                        // IP resolution: device IP → DHCP → nothing
                        $displayIp  = $device->ip_address ?: $dhcpLease?->ip_address;
                        $ipFromDhcp = !$device->ip_address && $dhcpLease?->ip_address;
                        // Azure device & Intune HW data
                        $az       = $device->azureDevice;
                        $intuneHw = $az && $az->net_data_synced_at;
                        // MAC resolution: device field → Intune ethernet_mac → nothing
                        $displayMac     = $device->mac_address ?: ($intuneHw ? $az->ethernet_mac : null);
                        $macFromIntune  = !$device->mac_address && $intuneHw && $az->ethernet_mac;
                        // WiFi MAC: device field → Intune wifi_mac → nothing
                        $displayWifiMac    = $device->wifi_mac ?: ($intuneHw ? $az->wifi_mac : null);
                        $wifiMacFromIntune = !$device->wifi_mac && $intuneHw && $az->wifi_mac;
                    @endphp
                    <tr><th class="text-muted ps-3">IP</th>
                        <td class="font-monospace">
                            <span id="dv-ip-display">
                            @if($displayIp)
                                {{ $displayIp }}
                                @if($ipFromDhcp)
                                    <span class="badge bg-info text-dark ms-1" style="font-size:.65em"
                                          title="From DHCP lease ({{ $dhcpLease->source }}, {{ $dhcpLease->last_seen?->diffForHumans() }})">DHCP</span>
                                @endif
                            @else
                                <span class="text-muted">—</span>
                            @endif
                            </span>
                            @can('manage-network-settings')
                            <a href="{{ route('admin.network.ip-reservations.create', ['device_id' => $device->id]) }}"
                               class="btn btn-sm btn-outline-primary py-0 px-1 ms-1" style="font-size:11px">
                                <i class="bi bi-plus"></i>
                            </a>
                            @endcan
                            @if($displayMac || ($intuneHw && $az && $az->ethernet_mac))
                            <button type="button" id="dv-dhcp-btn"
                                    class="btn btn-sm btn-outline-info py-0 px-1 ms-1" style="font-size:11px"
                                    title="Look up IP from DHCP leases"
                                    data-mac="{{ $normMacFn($displayMac ?: ($intuneHw && $az ? $az->ethernet_mac : '')) }}"
                                    data-lookup-url="{{ route('admin.devices.dhcp-lookup') }}">
                                <i class="bi bi-search"></i>
                            </button>
                            @endif
                        </td></tr>
                    <tr><th class="text-muted ps-3">
                            @if($device->type === 'phone') LAN MAC @else MAC @endif
                        </th>
                        <td class="font-monospace">
                            @if($displayMac)
                                {{ $normMacFn($displayMac) }}
                                @if($intuneHw && ($az->ethernet_mac || $macFromIntune))
                                    <span class="badge bg-success ms-1" style="font-size:.65em">Intune</span>
                                @else
                                    <span class="badge bg-secondary ms-1" style="font-size:.65em">Manual</span>
                                @endif
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td></tr>
                    @if($displayWifiMac || $device->type === 'phone')
                    <tr><th class="text-muted ps-3"><i class="bi bi-wifi me-1 text-info"></i>WiFi MAC</th>
                        <td class="font-monospace">
                            @if($displayWifiMac)
                                {{ $normMacFn($displayWifiMac) }}
                                @if($device->type === 'phone' && !$wifiMacFromIntune)
                                    <span class="badge bg-info text-dark ms-1" style="font-size:.65em">+1</span>
                                @endif
                                @if($intuneHw && ($az->wifi_mac || $wifiMacFromIntune))
                                    <span class="badge bg-success ms-1" style="font-size:.65em">Intune</span>
                                @elseif(!$wifiMacFromIntune)
                                    <span class="badge bg-secondary ms-1" style="font-size:.65em">Manual</span>
                                @endif
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td></tr>
                    @endif
                    <tr><th class="text-muted ps-3">Branch</th>
                        <td>{{ $device->branch?->name ?: '—' }}</td></tr>
                    @if($device->floor || $device->office)
                    <tr><th class="text-muted ps-3">Floor / Room</th>
                        <td>{{ $device->floor?->name }} {{ $device->office ? '/ '.$device->office->name : '' }}</td></tr>
                    @endif
                    @if($device->department)
                    <tr><th class="text-muted ps-3">Department</th>
                        <td>{{ $device->department->name }}</td></tr>
                    @endif
                    @if($device->location_description)
                    <tr><th class="text-muted ps-3">Location</th>
                        <td>{{ $device->location_description }}</td></tr>
                    @endif
                    <tr><th class="text-muted ps-3">Source</th>
                        <td><span class="badge bg-secondary">{{ ucfirst($device->source) }}</span></td></tr>
                    <tr><th class="text-muted ps-3">Updated</th>
                        <td>{{ $device->updated_at->diffForHumans() }}</td></tr>
                </table>
                @if($device->notes)
                <div class="px-3 pb-2 pt-0">
                    <hr class="mt-1 mb-2">
                    <p class="small text-muted mb-0">{{ $device->notes }}</p>
                </div>
                @endif
            </div>
        </div>

        {{-- ITAM / Financial Info --}}
        @canany(['view-itam','manage-itam','manage-assets'])
        <div class="card shadow-sm mb-3">
            <div class="card-header py-2">
                <h6 class="mb-0 fw-semibold"><i class="bi bi-boxes me-2"></i>ITAM / Financial</h6>
            </div>
            <div class="card-body p-0">
                <table class="table table-sm table-borderless small mb-0">
                    <tr><th class="text-muted ps-3" style="width:40%">Purchase Date</th>
                        <td>{{ $device->purchase_date?->format('d M Y') ?: '—' }}</td></tr>
                    <tr><th class="text-muted ps-3">Warranty Exp.</th>
                        <td>
                            @if($device->warranty_expiry)
                                {{ $device->warranty_expiry->format('d M Y') }}
                                @if($device->isWarrantyExpired())
                                <span class="badge bg-danger ms-1">Expired</span>
                                @elseif($device->warrantyDaysLeft() <= 30)
                                <span class="badge bg-warning text-dark ms-1">Expiring soon</span>
                                @else
                                <span class="badge bg-success ms-1">Valid</span>
                                @endif
                            @else
                            —
                            @endif
                        </td></tr>
                    <tr><th class="text-muted ps-3">Supplier</th>
                        <td>{{ $device->supplier?->name ?: '—' }}</td></tr>
                    <tr><th class="text-muted ps-3">Purchase Cost</th>
                        <td>{{ $device->purchase_cost ? 'SAR ' . number_format($device->purchase_cost, 2) : '—' }}</td></tr>
                    <tr><th class="text-muted ps-3">Condition</th>
                        <td>
                            @if($device->condition)
                            <span class="badge {{ $device->conditionBadgeClass() }}">{{ $device->conditionLabel() }}</span>
                            @else
                            —
                            @endif
                        </td></tr>
                    <tr><th class="text-muted ps-3">Depreciation</th>
                        <td>
                            @if($device->depreciation_method === 'straight_line')
                            Straight Line / {{ $device->depreciation_years }}yr
                            @else
                            None
                            @endif
                        </td></tr>
                    @if($device->purchase_cost && $device->depreciation_method === 'straight_line')
                    <tr><th class="text-muted ps-3">Current Value</th>
                        <td class="fw-semibold">SAR {{ number_format($depreciation->currentValue($device), 2) }}
                            <small class="text-muted">({{ number_format($depreciation->percentDepreciated($device), 0) }}% depreciated)</small>
                        </td></tr>
                    @endif
                </table>
            </div>
        </div>
        @endcanany

        {{-- Azure Device Link --}}
        @if($device->azureDevice)
        @php $az = $device->azureDevice; @endphp
        <div class="card shadow-sm mb-3">
            <div class="card-header py-2 d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-semibold"><i class="bi bi-microsoft me-2"></i>Azure / Intune</h6>
                <div class="d-flex gap-1">
                    @can('manage-itam')
                    <form method="POST" action="{{ route('admin.itam.azure.sync-hw-data', $az) }}" class="d-inline">
                        @csrf
                        <button type="submit" class="btn btn-sm btn-outline-success py-0 px-2" style="font-size:11px"
                                title="Sync CPU, MAC addresses and TeamViewer ID from Intune">
                            <i class="bi bi-motherboard me-1"></i>Sync HW
                        </button>
                    </form>
                    @endcan
                    <a href="{{ route('admin.itam.azure.show', $az) }}" class="btn btn-sm btn-outline-secondary py-0 px-2" style="font-size:11px">
                        <i class="bi bi-box-arrow-up-right"></i> Detail
                    </a>
                </div>
            </div>
            <div class="card-body p-0">
                <table class="table table-sm table-borderless small mb-0">
                    <tr><th class="text-muted ps-3" style="width:42%">Display Name</th>
                        <td>{{ $az->display_name }}</td></tr>
                    <tr><th class="text-muted ps-3">UPN</th>
                        <td class="text-truncate" style="max-width:180px">{{ $az->upn ?: '—' }}</td></tr>
                    <tr><th class="text-muted ps-3">OS</th>
                        <td>{{ $az->os }} {{ $az->os_version }}</td></tr>
                    <tr><th class="text-muted ps-3">Last Sync</th>
                        <td>{{ $az->last_sync_at?->format('d M Y H:i') ?: '—' }}</td></tr>
                    <tr><th class="text-muted ps-3">Link Status</th>
                        <td>
                            <span class="badge bg-{{ $az->link_status === 'linked' ? 'success' : ($az->link_status === 'pending' ? 'warning text-dark' : 'secondary') }}">
                                {{ ucfirst($az->link_status) }}
                            </span>
                        </td></tr>
                </table>
            </div>
        </div>

        {{-- Intune Hardware / Network Data (from NOC-DeviceInfo.ps1) --}}
        @if($az->net_data_synced_at)
        <div class="card shadow-sm mb-3">
            <div class="card-header py-2">
                <h6 class="mb-0 fw-semibold">
                    <i class="bi bi-motherboard me-2 text-primary"></i>Hardware / Network
                    <span class="badge bg-success ms-2" style="font-size:.68em">Intune</span>
                    <span class="text-muted fw-normal ms-2" style="font-size:.8em">{{ $az->net_data_synced_at->diffForHumans() }}</span>
                </h6>
            </div>
            <div class="card-body p-0">
                <table class="table table-sm table-borderless small mb-0">
                    @if($az->cpu_name)
                    <tr><th class="text-muted ps-3" style="width:42%"><i class="bi bi-cpu me-1"></i>CPU</th>
                        <td>{{ $az->cpu_name }}</td></tr>
                    @endif
                    @if($az->ethernet_mac)
                    <tr><th class="text-muted ps-3"><i class="bi bi-ethernet me-1"></i>Ethernet MAC</th>
                        <td class="font-monospace">{{ $az->ethernet_mac }}</td></tr>
                    @endif
                    @if($az->wifi_mac)
                    <tr><th class="text-muted ps-3"><i class="bi bi-wifi me-1 text-info"></i>WiFi MAC</th>
                        <td class="font-monospace">{{ $az->wifi_mac }}</td></tr>
                    @endif
                    @foreach($az->usb_eth_decoded() as $usb)
                    <tr><th class="text-muted ps-3"><i class="bi bi-usb-symbol me-1 text-warning"></i>USB LAN</th>
                        <td class="font-monospace">
                            {{ $usb['mac'] ?? '—' }}
                            @if(!empty($usb['name']))<span class="text-muted ms-1">({{ $usb['name'] }})</span>@endif
                        </td></tr>
                    @endforeach
                    @if($az->teamviewer_id)
                    <tr><th class="text-muted ps-3"><i class="bi bi-display me-1 text-success"></i>TeamViewer ID</th>
                        <td class="font-monospace fw-semibold">{{ $az->teamviewer_id }}
                            @if($az->tv_version)
                            <span class="text-muted ms-1 fw-normal" style="font-size:.9em">v{{ $az->tv_version }}</span>
                            @endif
                        </td></tr>
                    @endif
                </table>
            </div>
        </div>
        @endif
        @endif

    </div>{{-- /col-md-5 --}}

    {{-- ── Column 2: Credentials + Asset History ── --}}
    <div class="col-md-7">

        {{-- Credentials --}}
        <div class="card shadow-sm mb-3">
            <div class="card-header py-2 d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-semibold"><i class="bi bi-key-fill me-2"></i>Credentials</h6>
                @can('manage-credentials')
                <a href="{{ route('admin.credentials.create') }}?device_id={{ $device->id }}" class="btn btn-sm btn-outline-primary">
                    <i class="bi bi-plus-lg"></i> Add
                </a>
                @endcan
            </div>
            <div class="card-body p-0">
                @if($device->credentials->isEmpty())
                <div class="text-center py-3 text-muted small">No credentials linked.</div>
                @else
                <table class="table table-sm table-hover align-middle mb-0 small">
                    <thead class="table-light">
                        <tr><th>Title</th><th>Category</th><th>Username</th><th>Added by</th><th></th></tr>
                    </thead>
                    <tbody>
                        @foreach($device->credentials as $cred)
                        <tr>
                            <td class="fw-semibold">{{ $cred->title }}</td>
                            <td><span class="badge {{ $cred->categoryBadgeClass() }}">{{ $cred->categoryLabel() }}</span></td>
                            <td class="font-monospace text-muted">{{ $cred->username ?: '—' }}</td>
                            <td class="text-muted">{{ $cred->creator?->name ?: '—' }}</td>
                            <td>
                                @can('manage-credentials')
                                <a href="{{ route('admin.credentials.edit', $cred) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil"></i></a>
                                @endcan
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
                @endif
            </div>
        </div>

        {{-- Software Licenses --}}
        @if($device->licenseAssignments->isNotEmpty())
        <div class="card shadow-sm mb-3">
            <div class="card-header py-2">
                <h6 class="mb-0 fw-semibold"><i class="bi bi-key me-2"></i>Software Licenses</h6>
            </div>
            <div class="card-body p-0">
                <table class="table table-sm align-middle mb-0 small">
                    <thead class="table-light"><tr><th>License</th><th>Vendor</th><th>Type</th><th>Assigned</th></tr></thead>
                    <tbody>
                        @foreach($device->licenseAssignments as $la)
                        <tr>
                            <td class="fw-semibold">{{ $la->license->license_name }}</td>
                            <td class="text-muted">{{ $la->license->vendor ?: '—' }}</td>
                            <td><span class="badge bg-secondary">{{ ucfirst($la->license->license_type) }}</span></td>
                            <td class="text-muted">{{ $la->assigned_date?->format('d M Y') ?: '—' }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
        @endif

        {{-- Asset History --}}
        <div class="card shadow-sm mb-3">
            <div class="card-header py-2">
                <h6 class="mb-0 fw-semibold"><i class="bi bi-clock-history me-2"></i>Asset History</h6>
            </div>
            <div class="card-body">
                @if($device->assetHistory->isEmpty())
                <div class="text-muted small text-center py-2">No history recorded yet.</div>
                @else
                <div class="timeline" style="max-height:320px;overflow-y:auto">
                    @foreach($device->assetHistory->take(20) as $h)
                    <div class="d-flex gap-2 mb-2">
                        <div class="pt-1">
                            @php
                            $icon = match($h->event_type) {
                                'created'          => 'bi-plus-circle-fill text-success',
                                'assigned'         => 'bi-person-fill-check text-primary',
                                'returned'         => 'bi-box-arrow-left text-warning',
                                'maintenance'      => 'bi-tools text-warning',
                                'repair'           => 'bi-wrench text-orange',
                                'retired'          => 'bi-archive-fill text-secondary',
                                'disposed'         => 'bi-trash-fill text-danger',
                                'license_assigned' => 'bi-key-fill text-info',
                                'license_removed'  => 'bi-key text-muted',
                                'note_added'       => 'bi-chat-left-text-fill text-muted',
                                default            => 'bi-circle-fill text-muted',
                            };
                            @endphp
                            <i class="bi {{ $icon }}" style="font-size:.85rem"></i>
                        </div>
                        <div class="flex-grow-1">
                            <div class="small">{{ $h->description }}</div>
                            <div class="text-muted" style="font-size:.75rem">
                                {{ $h->user?->name ?? 'System' }} &bull; {{ \Carbon\Carbon::parse($h->created_at)->format('d M Y H:i') }}
                            </div>
                        </div>
                    </div>
                    @endforeach
                </div>
                @endif
            </div>
        </div>

    </div>{{-- /col-md-7 --}}

</div>{{-- /row --}}

{{-- ── Access Panel (Quick Access + SSH Sessions + Access Log) ── --}}
@include('admin.devices._access_panel')

@can('manage-assets')
<div class="mt-2 d-flex align-items-center gap-2">
    @if($assigned)
        <button class="btn btn-sm btn-outline-danger" disabled title="Cannot delete — device is assigned to {{ $assigned->employee?->name }}">
            <i class="bi bi-trash me-1"></i>Delete Device
        </button>
        <small class="text-muted"><i class="bi bi-info-circle me-1"></i>Return the device before deleting.</small>
    @else
        <form method="POST" action="{{ route('admin.devices.destroy', $device) }}"
              onsubmit="return confirm('Delete device \'{{ addslashes($device->name) }}\'? This cannot be undone.')">
            @csrf @method('DELETE')
            <button type="submit" class="btn btn-sm btn-outline-danger">
                <i class="bi bi-trash me-1"></i>Delete Device
            </button>
        </form>
    @endif
</div>
@endcan

{{-- ── Assign Modal ── --}}
@if($isUserEquipment && $canManage && !$assigned)
<div class="modal fade" id="assignModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="{{ route('admin.devices.assign', $device) }}">
                @csrf
                <div class="modal-header py-2">
                    <h6 class="modal-title fw-semibold"><i class="bi bi-person-plus me-1"></i>Assign Device</h6>
                    <button type="button" class="btn-close btn-sm" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Employee <span class="text-danger">*</span></label>
                        <select name="employee_id" class="form-select" required>
                            <option value="">— Select Employee —</option>
                            @foreach($employees as $emp)
                            <option value="{{ $emp->id }}">{{ $emp->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="row g-2">
                        <div class="col-6">
                            <label class="form-label fw-semibold">Assigned Date <span class="text-danger">*</span></label>
                            <input type="date" name="assigned_date" class="form-control" value="{{ date('Y-m-d') }}" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold">Condition</label>
                            <select name="condition" class="form-select">
                                <option value="good">Good</option>
                                <option value="fair">Fair</option>
                                <option value="poor">Poor</option>
                            </select>
                        </div>
                    </div>
                    <div class="mt-2">
                        <label class="form-label">Notes</label>
                        <textarea name="notes" class="form-control" rows="2" maxlength="500"></textarea>
                    </div>
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm">Assign</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endif

{{-- ── Return Modal ── --}}
@if($isUserEquipment && $canManage && $assigned)
<div class="modal fade" id="returnModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="{{ route('admin.devices.return', $device) }}">
                @csrf
                <div class="modal-header py-2">
                    <h6 class="modal-title fw-semibold"><i class="bi bi-box-arrow-left me-1"></i>Return Device</h6>
                    <button type="button" class="btn-close btn-sm" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="small text-muted">Returning device from <strong>{{ $assigned->employee->name }}</strong>.</p>
                    <div class="row g-2">
                        <div class="col-6">
                            <label class="form-label fw-semibold">Return Date <span class="text-danger">*</span></label>
                            <input type="date" name="returned_date" class="form-control" value="{{ date('Y-m-d') }}" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold">Condition</label>
                            <select name="condition" class="form-select">
                                <option value="good">Good</option>
                                <option value="fair">Fair</option>
                                <option value="poor">Poor</option>
                            </select>
                        </div>
                    </div>
                    <div class="mt-2">
                        <label class="form-label">Notes</label>
                        <textarea name="notes" class="form-control" rows="2" maxlength="500"></textarea>
                    </div>
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning btn-sm">Confirm Return</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endif

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/qrcode/build/qrcode.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const canvas = document.getElementById('showQrCanvas');
    if (canvas) {
        QRCode.toCanvas(canvas, '{{ addslashes($device->asset_code ?? '') }}', { width: 60, margin: 1 }, function() {});
    }

    // ── DHCP IP Lookup ────────────────────────────────────────────
    const dhcpBtn = document.getElementById('dv-dhcp-btn');
    if (dhcpBtn) {
        dhcpBtn.addEventListener('click', function () {
            const mac = dhcpBtn.dataset.mac;
            const url = dhcpBtn.dataset.lookupUrl + '?mac=' + encodeURIComponent(mac);
            dhcpBtn.disabled = true;
            dhcpBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
            fetch(url)
                .then(r => r.json())
                .then(data => {
                    const display = document.getElementById('dv-ip-display');
                    if (data && data.ip) {
                        display.innerHTML = `<span class="font-monospace">${data.ip}</span>`
                            + ` <span class="badge bg-info text-dark ms-1" style="font-size:.65em"`
                            + ` title="DHCP from ${data.source || ''}, last seen ${data.last_seen || ''}">DHCP</span>`;
                    } else {
                        display.innerHTML = '<span class="text-muted small">Not found in DHCP leases</span>';
                    }
                })
                .catch(() => {
                    document.getElementById('dv-ip-display').innerHTML = '<span class="text-danger small">Lookup failed</span>';
                })
                .finally(() => {
                    dhcpBtn.disabled = false;
                    dhcpBtn.innerHTML = '<i class="bi bi-search"></i>';
                });
        });
    }
});

function openWebBrowser(deviceId, ip) {
    const proto = document.querySelector(`input[name="wb-proto-${deviceId}"]:checked`)?.value ?? 'http';
    const port  = document.getElementById(`wb-port-${deviceId}`)?.value ?? '80';
    const path  = document.getElementById(`wb-path-${deviceId}`)?.value ?? '/';

    const defaultPort = proto === 'https' ? '443' : '80';
    const portStr     = port && port !== defaultPort ? `:${port}` : '';
    const url         = `${proto}://${ip}${portStr}${path.startsWith('/') ? path : '/' + path}`;

    const browserUrl  = @json(route('admin.browser.index'));
    window.open(browserUrl + '?url=' + encodeURIComponent(url), '_blank');
}
</script>
@endpush

@endsection
