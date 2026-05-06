@extends('layouts.portal')

@section('title', 'My Assets')

@section('content')
<style>
    .assets-hero {
        background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
        color: #fff;
        border-radius: 22px;
        padding: 26px 30px;
        margin-bottom: 24px;
        box-shadow: 0 10px 26px rgba(79, 172, 254, 0.25);
    }
    .assets-hero h3 { margin: 0; font-weight: 700; }

    .stat-pill {
        background: rgba(255,255,255,.2);
        border-radius: 14px;
        padding: 10px 16px;
        display: flex; align-items: center; gap: 10px;
        min-width: 130px;
    }
    .stat-pill .stat-num { font-size: 24px; font-weight: 700; line-height: 1; }
    .stat-pill .stat-lbl { font-size: 11px; opacity: .92; text-transform: uppercase; letter-spacing: .5px; }

    .asset-card { border-left: 4px solid var(--bs-primary); }
    .asset-card.returned { opacity: .6; border-left-color: var(--bs-secondary); }

    .section-title {
        display: flex; align-items: center; gap: 10px;
        font-weight: 700; margin: 24px 0 12px;
    }
    .section-title .count-badge {
        background: var(--bs-primary-bg-subtle);
        color: var(--bs-primary-text-emphasis);
        border-radius: 100px; padding: 2px 10px; font-size: 12px;
    }

    .empty-section {
        text-align: center; padding: 28px 16px;
        background: var(--bs-tertiary-bg);
        border-radius: 14px; color: var(--bs-secondary-color);
    }
</style>

@php
    $fmt = fn($d) => $d ? \Carbon\Carbon::parse($d)->format('M j, Y') : '—';
@endphp

<div class="d-flex justify-content-between align-items-center mb-3">
    <a href="{{ route('portal.index') }}" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i>Back to Portal
    </a>
</div>

<div class="assets-hero d-flex flex-column flex-md-row align-items-md-center gap-3">
    <div class="flex-grow-1">
        <h3><i class="bi bi-box-seam me-2"></i>My Assets</h3>
        <div class="opacity-90 mt-1">Everything currently signed out in your name.</div>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <div class="stat-pill">
            <i class="bi bi-laptop fs-4"></i>
            <div><div class="stat-num">{{ $activeCounts['it_assets'] }}</div><div class="stat-lbl">IT Assets</div></div>
        </div>
        <div class="stat-pill">
            <i class="bi bi-box fs-4"></i>
            <div><div class="stat-num">{{ $activeCounts['items'] }}</div><div class="stat-lbl">Items</div></div>
        </div>
        <div class="stat-pill">
            <i class="bi bi-headset fs-4"></i>
            <div><div class="stat-num">{{ $activeCounts['accessories'] }}</div><div class="stat-lbl">Accessories</div></div>
        </div>
        <div class="stat-pill">
            <i class="bi bi-key fs-4"></i>
            <div><div class="stat-num">{{ $activeCounts['licenses'] }}</div><div class="stat-lbl">Licenses</div></div>
        </div>
    </div>
</div>

@if(!$employee)
    <div class="alert alert-warning">
        <i class="bi bi-exclamation-triangle me-2"></i>
        Your account (<strong>{{ $user->email }}</strong>) is not linked to an employee record yet.
        Once IT links your account, the assets assigned to you will appear here.
    </div>
@else

    {{-- ─── IT Assets (laptops, monitors, phones via Device) ─── --}}
    <h5 class="section-title">
        <i class="bi bi-laptop text-primary"></i> IT Assets
        <span class="count-badge">{{ $itAssets->count() }}</span>
    </h5>
    @if($itAssets->isEmpty())
        <div class="empty-section">No IT assets assigned to you.</div>
    @else
        <div class="row g-3">
            @foreach($itAssets as $a)
                <div class="col-12 col-md-6 col-lg-4">
                    <div class="card asset-card shadow-sm h-100 {{ $a->returned_date ? 'returned' : '' }}">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <div>
                                    <div class="fw-semibold">{{ $a->device->name ?? 'Unknown device' }}</div>
                                    <div class="small text-muted">
                                        {{ trim(($a->device->manufacturer ?? '') . ' ' . ($a->device->model ?? '')) ?: '—' }}
                                    </div>
                                </div>
                                @if($a->returned_date)
                                    <span class="badge bg-secondary">Returned</span>
                                @else
                                    <span class="badge bg-success">Active</span>
                                @endif
                            </div>
                            <table class="table table-sm table-borderless mb-0 small">
                                @if($a->device?->asset_code)
                                    <tr><th class="text-muted ps-0" style="width:42%">Asset tag</th>
                                        <td class="font-monospace">{{ $a->device->asset_code }}</td></tr>
                                @endif
                                @if($a->device?->serial_number)
                                    <tr><th class="text-muted ps-0">Serial</th>
                                        <td class="font-monospace">{{ $a->device->serial_number }}</td></tr>
                                @endif
                                @if($a->device?->type)
                                    <tr><th class="text-muted ps-0">Type</th>
                                        <td>{{ ucfirst($a->device->type) }}</td></tr>
                                @endif
                                <tr><th class="text-muted ps-0">Assigned</th>
                                    <td>{{ $fmt($a->assigned_date) }}</td></tr>
                                @if($a->returned_date)
                                    <tr><th class="text-muted ps-0">Returned</th>
                                        <td>{{ $fmt($a->returned_date) }}</td></tr>
                                @endif
                                @if($a->condition)
                                    <tr><th class="text-muted ps-0">Condition</th>
                                        <td><span class="badge {{ $a->conditionBadgeClass() }}">{{ ucfirst($a->condition) }}</span></td></tr>
                                @endif
                            </table>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    {{-- ─── Personal Items (non-Device assets) ─── --}}
    <h5 class="section-title">
        <i class="bi bi-box text-success"></i> Items
        <span class="count-badge">{{ $items->count() }}</span>
    </h5>
    @if($items->isEmpty())
        <div class="empty-section">No personal items assigned to you.</div>
    @else
        <div class="row g-3">
            @foreach($items as $it)
                <div class="col-12 col-md-6 col-lg-4">
                    <div class="card asset-card shadow-sm h-100 {{ $it->returned_date ? 'returned' : '' }}"
                         style="border-left-color: var(--bs-success);">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <div>
                                    <div class="fw-semibold">{{ $it->item_name }}</div>
                                    <div class="small text-muted">{{ $it->item_type ?: '—' }}</div>
                                </div>
                                @if($it->returned_date)
                                    <span class="badge bg-secondary">Returned</span>
                                @else
                                    <span class="badge bg-success">Active</span>
                                @endif
                            </div>
                            <table class="table table-sm table-borderless mb-0 small">
                                @if($it->model)
                                    <tr><th class="text-muted ps-0" style="width:42%">Model</th>
                                        <td>{{ $it->model }}</td></tr>
                                @endif
                                @if($it->serial_number)
                                    <tr><th class="text-muted ps-0">Serial</th>
                                        <td class="font-monospace">{{ $it->serial_number }}</td></tr>
                                @endif
                                <tr><th class="text-muted ps-0">Assigned</th>
                                    <td>{{ $fmt($it->assigned_date) }}</td></tr>
                                @if($it->returned_date)
                                    <tr><th class="text-muted ps-0">Returned</th>
                                        <td>{{ $fmt($it->returned_date) }}</td></tr>
                                @endif
                                @if($it->condition)
                                    <tr><th class="text-muted ps-0">Condition</th>
                                        <td>{{ ucfirst($it->condition) }}</td></tr>
                                @endif
                            </table>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    {{-- ─── Accessories (headsets, cables, etc.) ─── --}}
    <h5 class="section-title">
        <i class="bi bi-headset text-warning"></i> Accessories
        <span class="count-badge">{{ $accessories->count() }}</span>
    </h5>
    @if($accessories->isEmpty())
        <div class="empty-section">No accessories assigned to you.</div>
    @else
        <div class="row g-3">
            @foreach($accessories as $acc)
                <div class="col-12 col-md-6 col-lg-4">
                    <div class="card asset-card shadow-sm h-100 {{ $acc->returned_date ? 'returned' : '' }}"
                         style="border-left-color: var(--bs-warning);">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <div>
                                    <div class="fw-semibold">{{ $acc->accessory->name ?? 'Accessory' }}</div>
                                    <div class="small text-muted">{{ $acc->accessory->category ?? '—' }}</div>
                                </div>
                                @if($acc->returned_date)
                                    <span class="badge bg-secondary">Returned</span>
                                @else
                                    <span class="badge bg-success">Active</span>
                                @endif
                            </div>
                            <table class="table table-sm table-borderless mb-0 small">
                                <tr><th class="text-muted ps-0" style="width:42%">Assigned</th>
                                    <td>{{ $fmt($acc->assigned_date) }}</td></tr>
                                @if($acc->returned_date)
                                    <tr><th class="text-muted ps-0">Returned</th>
                                        <td>{{ $fmt($acc->returned_date) }}</td></tr>
                                @endif
                            </table>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    {{-- ─── Licenses ─── --}}
    <h5 class="section-title">
        <i class="bi bi-key text-info"></i> Licenses
        <span class="count-badge">{{ $licenses->count() }}</span>
    </h5>
    @if($licenses->isEmpty())
        <div class="empty-section">No software licenses assigned to you.</div>
    @else
        <div class="row g-3">
            @foreach($licenses as $lic)
                <div class="col-12 col-md-6 col-lg-4">
                    <div class="card asset-card shadow-sm h-100"
                         style="border-left-color: var(--bs-info);">
                        <div class="card-body">
                            <div class="fw-semibold mb-1">{{ $lic->license->license_name ?? 'License' }}</div>
                            <div class="small text-muted mb-2">
                                {{ $lic->license->vendor ?? '' }}
                                @if($lic->license?->license_type) &middot; {{ ucfirst($lic->license->license_type) }} @endif
                            </div>
                            <table class="table table-sm table-borderless mb-0 small">
                                <tr><th class="text-muted ps-0" style="width:42%">Assigned</th>
                                    <td>{{ $fmt($lic->assigned_date) }}</td></tr>
                                @if($lic->license?->expiry_date)
                                    <tr><th class="text-muted ps-0">Expires</th>
                                        <td>{{ $fmt($lic->license->expiry_date) }}</td></tr>
                                @endif
                            </table>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    <div class="mt-4 text-center small text-muted">
        <i class="bi bi-info-circle me-1"></i>
        See something wrong? Contact IT — this list is read-only.
    </div>
@endif
@endsection
