@extends('layouts.admin')

@section('content')
<div class="container-fluid py-4">
    <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
        <div>
            <h4 class="mb-0 fw-bold"><i class="bi bi-collection me-2 text-primary"></i>Unified Printer View</h4>
            <small class="text-muted">ITAM asset · SNMP monitoring · CUPS queue — joined per printer</small>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('admin.printers.dashboard') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-speedometer2 me-1"></i>Dashboard</a>
            <a href="{{ route('admin.printers.usage') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-bar-chart me-1"></i>Usage Report</a>
            @can('manage-printer-alerts')
                <a href="{{ route('admin.printers.branch.index') }}" class="btn btn-outline-warning btn-sm"><i class="bi bi-bell me-1"></i>Alert Settings</a>
            @endcan
        </div>
    </div>

    <form method="GET" class="row g-2 mb-3">
        <div class="col-md-3">
            <select name="branch" class="form-select form-select-sm">
                <option value="">All branches</option>
                @foreach ($branches as $b)
                    <option value="{{ $b->id }}" @selected(request('branch') == $b->id)>{{ $b->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-md-4">
            <input type="search" name="search" class="form-control form-control-sm" placeholder="Search by name / IP / model" value="{{ request('search') }}">
        </div>
        <div class="col-md-3">
            <div class="form-check form-switch mt-1">
                <input class="form-check-input" type="checkbox" id="with_alerts" name="with_alerts" value="1" @checked(request('with_alerts'))>
                <label class="form-check-label small" for="with_alerts">Only printers with open alerts</label>
            </div>
        </div>
        <div class="col-md-2 d-flex gap-1">
            <button type="submit" class="btn btn-primary btn-sm flex-grow-1">Filter</button>
            <a href="{{ route('admin.printers.unified.index') }}" class="btn btn-outline-secondary btn-sm">Reset</a>
        </div>
    </form>

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle small">
                <thead class="table-light">
                    <tr>
                        <th>Printer</th>
                        <th>Branch</th>
                        <th>Asset Code</th>
                        <th>SNMP</th>
                        <th>Min Toner</th>
                        <th>Waste</th>
                        <th>CUPS</th>
                        <th>Alerts</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                @forelse ($printers as $p)
                    @php
                        $minToner = $p->lowestTonerLevel();
                        $waste    = $p->wasteFillPercent();
                        $cups     = $p->cupsPrinter;
                        $openCt   = (int) ($openEventCounts[$p->id] ?? 0);
                    @endphp
                    <tr>
                        <td>
                            <a href="{{ route('admin.printers.unified.show', $p) }}" class="fw-semibold text-decoration-none">{{ $p->printer_name }}</a>
                            <small class="d-block text-muted">{{ $p->ip_address ?? '—' }} · {{ $p->manufacturer }} {{ $p->model }}</small>
                        </td>
                        <td>{{ $p->branch?->name ?? '—' }}</td>
                        <td>
                            @if ($p->device?->asset_code)
                                <code class="small">{{ $p->device->asset_code }}</code>
                            @else
                                <span class="text-muted">Unmanaged</span>
                            @endif
                        </td>
                        <td>
                            @if ($p->hasSnmpData())
                                <span class="text-success" title="Polled {{ $p->snmp_last_polled_at?->diffForHumans() }}"><i class="bi bi-check-circle-fill"></i></span>
                            @elseif ($p->snmp_enabled)
                                <span class="text-warning" title="Stale: {{ $p->snmp_last_polled_at?->diffForHumans() ?? 'never' }}"><i class="bi bi-exclamation-circle"></i></span>
                            @else
                                <span class="text-muted"><i class="bi bi-dash-circle"></i></span>
                            @endif
                        </td>
                        <td>
                            @if ($minToner !== null)
                                <span class="badge {{ $minToner <= 5 ? 'bg-danger' : ($minToner <= 20 ? 'bg-warning text-dark' : 'bg-success') }}">{{ $minToner }}%</span>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td>
                            @if ($waste !== null)
                                <span class="badge {{ $waste >= 95 ? 'bg-danger' : ($waste >= 80 ? 'bg-warning text-dark' : 'bg-success') }}">{{ $waste }}% full</span>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td>
                            @if ($cups)
                                <span class="badge {{ $cups->statusBadgeClass() }}">{{ $cups->status }}</span>
                                <small class="d-block text-muted">{{ $cups->queue_name }}</small>
                            @else
                                <span class="text-muted">Not linked</span>
                            @endif
                        </td>
                        <td>
                            @if ($openCt > 0)
                                <span class="badge bg-danger">{{ $openCt }} open</span>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td class="text-end">
                            <a href="{{ route('admin.printers.unified.show', $p) }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye"></i></a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="text-center text-muted py-4">No printers match these filters.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">{{ $printers->links() }}</div>

    @if ($orphanCups->isNotEmpty())
        <div class="card border-0 shadow-sm mt-4">
            <div class="card-body">
                <h6 class="card-title mb-2 text-warning">
                    <i class="bi bi-link-45deg me-1"></i>{{ $orphanCups->count() }} CUPS queue(s) not linked to any SNMP printer
                </h6>
                <p class="small text-muted mb-2">Run <code>php artisan printers:link-cups</code> to auto-match by IP, or set <code>printer_id</code> manually.</p>
                <ul class="small mb-0">
                    @foreach ($orphanCups as $cp)
                        <li><code>{{ $cp->queue_name }}</code> · {{ $cp->ip_address ?? '—' }} · {{ $cp->branch?->name ?? '—' }}</li>
                    @endforeach
                </ul>
            </div>
        </div>
    @endif
</div>
@endsection
