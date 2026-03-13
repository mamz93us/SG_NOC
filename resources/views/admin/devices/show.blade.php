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
                Assigned to <strong>{{ $assigned->employee->name }}</strong>
                @if($assigned->employee->employee_id)
                <span class="text-muted">({{ $assigned->employee->employee_id }})</span>
                @endif
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
                        <td>{{ $device->deviceModel?->name ? (($device->deviceModel->manufacturer ? $device->deviceModel->manufacturer . ' ' : '') . $device->deviceModel->name) : ($device->model ?: '—') }}</td></tr>
                    <tr><th class="text-muted ps-3">Serial</th>
                        <td class="font-monospace">{{ $device->serial_number ?: '—' }}</td></tr>
                    <tr><th class="text-muted ps-3">IP</th>
                        <td class="font-monospace">
                            {{ $device->ip_address ?: '—' }}
                            @can('manage-network-settings')
                            <a href="{{ route('admin.network.ip-reservations.create', ['device_id' => $device->id]) }}" class="btn btn-sm btn-outline-primary py-0 px-1 ms-1" style="font-size:11px">
                                <i class="bi bi-plus"></i>
                            </a>
                            @endcan
                        </td></tr>
                    <tr><th class="text-muted ps-3">MAC</th>
                        <td class="font-monospace">{{ $device->mac_address ?: '—' }}</td></tr>
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
        <div class="card shadow-sm mb-3">
            <div class="card-header py-2">
                <h6 class="mb-0 fw-semibold"><i class="bi bi-microsoft me-2"></i>Azure / Intune</h6>
            </div>
            <div class="card-body p-0">
                <table class="table table-sm table-borderless small mb-0">
                    <tr><th class="text-muted ps-3" style="width:40%">Display Name</th>
                        <td>{{ $device->azureDevice->display_name }}</td></tr>
                    <tr><th class="text-muted ps-3">UPN</th>
                        <td>{{ $device->azureDevice->upn ?: '—' }}</td></tr>
                    <tr><th class="text-muted ps-3">OS</th>
                        <td>{{ $device->azureDevice->os }} {{ $device->azureDevice->os_version }}</td></tr>
                    <tr><th class="text-muted ps-3">Last Sync</th>
                        <td>{{ $device->azureDevice->last_sync_at?->format('d M Y H:i') ?: '—' }}</td></tr>
                    <tr><th class="text-muted ps-3">Link Status</th>
                        <td>
                            @php $az = $device->azureDevice; @endphp
                            <span class="badge bg-{{ $az->link_status === 'linked' ? 'success' : ($az->link_status === 'pending' ? 'warning text-dark' : 'secondary') }}">
                                {{ ucfirst($az->link_status) }}
                            </span>
                        </td></tr>
                </table>
            </div>
        </div>
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

@can('manage-assets')
<div class="mt-2">
    <form method="POST" action="{{ route('admin.devices.destroy', $device) }}"
          onsubmit="return confirm('Delete device \'{{ addslashes($device->name) }}\'? This cannot be undone.')">
        @csrf @method('DELETE')
        <button type="submit" class="btn btn-sm btn-outline-danger">
            <i class="bi bi-trash me-1"></i>Delete Device
        </button>
    </form>
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
                            <option value="{{ $emp->id }}">{{ $emp->name }}{{ $emp->employee_id ? ' ('.$emp->employee_id.')' : '' }}</option>
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
});
</script>
@endpush

@endsection
