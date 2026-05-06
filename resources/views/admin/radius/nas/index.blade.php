@extends('layouts.admin')
@section('title', 'RADIUS NAS Clients')

@section('content')
<div class="container-fluid py-4">

    {{-- ── Header ──────────────────────────────────────────────────── --}}
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-0"><i class="bi bi-router me-2 text-primary"></i>RADIUS NAS Clients</h4>
            <small class="text-muted">Switches and APs allowed to query the RADIUS server. FreeRADIUS reads this list on startup and on reload.</small>
        </div>
        <div class="d-flex gap-2">
            <form action="{{ route('admin.radius.nas.reload') }}" method="POST" class="d-inline">
                @csrf
                <button type="submit" class="btn btn-sm btn-outline-warning"
                        title="Trigger `radmin reload clients` on FreeRADIUS">
                    <i class="bi bi-arrow-clockwise me-1"></i>Reload FreeRADIUS
                </button>
            </form>
            <a href="{{ route('admin.radius.vlan.index') }}" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-diagram-3 me-1"></i>VLAN Policy
            </a>
            <a href="{{ route('admin.radius.nas.create') }}" class="btn btn-sm btn-primary">
                <i class="bi bi-plus-lg me-1"></i>Add NAS
            </a>
        </div>
    </div>

    {{-- ── Tip box ─────────────────────────────────────────────────── --}}
    <div class="alert alert-info border-0 small mb-4">
        <i class="bi bi-info-circle me-1"></i>
        <strong>nasname</strong> can be a single IP (e.g. <code>10.10.4.5</code>) or a CIDR range
        (e.g. <code>10.10.4.0/24</code>). FreeRADIUS silently drops requests from any IP
        not present here, so adding a NAS is the one-and-only way to authorize new gear.
    </div>

    {{-- ── Table ───────────────────────────────────────────────────── --}}
    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            @if($clients->isEmpty())
            <div class="text-center py-5 text-muted">
                <i class="bi bi-router fs-1 d-block mb-2 opacity-25"></i>
                No NAS clients configured yet.<br>
                <small>Add your first switch or AP to start accepting RADIUS requests.</small>
            </div>
            @else
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" style="font-size:.88rem">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-3" style="width:60px">Active</th>
                            <th>Short Name</th>
                            <th>NAS / CIDR</th>
                            <th style="width:100px">Type</th>
                            <th style="width:130px">Branch</th>
                            <th style="width:140px">Secret</th>
                            <th>Description</th>
                            <th style="width:130px"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($clients as $c)
                        <tr>
                            <td class="ps-3 text-center">
                                @if($c->is_active)
                                    <i class="bi bi-check-circle-fill text-success" title="Active"></i>
                                @else
                                    <i class="bi bi-x-circle text-muted" title="Disabled"></i>
                                @endif
                            </td>
                            <td class="fw-semibold">{{ $c->shortname }}</td>
                            <td class="font-monospace">{{ $c->nasname }}</td>
                            <td>
                                <span class="badge bg-{{ $c->typeBadge() }}">{{ ucfirst($c->type) }}</span>
                            </td>
                            <td class="text-muted small">{{ $c->branch?->name ?: '—' }}</td>
                            <td class="font-monospace small text-muted">{{ $c->maskedSecret() }}</td>
                            <td class="text-muted small">{{ $c->description ?: '—' }}</td>
                            <td class="text-end pe-3">
                                <a href="{{ route('admin.radius.nas.edit', $c) }}" class="btn btn-sm btn-outline-secondary" title="Edit">
                                    <i class="bi bi-pencil"></i>
                                </a>
                                <form action="{{ route('admin.radius.nas.destroy', $c) }}" method="POST" class="d-inline"
                                      onsubmit="return confirm('Delete NAS client {{ $c->shortname }}? FreeRADIUS will stop accepting requests from {{ $c->nasname }}.');">
                                    @csrf
                                    @method('DELETE')
                                    <button class="btn btn-sm btn-outline-danger" title="Delete">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if($clients->hasPages())
            <div class="px-3 py-2 border-top">
                {{ $clients->links() }}
            </div>
            @endif
            @endif
        </div>
    </div>

</div>
@endsection
