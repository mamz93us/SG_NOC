@extends('layouts.admin')
@section('title', $azureDevice->display_name . ' — Azure Device')

@section('content')
<div class="container-fluid py-4">

    {{-- ── Header ─────────────────────────────────────────────────── --}}
    <div class="d-flex align-items-center gap-3 mb-4">
        <a href="{{ route('admin.itam.azure.index') }}" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left"></i>
        </a>
        <div>
            <h4 class="mb-0">
                <i class="bi bi-microsoft me-2 text-primary"></i>{{ $azureDevice->display_name }}
            </h4>
            <small class="text-muted font-monospace">{{ $azureDevice->azure_device_id }}</small>
        </div>
        <span class="badge bg-{{ $azureDevice->linkStatusBadgeClass() }} ms-auto fs-6">
            {{ $azureDevice->linkStatusLabel() }}
        </span>
    </div>

    @if(session('success'))<div class="alert alert-success py-2">{{ session('success') }}</div>@endif
    @if(session('error'))<div class="alert alert-danger py-2">{{ session('error') }}</div>@endif

    <div class="row g-4">

        {{-- ── Left Column ─────────────────────────────────────────── --}}
        <div class="col-lg-4">

            {{-- Device Identity --}}
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header fw-semibold">
                    <i class="bi bi-pc-display me-2 text-primary"></i>Device Identity
                </div>
                <div class="card-body p-0">
                    <table class="table table-sm mb-0">
                        <tbody>
                            <tr><th class="ps-3 text-muted fw-normal" style="width:40%">Hostname</th>
                                <td class="fw-semibold">{{ $azureDevice->display_name }}</td></tr>
                            <tr><th class="ps-3 text-muted fw-normal">OS</th>
                                <td>{{ $azureDevice->os }}{{ $azureDevice->os_version ? ' ' . $azureDevice->os_version : '' }}</td></tr>
                            <tr><th class="ps-3 text-muted fw-normal">Manufacturer</th>
                                <td>{{ $azureDevice->manufacturer ?: '—' }}</td></tr>
                            <tr><th class="ps-3 text-muted fw-normal">Model</th>
                                <td>{{ $azureDevice->model ?: '—' }}</td></tr>
                            <tr><th class="ps-3 text-muted fw-normal">Serial Number</th>
                                <td class="font-monospace small">{{ $azureDevice->serial_number ?: '—' }}</td></tr>
                            <tr><th class="ps-3 text-muted fw-normal">Device Type</th>
                                <td>{{ $azureDevice->device_type ?: '—' }}</td></tr>
                            <tr><th class="ps-3 text-muted fw-normal">Assigned User</th>
                                <td class="small">{{ $azureDevice->upn ?: '—' }}</td></tr>
                            <tr><th class="ps-3 text-muted fw-normal">Enrolled</th>
                                <td class="small">{{ $azureDevice->enrolled_date?->format('d M Y') ?: '—' }}</td></tr>
                            <tr><th class="ps-3 text-muted fw-normal">Last Active</th>
                                <td class="small">{{ $azureDevice->last_activity_at?->diffForHumans() ?: '—' }}</td></tr>
                            <tr><th class="ps-3 text-muted fw-normal">Last Intune Sync</th>
                                <td class="small">{{ $azureDevice->last_sync_at?->diffForHumans() ?: '—' }}</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- Linked ITAM Asset --}}
            @if($azureDevice->device)
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header fw-semibold">
                    <i class="bi bi-link-45deg me-2 text-success"></i>Linked ITAM Asset
                </div>
                <div class="card-body p-0">
                    <table class="table table-sm mb-0">
                        <tbody>
                            <tr><th class="ps-3 text-muted fw-normal" style="width:40%">Asset Code</th>
                                <td class="font-monospace fw-semibold">
                                    <a href="{{ route('admin.devices.show', $azureDevice->device) }}">
                                        {{ $azureDevice->device->asset_code ?: '—' }}
                                    </a>
                                </td></tr>
                            <tr><th class="ps-3 text-muted fw-normal">Name</th>
                                <td>{{ $azureDevice->device->name }}</td></tr>
                            <tr><th class="ps-3 text-muted fw-normal">Branch</th>
                                <td>{{ $azureDevice->device->branch?->name ?: '—' }}</td></tr>
                            <tr><th class="ps-3 text-muted fw-normal">Status</th>
                                <td><span class="badge bg-secondary">{{ ucfirst($azureDevice->device->status ?? 'unknown') }}</span></td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="card-footer bg-transparent">
                    <a href="{{ route('admin.devices.show', $azureDevice->device) }}" class="btn btn-sm btn-outline-primary">
                        <i class="bi bi-box-arrow-up-right me-1"></i>Open Asset Page
                    </a>
                </div>
            </div>
            @else
            <div class="card border-0 shadow-sm mb-4 border-warning border-opacity-50">
                <div class="card-body text-center py-4 text-muted">
                    <i class="bi bi-link-45deg fs-2 d-block mb-2"></i>
                    <div class="small">Not linked to an ITAM asset</div>
                    <a href="{{ route('admin.itam.azure.create-device', $azureDevice) }}"
                       class="btn btn-sm btn-outline-primary mt-2">
                        <i class="bi bi-plus-lg me-1"></i>Create Asset
                    </a>
                </div>
            </div>
            @endif

        </div>

        {{-- ── Right Column ─────────────────────────────────────────── --}}
        <div class="col-lg-8">

            {{-- ── Network & Hardware (from Intune script) ─────────── --}}
            @php $hasNetData = $azureDevice->net_data_synced_at !== null; @endphp
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header d-flex align-items-center justify-content-between">
                    <span class="fw-semibold">
                        <i class="bi bi-cpu me-2 text-info"></i>Hardware &amp; Network Data
                    </span>
                    <div class="d-flex align-items-center gap-2">
                        @if($hasNetData)
                            <small class="text-muted">
                                <i class="bi bi-clock me-1"></i>Synced {{ $azureDevice->net_data_synced_at->diffForHumans() }}
                            </small>
                        @else
                            <span class="badge bg-secondary">Not yet synced</span>
                        @endif
                        @can('manage-itam')
                        @if($azureDevice->intune_managed_device_id)
                        <form method="POST" action="{{ route('admin.itam.azure.sync-hw-data', $azureDevice) }}" class="d-inline">
                            @csrf
                            <button type="submit" class="btn btn-sm btn-outline-info py-0 px-2"
                                    title="Pull latest script result from Intune for this device"
                                    onclick="return confirm('Sync hardware data from Intune for {{ addslashes($azureDevice->display_name) }}?')">
                                <i class="bi bi-arrow-repeat me-1"></i>Sync HW
                            </button>
                        </form>
                        @else
                        <span class="badge bg-warning text-dark" title="Run itam:sync-devices to populate Intune ID">No Intune ID</span>
                        @endif
                        @endcan
                    </div>
                </div>
                <div class="card-body">
                    @if(!$hasNetData)
                    <div class="alert alert-info py-2 small mb-0">
                        <i class="bi bi-info-circle me-1"></i>
                        Deploy <code>NOC-DeviceInfo.ps1</code> via Intune and run
                        <code>php artisan intune:sync-net-data</code> to populate this section.
                    </div>
                    @else
                    <div class="row g-3">

                        {{-- CPU --}}
                        <div class="col-md-12">
                            <div class="d-flex align-items-center gap-2">
                                <i class="bi bi-cpu-fill text-primary fs-5"></i>
                                <div>
                                    <div class="small text-muted">Processor</div>
                                    <div class="fw-semibold">{{ $azureDevice->cpu_name ?: '—' }}</div>
                                </div>
                            </div>
                        </div>

                        {{-- IP Address --}}
                        <div class="col-md-6">
                            <div class="d-flex align-items-center gap-2">
                                <i class="bi bi-globe text-success fs-5"></i>
                                <div>
                                    <div class="small text-muted">IP Address
                                        @if($monitoredHost)
                                            <span class="badge bg-info text-dark ms-1" style="font-size:.65rem">SNMP</span>
                                        @elseif($ipAddress && $azureDevice->device?->ip_address)
                                            <span class="badge bg-secondary ms-1" style="font-size:.65rem">ITAM</span>
                                        @endif
                                    </div>
                                    @if($ipAddress)
                                        <span class="font-monospace fw-semibold">{{ $ipAddress }}</span>
                                        <a href="{{ route('admin.browser.index') }}?url=http://{{ $ipAddress }}" target="_blank"
                                           class="btn btn-link btn-sm p-0 ms-1" title="Open Web Browser">
                                            <i class="bi bi-globe2 small"></i>
                                        </a>
                                    @else
                                        <span class="text-muted">Not detected</span>
                                    @endif
                                </div>
                            </div>
                        </div>

                        {{-- TeamViewer --}}
                        <div class="col-md-6">
                            <div class="d-flex align-items-center gap-2">
                                <i class="bi bi-display-fill text-warning fs-5"></i>
                                <div>
                                    <div class="small text-muted">TeamViewer</div>
                                    @if($azureDevice->teamviewer_id)
                                        <span class="font-monospace fw-semibold">{{ $azureDevice->teamviewer_id }}</span>
                                        <div class="small text-muted">v{{ $azureDevice->tv_version }}</div>
                                    @else
                                        <span class="text-muted">Not installed</span>
                                    @endif
                                </div>
                            </div>
                        </div>

                        <div class="col-12"><hr class="my-1"></div>

                        {{-- Ethernet MAC --}}
                        <div class="col-md-6">
                            <div class="d-flex align-items-start gap-2">
                                <i class="bi bi-ethernet fs-5 text-primary mt-1"></i>
                                <div>
                                    <div class="small text-muted">Ethernet MAC <span class="badge bg-primary" style="font-size:.6rem">LAN</span></div>
                                    @if($azureDevice->ethernet_mac)
                                        <span class="font-monospace fw-semibold">{{ $azureDevice->ethernet_mac }}</span>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </div>
                            </div>
                        </div>

                        {{-- Wi-Fi MAC --}}
                        <div class="col-md-6">
                            <div class="d-flex align-items-start gap-2">
                                <i class="bi bi-wifi fs-5 text-info mt-1"></i>
                                <div>
                                    <div class="small text-muted">Wi-Fi MAC <span class="badge bg-info text-dark" style="font-size:.6rem">WLAN</span></div>
                                    @if($azureDevice->wifi_mac)
                                        <span class="font-monospace fw-semibold">{{ $azureDevice->wifi_mac }}</span>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </div>
                            </div>
                        </div>

                        {{-- USB / Dock Ethernet adapters --}}
                        @php $usbAdapters = $azureDevice->usb_eth_decoded(); @endphp
                        @if(count($usbAdapters) > 0)
                        <div class="col-12">
                            <div class="d-flex align-items-start gap-2">
                                <i class="bi bi-usb-symbol fs-5 text-warning mt-1"></i>
                                <div class="flex-grow-1">
                                    <div class="small text-muted mb-1">USB / Dock Ethernet Adapters <span class="badge bg-warning text-dark" style="font-size:.6rem">{{ count($usbAdapters) }}</span></div>
                                    <table class="table table-sm table-bordered mb-0" style="font-size:.82rem">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Name</th>
                                                <th>MAC</th>
                                                <th>Hardware</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach($usbAdapters as $usb)
                                            <tr>
                                                <td>{{ $usb['name'] ?? '—' }}</td>
                                                <td class="font-monospace">{{ $usb['mac'] ?? '—' }}</td>
                                                <td class="text-muted small">{{ $usb['desc'] ?? '—' }}</td>
                                            </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                        @endif

                    </div>
                    @endif
                </div>
            </div>

            {{-- ── MAC Address Registry ─────────────────────────────── --}}
            @if($azureDevice->macs->count() > 0)
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header fw-semibold">
                    <i class="bi bi-fingerprint me-2 text-success"></i>
                    MAC Address Registry
                    <span class="badge bg-secondary ms-2">{{ $azureDevice->macs->count() }}</span>
                    <a href="{{ route('admin.itam.mac-address') }}" class="btn btn-link btn-sm float-end py-0">
                        View All
                    </a>
                </div>
                <div class="card-body p-0">
                    <table class="table table-sm table-hover mb-0" style="font-size:.83rem">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-3">Type</th>
                                <th>MAC Address</th>
                                <th>Adapter Name</th>
                                <th>Source</th>
                                <th>Last Seen</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($azureDevice->macs->sortBy('adapter_type') as $mac)
                            <tr>
                                <td class="ps-3">
                                    <span class="badge bg-{{ $mac->adapterTypeBadge() }}">{{ $mac->adapterTypeLabel() }}</span>
                                </td>
                                <td class="font-monospace fw-semibold">{{ $mac->mac_address }}</td>
                                <td class="text-muted">{{ $mac->adapter_name ?: '—' }}</td>
                                <td><span class="badge bg-{{ $mac->sourceBadge() }} text-dark" style="font-size:.65rem">{{ ucfirst($mac->source) }}</span></td>
                                <td class="text-muted">{{ $mac->last_seen_at?->diffForHumans() ?: '—' }}</td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
            @endif

            {{-- ── SNMP Monitoring Status ────────────────────────────── --}}
            @if($monitoredHost)
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header fw-semibold">
                    <i class="bi bi-activity me-2 text-success"></i>SNMP Monitoring
                </div>
                <div class="card-body p-0">
                    <table class="table table-sm mb-0">
                        <tbody>
                            <tr><th class="ps-3 text-muted fw-normal" style="width:35%">Host Name</th>
                                <td>{{ $monitoredHost->name }}</td></tr>
                            <tr><th class="ps-3 text-muted fw-normal">IP Address</th>
                                <td class="font-monospace">{{ $monitoredHost->ip }}</td></tr>
                            <tr><th class="ps-3 text-muted fw-normal">Status</th>
                                <td>
                                    <span class="badge bg-{{ $monitoredHost->status === 'up' ? 'success' : ($monitoredHost->status === 'down' ? 'danger' : 'secondary') }}">
                                        {{ ucfirst($monitoredHost->status ?? 'unknown') }}
                                    </span>
                                </td></tr>
                            <tr><th class="ps-3 text-muted fw-normal">SNMP Enabled</th>
                                <td>
                                    @if($monitoredHost->snmp_enabled)
                                        <i class="bi bi-check-circle-fill text-success"></i> Yes
                                    @else
                                        <span class="text-muted">No</span>
                                    @endif
                                </td></tr>
                            <tr><th class="ps-3 text-muted fw-normal">Last Checked</th>
                                <td class="small">{{ $monitoredHost->last_checked_at?->diffForHumans() ?: '—' }}</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
            @endif

        </div>{{-- /col-lg-8 --}}
    </div>{{-- /row --}}
</div>
@endsection
