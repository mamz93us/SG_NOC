@extends('layouts.admin')
@section('content')

@php
    $results = $results ?? [];
    $counts  = $counts ?? [];
    $statusMeta = [
        'ready'          => ['label' => 'Ready to Assign', 'class' => 'success',   'icon' => 'check2-circle'],
        'no_asset'       => ['label' => 'No Asset',        'class' => 'warning',   'icon' => 'box'],
        'no_account'     => ['label' => 'No SIP Account',  'class' => 'secondary', 'icon' => 'telephone-x'],
        'no_employee'    => ['label' => 'No Employee',     'class' => 'secondary', 'icon' => 'person-x'],
        'wrong_employee' => ['label' => 'Wrong Person',    'class' => 'danger',    'icon' => 'exclamation-triangle'],
        'assigned'       => ['label' => 'Assigned',        'class' => 'primary',   'icon' => 'person-check'],
    ];
@endphp

{{-- ── Header ─────────────────────────────────────────────────────────── --}}
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-telephone me-2 text-primary"></i>Phone Management</h4>
        <small class="text-muted">GDMS devices · ITAM assets · SIP accounts · employees</small>
    </div>
    <div class="d-flex gap-2">
        @can('manage-phones')
        <a href="{{ route('admin.phones.create') }}" class="btn btn-primary btn-sm">
            <i class="bi bi-plus-circle me-1"></i>Add Phone to GDMS
        </a>
        @endcan
        <a href="{{ route('admin.gdms.ucm') }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-hdd-rack me-1"></i>PBX Status
        </a>
    </div>
</div>

{{-- ── Alerts ──────────────────────────────────────────────────────────── --}}
@if($gdmsError ?? false)
<div class="alert alert-warning d-flex align-items-start gap-2 py-2 mb-3">
    <i class="bi bi-cloud-slash-fill mt-1 flex-shrink-0"></i>
    <div><strong>GDMS Unreachable</strong> — {{ $gdmsError }}<br>
    <small class="text-muted">Showing data from the local database only.</small></div>
</div>
@endif
@if(session('success'))
<div class="alert alert-success alert-dismissible fade show py-2 mb-3">{{ session('success') }}<button type="button" class="btn-close btn-sm" data-bs-dismiss="alert"></button></div>
@endif
@if(session('error'))
<div class="alert alert-danger alert-dismissible fade show py-2 mb-3">{{ session('error') }}<button type="button" class="btn-close btn-sm" data-bs-dismiss="alert"></button></div>
@endif

{{-- ── Filter chips + search ───────────────────────────────────────────── --}}
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <div class="btn-group btn-group-sm flex-wrap" role="group">
        <a href="{{ route('admin.phones.index') }}"
           class="btn btn-outline-dark {{ (! $status || $status === 'all') ? 'active' : '' }}">
            All <span class="badge bg-dark ms-1">{{ $counts['all'] ?? 0 }}</span>
        </a>
        @foreach($statusMeta as $key => $meta)
            @if(($counts[$key] ?? 0) > 0)
            <a href="{{ route('admin.phones.index', ['status' => $key]) }}"
               class="btn btn-outline-{{ $meta['class'] }} {{ $status === $key ? 'active' : '' }}">
                {{ $meta['label'] }} <span class="badge bg-{{ $meta['class'] }} ms-1">{{ $counts[$key] }}</span>
            </a>
            @endif
        @endforeach
    </div>
    <form method="GET" action="{{ route('admin.phones.index') }}" class="d-flex gap-2">
        @if($status)<input type="hidden" name="status" value="{{ $status }}">@endif
        <input type="search" name="q" value="{{ $q ?? '' }}" class="form-control form-control-sm"
               placeholder="MAC, model, ext, employee, IP" style="min-width:240px">
        <button class="btn btn-outline-primary btn-sm"><i class="bi bi-search"></i></button>
    </form>
</div>

{{-- ── Table ───────────────────────────────────────────────────────────── --}}
<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Status</th><th>MAC</th><th>Model</th><th>IP</th><th>Online</th>
                    <th>Ext / SIP</th><th>Employee</th><th>Asset</th><th></th>
                </tr>
            </thead>
            <tbody>
            @forelse($results as $r)
                @php $m = $statusMeta[$r['status']] ?? ['label' => $r['status'], 'class' => 'secondary', 'icon' => 'question-circle']; @endphp
                <tr>
                    <td><span class="badge bg-{{ $m['class'] }}"><i class="bi bi-{{ $m['icon'] }} me-1"></i>{{ $m['label'] }}</span></td>
                    <td class="font-monospace small">{{ strtoupper(implode(':', str_split($r['mac'], 2))) }}</td>
                    <td>{{ $r['model'] ?? '—' }}</td>
                    <td class="small">{{ $r['ip'] ?? '—' }}</td>
                    <td>
                        @if($r['online'] === true)<span class="badge bg-success">Online</span>
                        @elseif($r['online'] === false)<span class="badge bg-secondary">Offline</span>
                        @else<span class="text-muted">—</span>@endif
                    </td>
                    <td>{{ $r['sipUserId'] ?? '—' }}</td>
                    <td>{{ $r['employee']?->name ?? ($r['assignedEmployee']?->name ?? '—') }}</td>
                    <td class="small">{{ $r['device']?->asset_code ?? '—' }}</td>
                    <td class="text-end">
                        <a href="{{ route('admin.phones.show', $r['mac']) }}" class="btn btn-sm btn-outline-primary" title="Details">
                            <i class="bi bi-eye"></i>
                        </a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="9" class="text-center text-muted py-4">No phones found.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

@endsection
