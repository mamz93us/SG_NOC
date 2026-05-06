@extends('layouts.admin')
@section('title', 'RADIUS MAC Registry')

@section('content')
<div class="container-fluid py-4">

    {{-- ── Header ──────────────────────────────────────────────────── --}}
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-0"><i class="bi bi-fingerprint me-2 text-primary"></i>RADIUS MAC Registry</h4>
            <small class="text-muted">Every MAC FreeRADIUS knows about, with its resolved status and VLAN.</small>
        </div>
        <div class="d-flex gap-2">
            <form action="{{ route('admin.radius.macs.sync') }}" method="POST" class="d-inline"
                  onsubmit="return confirm('Sync MACs from devices table into the RADIUS registry now?');">
                @csrf
                <button type="submit" class="btn btn-sm btn-outline-info"
                        title="Pull MACs from devices (phones, APs, printers) into device_macs">
                    <i class="bi bi-arrow-repeat me-1"></i>Sync from Inventory
                </button>
            </form>
            <a href="{{ route('admin.radius.nas.index') }}" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-router me-1"></i>NAS Clients
            </a>
            <a href="{{ route('admin.radius.vlan.index') }}" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-diagram-3 me-1"></i>VLAN Policy
            </a>
            <a href="{{ route('admin.radius.macs.create') }}" class="btn btn-sm btn-primary">
                <i class="bi bi-plus-lg me-1"></i>Add MAC
            </a>
        </div>
    </div>

    {{-- ── Tip ──────────────────────────────────────────────────────── --}}
    <div class="alert alert-info border-0 small mb-4">
        <i class="bi bi-info-circle me-1"></i>
        Intune-synced PCs (run by <code>intune:sync-net-data</code>) and inventory MACs
        (this page's <strong>Sync from Inventory</strong> button, plus the hourly job)
        land here automatically. The <strong>VLAN</strong> column shows what each MAC
        would be assigned right now, based on
        <a href="{{ route('admin.radius.vlan.index') }}" class="alert-link">branch policy</a>
        (e.g. <em>device_type=phone → VLAN 200</em> auto-routes every IP phone in that branch).
    </div>

    {{-- ── KPI cards ────────────────────────────────────────────────── --}}
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center py-3">
                <div class="display-6 fw-bold text-primary">{{ number_format($stats['total']) }}</div>
                <div class="small text-muted">Total registered</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center py-3">
                <div class="display-6 fw-bold text-success">{{ number_format($stats['allowed']) }}</div>
                <div class="small text-muted">RADIUS allowed</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center py-3">
                <div class="display-6 fw-bold text-danger">{{ number_format($stats['denied']) }}</div>
                <div class="small text-muted">RADIUS denied</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center py-3">
                <div class="display-6 fw-bold text-warning">{{ number_format($stats['override']) }}</div>
                <div class="small text-muted">With override</div>
            </div>
        </div>
    </div>

    {{-- ── Filters ──────────────────────────────────────────────────── --}}
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body py-3">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small mb-1">Search</label>
                    <input type="text" name="search" class="form-control form-control-sm"
                           placeholder="MAC, device, owner UPN…"
                           value="{{ request('search') }}">
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1">Status</label>
                    <select name="status" class="form-select form-select-sm">
                        <option value="">All</option>
                        <option value="allowed"  @selected(request('status') === 'allowed')>RADIUS allowed</option>
                        <option value="denied"   @selected(request('status') === 'denied')>RADIUS denied</option>
                        <option value="override" @selected(request('status') === 'override')>Has override</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1">Branch</label>
                    <select name="branch" class="form-select form-select-sm">
                        <option value="">All branches</option>
                        @foreach($branches as $b)
                            <option value="{{ $b->id }}" @selected((int) request('branch') === $b->id)>
                                {{ $b->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1">Adapter</label>
                    <select name="adapter" class="form-select form-select-sm">
                        <option value="">All</option>
                        @foreach(['ethernet','wifi','usb_ethernet','management','virtual'] as $a)
                            <option value="{{ $a }}" @selected(request('adapter') === $a)>{{ $a }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1">Source</label>
                    <select name="source" class="form-select form-select-sm">
                        <option value="">All</option>
                        @foreach(['intune','snmp','dhcp','arp','manual','import'] as $s)
                            <option value="{{ $s }}" @selected(request('source') === $s)>{{ ucfirst($s) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-1 d-flex gap-1">
                    <button type="submit" class="btn btn-primary btn-sm flex-grow-1"><i class="bi bi-search"></i></button>
                    <a href="{{ route('admin.radius.macs.index') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-x-lg"></i></a>
                </div>
            </form>
        </div>
    </div>

    {{-- ── Table ────────────────────────────────────────────────────── --}}
    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            @if($macs->isEmpty())
            <div class="text-center py-5 text-muted">
                <i class="bi bi-fingerprint fs-1 d-block mb-2 opacity-25"></i>
                No MACs match your filters.<br>
                <small>Try clicking <strong>Sync from Inventory</strong> to pull phones / APs / printers, or <strong>Add MAC</strong> to register one manually.</small>
            </div>
            @else
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" style="font-size:.86rem">
                    <thead class="table-light sticky-top">
                        <tr>
                            <th class="ps-3" style="width:170px">MAC Address</th>
                            <th style="width:110px">Adapter</th>
                            <th>Owner</th>
                            <th style="width:120px">Branch</th>
                            <th style="width:90px">Source</th>
                            <th style="width:100px">RADIUS</th>
                            <th style="width:120px">VLAN</th>
                            <th style="width:60px"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($macs as $mac)
                            @php
                                $r = $resolved[$mac->id] ?? ['vlan' => null, 'source' => 'none', 'reason' => ''];
                                $ovr = $mac->radiusOverride;
                                $allowed = $mac->is_active && (!$ovr || $ovr->radius_enabled);
                                $branchName = $mac->device?->branch?->name
                                    ?: $mac->azureDevice?->device?->branch?->name;
                            @endphp
                            <tr>
                                <td class="ps-3 font-monospace fw-semibold">{{ $mac->mac_address }}</td>
                                <td>
                                    <span class="badge bg-{{ $mac->adapterTypeBadge() }}">
                                        {{ $mac->adapterTypeLabel() }}
                                    </span>
                                </td>
                                <td>
                                    @if($mac->azureDevice)
                                        <i class="bi bi-microsoft text-primary me-1" style="font-size:.75rem"></i>
                                        {{ $mac->azureDevice->display_name }}
                                        @if($mac->azureDevice->upn)
                                            <div class="text-muted small">{{ $mac->azureDevice->upn }}</div>
                                        @endif
                                    @elseif($mac->device)
                                        <i class="bi bi-hdd text-secondary me-1" style="font-size:.75rem"></i>
                                        {{ $mac->device->name }}
                                        <span class="text-muted small">({{ $mac->device->type }})</span>
                                    @else
                                        <span class="text-muted">— manual —</span>
                                    @endif
                                </td>
                                <td class="text-muted small">{{ $branchName ?: '—' }}</td>
                                <td>
                                    <span class="badge bg-{{ $mac->sourceBadge() }} text-dark" style="font-size:.7rem">
                                        {{ ucfirst($mac->source) }}
                                    </span>
                                </td>
                                <td>
                                    @if($allowed)
                                        <span class="badge bg-success">Allow</span>
                                    @else
                                        <span class="badge bg-danger">Deny</span>
                                    @endif
                                    @if($ovr)
                                        <i class="bi bi-pencil-fill text-warning ms-1" style="font-size:.7rem" title="Has override"></i>
                                    @endif
                                </td>
                                <td>
                                    @if($r['vlan'] !== null)
                                        <span class="badge bg-primary">VLAN {{ $r['vlan'] }}</span>
                                        <div class="text-muted" style="font-size:.7rem">{{ $r['source'] }}</div>
                                    @else
                                        <span class="text-muted small" title="{{ $r['reason'] }}">switch default</span>
                                    @endif
                                </td>
                                <td class="text-end pe-3">
                                    @if(in_array($mac->source, ['manual','import'], true))
                                        <form action="{{ route('admin.radius.macs.destroy', $mac) }}" method="POST" class="d-inline"
                                              onsubmit="return confirm('Delete {{ $mac->mac_address }} from RADIUS registry?');">
                                            @csrf
                                            @method('DELETE')
                                            <button class="btn btn-sm btn-outline-danger" title="Remove">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if($macs->hasPages())
                <div class="px-3 py-2 border-top">{{ $macs->links() }}</div>
            @endif
            @endif
        </div>
    </div>

</div>
@endsection
