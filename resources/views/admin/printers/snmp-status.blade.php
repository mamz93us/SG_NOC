@extends('layouts.admin')
@section('content')

<div class="d-flex justify-content-between align-items-start mb-4">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-printer-fill me-2 text-primary"></i>Printer SNMP Dashboard</h4>
        <small class="text-muted">Live toner, paper, and status monitoring via SNMP</small>
    </div>
    <div class="d-flex gap-2">
        <form method="POST" action="{{ route('admin.printers.snmp.poll-all') }}" class="d-inline">
            @csrf
            <button type="submit" class="btn btn-sm btn-outline-success">
                <i class="bi bi-arrow-repeat me-1"></i>Poll All Now
            </button>
        </form>
        <a href="{{ route('admin.printers.index') }}" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>Back to Printers
        </a>
    </div>
</div>

@if(session('success'))
<div class="alert alert-success alert-dismissible fade show py-2" role="alert">
    {{ session('success') }}<button type="button" class="btn-close btn-sm" data-bs-dismiss="alert"></button>
</div>
@endif

{{-- Summary Cards --}}
<div class="row g-2 mb-4">
    <div class="col-sm-6 col-lg-3">
        <div class="card shadow-sm text-center py-2">
            <div class="fw-bold fs-4 text-primary">{{ $printers->count() }}</div>
            <small class="text-muted">SNMP Printers</small>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="card shadow-sm text-center py-2">
            <div class="fw-bold fs-4 text-success">{{ $printers->where('printer_status', 'idle')->count() }}</div>
            <small class="text-muted">Idle / Ready</small>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="card shadow-sm text-center py-2">
            @php $lowToner = $printers->filter(fn($p) => $p->lowestTonerLevel() !== null && $p->lowestTonerLevel() <= 20)->count(); @endphp
            <div class="fw-bold fs-4 {{ $lowToner > 0 ? 'text-warning' : 'text-success' }}">{{ $lowToner }}</div>
            <small class="text-muted">Low Toner</small>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="card shadow-sm text-center py-2">
            @php $errors = $printers->filter(fn($p) => $p->error_state && $p->error_state !== 'normal')->count(); @endphp
            <div class="fw-bold fs-4 {{ $errors > 0 ? 'text-danger' : 'text-success' }}">{{ $errors }}</div>
            <small class="text-muted">Errors</small>
        </div>
    </div>
</div>

{{-- Filter --}}
<div class="card shadow-sm mb-3">
    <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-3">
                <select name="branch" class="form-select form-select-sm">
                    <option value="">All Branches</option>
                    @foreach($branches as $b)
                    <option value="{{ $b->id }}" {{ request('branch') == $b->id ? 'selected' : '' }}>{{ $b->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <select name="status" class="form-select form-select-sm">
                    <option value="">All Statuses</option>
                    <option value="idle" {{ request('status') == 'idle' ? 'selected' : '' }}>Idle</option>
                    <option value="printing" {{ request('status') == 'printing' ? 'selected' : '' }}>Printing</option>
                    <option value="error" {{ request('status') == 'error' ? 'selected' : '' }}>Error</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-check form-check-inline mb-0">
                    <input type="checkbox" name="low_toner" value="1" class="form-check-input"
                           {{ request('low_toner') ? 'checked' : '' }}>
                    <span class="form-check-label small">Low Toner Only</span>
                </label>
            </div>
            <div class="col-md-2">
                <input type="text" name="search" class="form-control form-control-sm"
                       placeholder="Search..." value="{{ request('search') }}">
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-funnel me-1"></i>Filter</button>
            </div>
        </form>
    </div>
</div>

{{-- Printer Cards Grid --}}
<div class="row g-3">
    @forelse($printers as $printer)
    <div class="col-xl-4 col-lg-6">
        <div class="card shadow-sm h-100 {{ $printer->error_state && $printer->error_state !== 'normal' ? 'border-danger' : '' }}">
            {{-- Header --}}
            <div class="card-header py-2 d-flex justify-content-between align-items-center">
                <div class="d-flex align-items-center gap-2">
                    <i class="bi bi-printer-fill text-primary"></i>
                    <a href="{{ route('admin.printers.show', $printer) }}" class="fw-semibold text-decoration-none">
                        {{ $printer->printer_name }}
                    </a>
                </div>
                <div class="d-flex align-items-center gap-1">
                    <span class="badge {{ $printer->statusBadgeClass() }} badge-sm">{{ $printer->statusLabel() }}</span>
                    @if($printer->error_state && $printer->error_state !== 'normal')
                    <span class="badge {{ $printer->errorBadgeClass() }} badge-sm">{{ $printer->errorLabel() }}</span>
                    @endif
                </div>
            </div>

            <div class="card-body py-2">
                {{-- Info Row --}}
                <div class="d-flex justify-content-between small text-muted mb-2">
                    <span><i class="bi bi-geo-alt me-1"></i>{{ $printer->branch?->name ?? '—' }}</span>
                    <span class="font-monospace">{{ $printer->ip_address }}</span>
                </div>

                @if($printer->snmp_model)
                <div class="small text-muted mb-2">
                    <i class="bi bi-cpu me-1"></i>{{ $printer->manufacturer ? $printer->manufacturer . ' ' : '' }}{{ $printer->snmp_model }}
                </div>
                @endif

                {{-- Toner Gauges --}}
                <div class="mb-2">
                    <div class="d-flex align-items-center justify-content-between mb-1">
                        <small class="fw-semibold text-muted">Toner Levels</small>
                        @if($printer->snmp_last_polled_at)
                        <small class="text-muted" title="{{ $printer->snmp_last_polled_at->format('d M Y H:i:s') }}">
                            <i class="bi bi-clock me-1"></i>{{ $printer->snmp_last_polled_at->diffForHumans() }}
                        </small>
                        @endif
                    </div>

                    @foreach($printer->tonerLevels() as $color => $level)
                    <div class="d-flex align-items-center gap-2 mb-1">
                        <div style="width:12px;height:12px;border-radius:50%;background:{{ \App\Models\Printer::tonerColor($color) }};flex-shrink:0;border:1px solid rgba(0,0,0,0.15)"></div>
                        <small class="text-muted" style="width:55px">{{ $color }}</small>
                        <div class="progress flex-grow-1" style="height:14px">
                            @if($level !== null && $level >= 0)
                            <div class="progress-bar {{ \App\Models\Printer::tonerBarClass($level) }}"
                                 role="progressbar" style="width:{{ $level }}%"
                                 aria-valuenow="{{ $level }}" aria-valuemin="0" aria-valuemax="100">
                                <small class="fw-bold" style="font-size:0.65rem">{{ $level }}%</small>
                            </div>
                            @else
                            <div class="progress-bar bg-secondary" role="progressbar" style="width:100%">
                                <small style="font-size:0.65rem">N/A</small>
                            </div>
                            @endif
                        </div>
                    </div>
                    @endforeach

                    @if($printer->toner_waste !== null)
                    <div class="d-flex align-items-center gap-2 mb-1">
                        <div style="width:12px;height:12px;border-radius:50%;background:#6c757d;flex-shrink:0;border:1px solid rgba(0,0,0,0.15)"></div>
                        <small class="text-muted" style="width:55px">Waste</small>
                        <div class="progress flex-grow-1" style="height:14px">
                            <div class="progress-bar {{ $printer->toner_waste >= 80 ? 'bg-danger' : ($printer->toner_waste >= 60 ? 'bg-warning' : 'bg-info') }}"
                                 role="progressbar" style="width:{{ $printer->toner_waste }}%">
                                <small class="fw-bold" style="font-size:0.65rem">{{ $printer->toner_waste }}%</small>
                            </div>
                        </div>
                    </div>
                    @endif
                </div>

                {{-- Paper Trays --}}
                @if($printer->paper_trays && count($printer->paper_trays) > 0)
                <div class="mb-2">
                    <small class="fw-semibold text-muted d-block mb-1">Paper Trays</small>
                    @foreach($printer->paper_trays as $tray)
                    @php
                        $trayPct = ($tray['max'] ?? 0) > 0 ? round(($tray['current'] ?? 0) / $tray['max'] * 100) : 0;
                    @endphp
                    <div class="d-flex align-items-center gap-2 mb-1">
                        <i class="bi bi-layers text-muted" style="width:12px"></i>
                        <small class="text-muted" style="width:55px">{{ $tray['name'] ?? 'Tray' }}</small>
                        <div class="progress flex-grow-1" style="height:12px">
                            <div class="progress-bar {{ $trayPct <= 10 ? 'bg-danger' : ($trayPct <= 30 ? 'bg-warning' : 'bg-info') }}"
                                 style="width:{{ $trayPct }}%">
                                <small style="font-size:0.6rem">{{ $tray['current'] ?? 0 }}/{{ $tray['max'] ?? '?' }}</small>
                            </div>
                        </div>
                    </div>
                    @endforeach
                </div>
                @endif

                {{-- Drum / Fuser --}}
                @if($printer->drum_black !== null || $printer->fuser_level !== null)
                <div class="mb-2">
                    <small class="fw-semibold text-muted d-block mb-1">Consumables</small>
                    @if($printer->drum_black !== null)
                    <div class="d-flex align-items-center gap-2 mb-1">
                        <i class="bi bi-disc text-muted" style="width:12px"></i>
                        <small class="text-muted" style="width:55px">Drum</small>
                        <div class="progress flex-grow-1" style="height:12px">
                            <div class="progress-bar {{ $printer->drum_black <= 10 ? 'bg-danger' : ($printer->drum_black <= 25 ? 'bg-warning' : 'bg-secondary') }}"
                                 style="width:{{ $printer->drum_black }}%">
                                <small style="font-size:0.6rem">{{ $printer->drum_black }}%</small>
                            </div>
                        </div>
                    </div>
                    @endif
                    @if($printer->fuser_level !== null)
                    <div class="d-flex align-items-center gap-2 mb-1">
                        <i class="bi bi-thermometer-half text-muted" style="width:12px"></i>
                        <small class="text-muted" style="width:55px">Fuser</small>
                        <div class="progress flex-grow-1" style="height:12px">
                            <div class="progress-bar {{ $printer->fuser_level <= 10 ? 'bg-danger' : ($printer->fuser_level <= 25 ? 'bg-warning' : 'bg-secondary') }}"
                                 style="width:{{ $printer->fuser_level }}%">
                                <small style="font-size:0.6rem">{{ $printer->fuser_level }}%</small>
                            </div>
                        </div>
                    </div>
                    @endif
                </div>
                @endif

                {{-- Page Counters --}}
                @if($printer->page_count_total)
                <div class="border-top pt-2 mt-1">
                    <div class="row g-1 text-center">
                        <div class="col">
                            <div class="fw-bold small">{{ number_format($printer->page_count_total) }}</div>
                            <small class="text-muted" style="font-size:0.65rem">Total</small>
                        </div>
                        @if($printer->page_count_color)
                        <div class="col">
                            <div class="fw-bold small text-primary">{{ number_format($printer->page_count_color) }}</div>
                            <small class="text-muted" style="font-size:0.65rem">Color</small>
                        </div>
                        @endif
                        @if($printer->page_count_mono)
                        <div class="col">
                            <div class="fw-bold small">{{ number_format($printer->page_count_mono) }}</div>
                            <small class="text-muted" style="font-size:0.65rem">Mono</small>
                        </div>
                        @endif
                        @if($printer->page_count_copy)
                        <div class="col">
                            <div class="fw-bold small">{{ number_format($printer->page_count_copy) }}</div>
                            <small class="text-muted" style="font-size:0.65rem">Copy</small>
                        </div>
                        @endif
                        @if($printer->page_count_scan)
                        <div class="col">
                            <div class="fw-bold small">{{ number_format($printer->page_count_scan) }}</div>
                            <small class="text-muted" style="font-size:0.65rem">Scan</small>
                        </div>
                        @endif
                    </div>
                </div>
                @endif
            </div>

            {{-- Footer --}}
            <div class="card-footer py-1 bg-transparent d-flex justify-content-between">
                <a href="{{ route('admin.printers.show', $printer) }}" class="btn btn-sm btn-link p-0">
                    <i class="bi bi-eye me-1"></i>Details
                </a>
                <form method="POST" action="{{ route('admin.printers.snmp.poll', $printer) }}" class="d-inline">
                    @csrf
                    <button type="submit" class="btn btn-sm btn-link p-0 text-success">
                        <i class="bi bi-arrow-repeat me-1"></i>Poll Now
                    </button>
                </form>
            </div>
        </div>
    </div>
    @empty
    <div class="col-12">
        <div class="card shadow-sm">
            <div class="card-body text-center py-5">
                <i class="bi bi-printer display-4 text-muted"></i>
                <p class="text-muted mt-2 mb-0">No SNMP-enabled printers found.</p>
                <small class="text-muted">Enable SNMP on a printer by editing it and checking "Enable SNMP Monitoring".</small>
            </div>
        </div>
    </div>
    @endforelse
</div>

@endsection
