@extends('layouts.admin')

@section('content')
<div class="container-fluid py-4">

    {{-- Page Header --}}
    <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
        <div>
            <h4 class="mb-0 fw-bold"><i class="bi bi-speedometer2 me-2 text-warning"></i>Printer Dashboard</h4>
            <small class="text-muted">Fleet health, toner levels, maintenance overview</small>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('admin.printers.index') }}" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-printer-fill me-1"></i>Printers List
            </a>
            <a href="{{ route('admin.printers.snmp.status') }}" class="btn btn-outline-success btn-sm">
                <i class="bi bi-activity me-1"></i>SNMP Status
            </a>
        </div>
    </div>

    {{-- KPI Cards --}}
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-2">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body text-center py-3">
                    <div class="fs-2 fw-bold text-primary">{{ $total }}</div>
                    <div class="small text-muted">Total Printers</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="card border-start border-success border-3 shadow-sm h-100">
                <div class="card-body text-center py-3">
                    <div class="fs-2 fw-bold text-success">{{ $snmpEnabled }}</div>
                    <div class="small text-muted">SNMP Enabled</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="card border-start border-info border-3 shadow-sm h-100">
                <div class="card-body text-center py-3">
                    <div class="fs-2 fw-bold text-info">{{ $recentlyPolled }}</div>
                    <div class="small text-muted">Polled (30min)</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="card border-start border-warning border-3 shadow-sm h-100">
                <div class="card-body text-center py-3">
                    <div class="fs-2 fw-bold text-warning">{{ $lowTonerCount }}</div>
                    <div class="small text-muted">Low Toner</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="card border-start border-danger border-3 shadow-sm h-100">
                <div class="card-body text-center py-3">
                    <div class="fs-2 fw-bold text-danger">{{ $criticalTonerCount }}</div>
                    <div class="small text-muted">Critical Toner</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="card border-start border-secondary border-3 shadow-sm h-100">
                <div class="card-body text-center py-3">
                    <div class="fs-2 fw-bold text-secondary">{{ $maintenanceDue }}</div>
                    <div class="small text-muted">Maintenance Due</div>
                </div>
            </div>
        </div>
    </div>

    {{-- Row: Low Toner Table + Branch Distribution --}}
    <div class="row g-3 mb-4">

        {{-- Low Toner Supplies --}}
        <div class="col-md-7">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-transparent border-0 pb-0 d-flex justify-content-between align-items-center">
                    <span class="fw-semibold small"><i class="bi bi-exclamation-diamond-fill me-2 text-warning"></i>Low Toner Supplies</span>
                    <span class="badge bg-warning text-dark">{{ $lowTonerCount }} printers</span>
                </div>
                <div class="card-body p-0">
                    @if($lowTonerSupplies->isEmpty())
                    <div class="text-center text-muted py-4">
                        <i class="bi bi-check-circle-fill text-success fs-4 d-block mb-1"></i>No low toner supplies detected
                    </div>
                    @else
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0 small">
                            <thead class="table-light">
                                <tr>
                                    <th>Printer</th>
                                    <th>Branch</th>
                                    <th>Supply</th>
                                    <th style="width:140px">Level</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($lowTonerSupplies as $supply)
                                @php
                                    $pct = $supply->supply_percent ?? 0;
                                    $barClass = $pct <= ($supply->critical_threshold ?? 5) ? 'bg-danger'
                                              : ($pct <= ($supply->warning_threshold ?? 20) ? 'bg-warning' : 'bg-success');
                                    $colorDot = match(strtolower($supply->supply_color ?? '')) {
                                        'cyan'    => '#0dcaf0',
                                        'magenta' => '#d63384',
                                        'yellow'  => '#ffc107',
                                        'waste'   => '#6c757d',
                                        default   => '#212529',
                                    };
                                @endphp
                                <tr>
                                    <td class="fw-semibold">{{ $supply->printer?->printer_name ?? '—' }}</td>
                                    <td class="text-muted">{{ $supply->printer?->branch?->name ?? '—' }}</td>
                                    <td>
                                        <span class="d-inline-flex align-items-center gap-1">
                                            <span style="width:10px;height:10px;border-radius:50%;background:{{ $colorDot }};display:inline-block;border:1px solid rgba(0,0,0,.2);"></span>
                                            {{ ucfirst($supply->supply_color ?? $supply->supply_type) }}
                                        </span>
                                    </td>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <div class="progress flex-grow-1" style="height:8px;">
                                                <div class="progress-bar {{ $barClass }}" style="width:{{ $pct }}%"></div>
                                            </div>
                                            <span class="fw-semibold {{ $barClass === 'bg-danger' ? 'text-danger' : 'text-warning' }}" style="min-width:32px;">{{ $pct }}%</span>
                                        </div>
                                    </td>
                                    <td>
                                        <a href="{{ route('admin.printers.show', $supply->printer_id) }}"
                                           class="btn btn-sm btn-outline-primary py-0 px-1"><i class="bi bi-eye"></i></a>
                                    </td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @endif
                </div>
            </div>
        </div>

        {{-- Branch Distribution --}}
        <div class="col-md-5">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-transparent border-0 pb-0">
                    <span class="fw-semibold small"><i class="bi bi-building me-2 text-secondary"></i>Branch Distribution</span>
                </div>
                <div class="card-body p-0">
                    @if($branchDist->isEmpty())
                    <div class="text-center text-muted py-4">No data</div>
                    @else
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0 small">
                            <thead class="table-light">
                                <tr><th>Branch</th><th class="text-center">Printers</th><th style="width:100px">Share</th></tr>
                            </thead>
                            <tbody>
                                @foreach($branchDist as $row)
                                @php $pct = $total > 0 ? round($row->total / $total * 100) : 0; @endphp
                                <tr>
                                    <td>{{ $row->branch?->name ?? 'No Branch' }}</td>
                                    <td class="text-center fw-semibold">{{ $row->total }}</td>
                                    <td>
                                        <div class="progress" style="height:8px;">
                                            <div class="progress-bar bg-primary" style="width:{{ $pct }}%"></div>
                                        </div>
                                        <div class="text-muted text-end" style="font-size:0.7rem;">{{ $pct }}%</div>
                                    </td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- Row: Top Usage + Error Printers --}}
    <div class="row g-3 mb-4">

        {{-- Highest Usage Printers --}}
        <div class="col-md-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-transparent border-0 pb-0">
                    <span class="fw-semibold small"><i class="bi bi-graph-up-arrow me-2 text-primary"></i>Highest Usage Printers</span>
                </div>
                <div class="card-body p-0">
                    @if($topByUsage->isEmpty())
                    <div class="text-center text-muted py-4">No page count data available</div>
                    @else
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0 small">
                            <thead class="table-light">
                                <tr><th>Printer</th><th>Branch</th><th class="text-end">Total Pages</th><th class="text-end">Color</th></tr>
                            </thead>
                            <tbody>
                                @foreach($topByUsage as $p)
                                <tr>
                                    <td>
                                        <a href="{{ route('admin.printers.show', $p) }}" class="text-decoration-none fw-semibold">{{ $p->printer_name }}</a>
                                        <div class="text-muted" style="font-size:0.75rem;">{{ $p->model }}</div>
                                    </td>
                                    <td class="text-muted">{{ $p->branch?->name ?? '—' }}</td>
                                    <td class="text-end fw-semibold">{{ number_format($p->page_count_total) }}</td>
                                    <td class="text-end text-muted">
                                        {{ $p->page_count_color ? number_format($p->page_count_color) : '—' }}
                                    </td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @endif
                </div>
            </div>
        </div>

        {{-- Error / Problem Printers --}}
        <div class="col-md-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-transparent border-0 pb-0 d-flex justify-content-between align-items-center">
                    <span class="fw-semibold small"><i class="bi bi-exclamation-triangle-fill me-2 text-danger"></i>Error State Printers</span>
                </div>
                <div class="card-body p-0">
                    @if($errorPrinters->isEmpty())
                    <div class="text-center text-muted py-4">
                        <i class="bi bi-check-circle-fill text-success fs-4 d-block mb-1"></i>No printers in error state
                    </div>
                    @else
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0 small">
                            <thead class="table-light">
                                <tr><th>Printer</th><th>Branch</th><th>Status</th><th>Last Poll</th><th></th></tr>
                            </thead>
                            <tbody>
                                @foreach($errorPrinters as $p)
                                <tr>
                                    <td class="fw-semibold">{{ $p->printer_name }}</td>
                                    <td class="text-muted">{{ $p->branch?->name ?? '—' }}</td>
                                    <td>
                                        <span class="badge {{ $p->errorBadgeClass() }}">{{ $p->errorLabel() }}</span>
                                    </td>
                                    <td class="text-muted">{{ $p->snmp_last_polled_at?->diffForHumans() ?? '—' }}</td>
                                    <td>
                                        <a href="{{ route('admin.printers.show', $p) }}" class="btn btn-sm btn-outline-danger py-0 px-1">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                    </td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- Row: Active Low-Alert Printers + Recent Maintenance --}}
    <div class="row g-3">

        {{-- Printers with active low-alert --}}
        <div class="col-md-5">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-transparent border-0 pb-0 d-flex justify-content-between align-items-center">
                    <span class="fw-semibold small"><i class="bi bi-bell-fill me-2 text-danger"></i>Active Low-Toner Alerts</span>
                </div>
                <div class="card-body p-0">
                    @if($isLowAlertPrinters->isEmpty())
                    <div class="text-center text-muted py-4">No active low-toner alerts</div>
                    @else
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0 small">
                            <thead class="table-light">
                                <tr><th>Printer</th><th>Branch</th><th>Supplies</th><th></th></tr>
                            </thead>
                            <tbody>
                                @foreach($isLowAlertPrinters as $p)
                                <tr>
                                    <td class="fw-semibold">{{ $p->printer_name }}</td>
                                    <td class="text-muted">{{ $p->branch?->name ?? '—' }}</td>
                                    <td>
                                        @foreach($p->supplies as $s)
                                        <span class="badge bg-danger me-1">{{ ucfirst($s->supply_color ?? $s->supply_type) }} {{ $s->supply_percent }}%</span>
                                        @endforeach
                                    </td>
                                    <td>
                                        <a href="{{ route('admin.printers.show', $p) }}" class="btn btn-sm btn-outline-danger py-0 px-1">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                    </td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @endif
                </div>
            </div>
        </div>

        {{-- Recent Maintenance Logs --}}
        <div class="col-md-7">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-transparent border-0 pb-0">
                    <span class="fw-semibold small"><i class="bi bi-tools me-2 text-secondary"></i>Recent Maintenance</span>
                </div>
                <div class="card-body p-0">
                    @if($recentMaintenance->isEmpty())
                    <div class="text-center text-muted py-4">No maintenance records yet</div>
                    @else
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0 small">
                            <thead class="table-light">
                                <tr><th>Printer</th><th>Branch</th><th>Type</th><th>Performed By</th><th>Date</th><th></th></tr>
                            </thead>
                            <tbody>
                                @foreach($recentMaintenance as $log)
                                <tr>
                                    <td class="fw-semibold">{{ $log->printer?->printer_name ?? '—' }}</td>
                                    <td class="text-muted">{{ $log->printer?->branch?->name ?? '—' }}</td>
                                    <td>
                                        <span class="badge {{ $log->typeBadgeClass() }}">
                                            <i class="bi {{ $log->typeIcon() }} me-1"></i>{{ $log->typeLabel() }}
                                        </span>
                                    </td>
                                    <td class="text-muted">{{ $log->performerName() }}</td>
                                    <td class="text-muted">
                                        {{ $log->performed_at ? \Carbon\Carbon::parse($log->performed_at)->format('d M Y') : '—' }}
                                    </td>
                                    <td>
                                        @if($log->printer)
                                        <a href="{{ route('admin.printers.show', $log->printer) }}" class="btn btn-sm btn-outline-secondary py-0 px-1">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                        @endif
                                    </td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

</div>
@endsection
