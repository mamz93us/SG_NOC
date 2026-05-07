@extends('layouts.admin')

@section('title', 'SNMP Devices')

@section('content')
<div class="container-fluid py-3">

    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h4 class="mb-0">SNMP Devices</h4>
            <small class="text-muted">
                Devices each branch's Telegraf polls. Branches sync this list every 5 min.
            </small>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('admin.snmp-devices.discovered') }}"
               class="btn btn-sm btn-outline-info position-relative">
                <i class="bi bi-search me-1"></i>Discovered
                @if($pendingCount > 0)
                    <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">
                        {{ $pendingCount }}
                    </span>
                @endif
            </a>
            <a href="{{ route('admin.snmp-devices.create') }}" class="btn btn-sm btn-primary">
                <i class="bi bi-plus-lg me-1"></i>Add device
            </a>
        </div>
    </div>

    @if(session('success'))
        <div class="alert alert-success alert-dismissible fade show py-2">
            {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    {{-- ─── Filters ─────────────────────────────────────────────────── --}}
    <form method="GET" class="card mb-3">
        <div class="card-body py-2">
            <div class="row g-2 small align-items-end">
                <div class="col-md-3">
                    <label class="form-label small text-muted mb-1">Branch</label>
                    <select name="branch" class="form-select form-select-sm">
                        <option value="">all branches</option>
                        @foreach($branches as $b)
                            <option value="{{ $b->code }}" @if($filters['branch']===$b->code) selected @endif>
                                {{ $b->name }} ({{ $b->code }})
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small text-muted mb-1">Device type</label>
                    <select name="device_type" class="form-select form-select-sm">
                        <option value="">any</option>
                        @foreach(\App\Models\SnmpDevice::TYPES as $key => $label)
                            <option value="{{ $key }}" @if($filters['device_type']===$key) selected @endif>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small text-muted mb-1">Status</label>
                    <select name="enabled" class="form-select form-select-sm">
                        <option value="">any</option>
                        <option value="1" @if($filters['enabled']==='1') selected @endif>enabled</option>
                        <option value="0" @if($filters['enabled']==='0') selected @endif>disabled</option>
                    </select>
                </div>
                <div class="col-md-4 d-flex gap-2">
                    <button type="submit" class="btn btn-sm btn-primary">Apply</button>
                    <a href="{{ route('admin.snmp-devices.index') }}" class="btn btn-sm btn-outline-secondary">Clear</a>
                </div>
            </div>
        </div>
    </form>

    {{-- ─── Table ───────────────────────────────────────────────────── --}}
    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive">
            <table class="table table-sm table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th style="width:60px;">Branch</th>
                        <th>Name</th>
                        <th style="width:140px;">Host</th>
                        <th style="width:160px;">Type</th>
                        <th style="width: 70px;">Version</th>
                        <th style="width: 60px;">Port</th>
                        <th style="width: 80px;">Interval</th>
                        <th style="width: 80px;">Enabled</th>
                        <th style="width:120px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                @forelse($devices as $d)
                    <tr>
                        <td><span class="badge bg-secondary">{{ $d->branch?->code }}</span></td>
                        <td>
                            {{ $d->name }}
                            @if($d->notes)
                                <i class="bi bi-info-circle text-muted ms-1" title="{{ $d->notes }}"></i>
                            @endif
                        </td>
                        <td class="font-monospace small">{{ $d->host }}</td>
                        <td><span class="badge bg-light text-dark">{{ \App\Models\SnmpDevice::TYPES[$d->device_type] ?? $d->device_type }}</span></td>
                        <td class="small">{{ $d->snmp_version }}</td>
                        <td class="small">{{ $d->snmp_port }}</td>
                        <td class="small text-muted">{{ $d->polling_interval_s }}s</td>
                        <td>
                            @if($d->enabled)<span class="badge bg-success">on</span>
                            @else<span class="badge bg-secondary">off</span>@endif
                        </td>
                        <td>
                            <a class="btn btn-sm btn-outline-secondary"
                               href="{{ route('admin.snmp-devices.edit', $d) }}">
                                <i class="bi bi-pencil"></i>
                            </a>
                            <form action="{{ route('admin.snmp-devices.destroy', $d) }}"
                                  method="POST" class="d-inline"
                                  onsubmit="return confirm('Remove SNMP device &quot;{{ $d->name }}&quot;?');">
                                @csrf @method('DELETE')
                                <button class="btn btn-sm btn-outline-danger" type="submit">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="text-center text-muted py-5">
                        No SNMP devices configured.
                        <a href="{{ route('admin.snmp-devices.create') }}">Add the first one</a>,
                        or check the
                        <a href="{{ route('admin.snmp-devices.discovered') }}">discovery inbox</a>.
                    </td></tr>
                @endforelse
                </tbody>
            </table>
            </div>
        </div>
        @if($devices->hasPages())
            <div class="card-footer">{{ $devices->links() }}</div>
        @endif
    </div>
</div>
@endsection
