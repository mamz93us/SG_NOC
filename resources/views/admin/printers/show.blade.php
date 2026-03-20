@extends('layouts.admin')
@section('content')

<div class="d-flex justify-content-between align-items-start mb-4">
    <div class="d-flex align-items-center gap-2">
        <a href="{{ route('admin.printers.index') }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i></a>
        <i class="bi bi-printer-fill fs-4 text-primary"></i>
        <h4 class="mb-0 fw-bold">{{ $printer->printer_name }}</h4>
    </div>
    @can('manage-printers')
    <a href="{{ route('admin.printers.edit', $printer) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil me-1"></i>Edit</a>
    @endcan
</div>


{{-- Maintenance Alert Banners --}}
@if($printer->isMaintenanceDue() || $printer->isTonerDue())
<div class="row g-2 mb-3">
    @if($printer->isMaintenanceDue())
    <div class="col-md-6">
        <div class="alert alert-warning py-2 mb-0 d-flex align-items-center gap-3">
            <i class="bi bi-wrench-fill fs-4 text-warning flex-shrink-0"></i>
            <div>
                <strong>Service Overdue</strong><br>
                <small>
                    Last serviced {{ $printer->last_service_date ? $printer->last_service_date->diffForHumans() : 'never' }}.
                    Interval: {{ $printer->service_interval_days }} days.
                </small>
            </div>
            <a href="{{ route('admin.printers.maintenance.index', $printer) }}" class="btn btn-sm btn-warning ms-auto">Log Service</a>
        </div>
    </div>
    @endif
    @if($printer->isTonerDue())
    <div class="col-md-6">
        <div class="alert alert-info py-2 mb-0 d-flex align-items-center gap-3">
            <i class="bi bi-printer-fill fs-4 text-info flex-shrink-0"></i>
            <div>
                <strong>Toner Change Due</strong><br>
                <small>Last changed {{ $printer->toner_last_changed ? $printer->toner_last_changed->diffForHumans() : 'never' }}.</small>
            </div>
            <a href="{{ route('admin.printers.maintenance.index', $printer) }}" class="btn btn-sm btn-info ms-auto">Log Toner</a>
        </div>
    </div>
    @endif
</div>
@endif

<div class="row g-3">

    {{-- Printer Info --}}
    <div class="col-md-5">
        <div class="card shadow-sm h-100">
            <div class="card-header py-2"><h6 class="mb-0 fw-semibold"><i class="bi bi-info-circle me-2"></i>Printer Info</h6></div>
            <div class="card-body">
                <table class="table table-sm table-borderless small mb-0">
                    <tr><th class="text-muted" style="width:38%">Manufacturer</th><td>{{ $printer->manufacturer ?: '—' }}</td></tr>
                    <tr><th class="text-muted">Model</th><td>{{ $printer->model ?: '—' }}</td></tr>
                    <tr><th class="text-muted">Serial</th><td class="font-monospace">{{ $printer->serial_number ?: '—' }}</td></tr>
                    <tr><th class="text-muted">Toner</th><td>{{ $printer->toner_model ?: '—' }}</td></tr>
                    <tr><th class="text-muted">IP</th><td class="font-monospace">{{ $printer->ip_address ?: '—' }}</td></tr>
                    <tr><th class="text-muted">MAC</th><td class="font-monospace">{{ $printer->mac_address ?: '—' }}</td></tr>
                    <tr><th class="text-muted">Branch</th><td>{{ $printer->branch?->name ?: '—' }}</td></tr>
                    <tr><th class="text-muted">Location</th><td>{{ $printer->locationLabel() }}</td></tr>
                    <tr><th class="text-muted">Department</th><td>{{ $printer->department ?: '—' }}</td></tr>
                    <tr><th class="text-muted">Added</th><td>{{ $printer->created_at->format('d M Y') }}</td></tr>
                </table>
                @if($printer->notes)
                <hr class="my-2">
                <p class="small text-muted mb-0">{{ $printer->notes }}</p>
                @endif
            </div>
        </div>
    </div>

    {{-- Credentials --}}
    <div class="col-md-7">
        <div class="card shadow-sm">
            <div class="card-header py-2 d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-semibold"><i class="bi bi-key-fill me-2"></i>Credentials</h6>
                @can('manage-credentials')
                @if($printer->device)
                <a href="{{ route('admin.credentials.create') }}?device_id={{ $printer->device->id }}" class="btn btn-sm btn-outline-primary">
                    <i class="bi bi-plus-lg"></i> Add
                </a>
                @endif
                @endcan
            </div>
            <div class="card-body p-0">
                @php $credentials = $printer->device?->credentials ?? collect(); @endphp
                @if($credentials->isEmpty())
                <div class="text-center py-4 text-muted small">No credentials linked to this printer.</div>
                @else
                <table class="table table-sm table-hover align-middle mb-0 small">
                    <thead class="table-light">
                        <tr><th>Title</th><th>Category</th><th>Username</th><th>Added by</th><th></th></tr>
                    </thead>
                    <tbody>
                        @foreach($credentials as $cred)
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
    </div>
</div>

{{-- SNMP Live Status --}}
@if($printer->snmp_enabled && $printer->snmp_last_polled_at)
<div class="card shadow-sm border-0 mt-3">
    <div class="card-header bg-transparent d-flex justify-content-between align-items-center">
        <strong><i class="bi bi-activity me-2"></i>SNMP Live Status</strong>
        <div class="d-flex align-items-center gap-2">
            <small class="text-muted">
                <i class="bi bi-clock me-1"></i>Polled {{ $printer->snmp_last_polled_at->diffForHumans() }}
            </small>
            <form method="POST" action="{{ route('admin.printers.snmp.poll', $printer) }}" class="d-inline">
                @csrf
                <button type="submit" class="btn btn-sm btn-outline-success">
                    <i class="bi bi-arrow-repeat me-1"></i>Poll Now
                </button>
            </form>
        </div>
    </div>
    <div class="card-body">
        <div class="row g-3">
            {{-- Printer Status --}}
            <div class="col-md-4">
                <div class="text-center mb-3">
                    <span class="badge {{ $printer->statusBadgeClass() }} fs-6 px-3 py-2">
                        <i class="bi bi-printer me-1"></i>{{ $printer->statusLabel() }}
                    </span>
                    @if($printer->error_state && $printer->error_state !== 'normal')
                    <div class="mt-2">
                        <span class="badge {{ $printer->errorBadgeClass() }}">{{ $printer->errorLabel() }}</span>
                    </div>
                    @endif
                </div>

                @if($printer->snmp_model || $printer->snmp_serial)
                <table class="table table-sm table-borderless small mb-0">
                    @if($printer->snmp_model)
                    <tr><th class="text-muted" style="width:40%">SNMP Model</th><td>{{ $printer->snmp_model }}</td></tr>
                    @endif
                    @if($printer->snmp_serial)
                    <tr><th class="text-muted">SNMP Serial</th><td class="font-monospace">{{ $printer->snmp_serial }}</td></tr>
                    @endif
                </table>
                @endif
            </div>

            {{-- Toner Gauges --}}
            @php
                $tonerSupplies = $printer->supplies->where('supply_type', 'toner');
                $otherSupplies = $printer->supplies->whereNotIn('supply_type', ['toner']);
            @endphp
            <div class="col-md-4">
                <h6 class="fw-semibold small text-muted mb-2"><i class="bi bi-droplet-half me-1"></i>Toner Levels</h6>
                @forelse($tonerSupplies as $supply)
                <div class="d-flex align-items-center gap-2 mb-2">
                    <div style="width:14px;height:14px;border-radius:50%;background:{{ $supply->colorDot() }};flex-shrink:0;border:1px solid rgba(0,0,0,0.15)"></div>
                    <small class="text-muted fw-semibold text-truncate" style="width:60px" title="{{ $supply->supply_descr }}">
                        {{ ucfirst($supply->supply_color ?? 'N/A') }}
                    </small>
                    <div class="progress flex-grow-1" style="height:18px">
                        @if($supply->supply_percent !== null)
                        <div class="progress-bar bg-{{ $supply->colorClass() }}"
                             style="width:{{ $supply->supply_percent }}%">
                            <span class="fw-bold" style="font-size:0.7rem">{{ $supply->supply_percent }}%</span>
                        </div>
                        @else
                        <div class="progress-bar bg-secondary" style="width:100%">
                            <span style="font-size:0.7rem">N/A</span>
                        </div>
                        @endif
                    </div>
                    @if($supply->isCritical())
                    <span class="badge bg-danger">Critical</span>
                    @elseif($supply->isLow())
                    <span class="badge bg-warning text-dark">Low</span>
                    @endif
                </div>
                @if($supply->estimated_days_remaining !== null)
                <div class="ms-5 mb-1">
                    <small class="text-muted">
                        <i class="bi bi-calendar me-1"></i>
                        @if($supply->estimated_days_remaining <= 14)
                        <span class="text-{{ $supply->colorClass() }} fw-semibold">~{{ $supply->estimated_days_remaining }}d remaining</span>
                        @else
                        ~{{ $supply->estimated_days_remaining }}d remaining
                        @endif
                    </small>
                </div>
                @endif
                @empty
                {{-- Fallback to legacy flat columns if no supply rows yet --}}
                @foreach($printer->tonerLevels() as $color => $level)
                <div class="d-flex align-items-center gap-2 mb-2">
                    <div style="width:14px;height:14px;border-radius:50%;background:{{ \App\Models\Printer::tonerColor($color) }};flex-shrink:0;border:1px solid rgba(0,0,0,0.15)"></div>
                    <small class="text-muted fw-semibold" style="width:60px">{{ $color }}</small>
                    <div class="progress flex-grow-1" style="height:18px">
                        @if($level !== null && $level >= 0)
                        <div class="progress-bar {{ \App\Models\Printer::tonerBarClass($level) }}"
                             style="width:{{ $level }}%">
                            <span class="fw-bold" style="font-size:0.7rem">{{ $level }}%</span>
                        </div>
                        @else
                        <div class="progress-bar bg-secondary" style="width:100%">
                            <span style="font-size:0.7rem">N/A</span>
                        </div>
                        @endif
                    </div>
                </div>
                @endforeach
                @endforelse

                {{-- Toner History Chart (Phase 7) --}}
                <div class="mt-3">
                    <button class="btn btn-sm btn-outline-secondary" type="button"
                            data-bs-toggle="collapse" data-bs-target="#tonerHistoryChart">
                        <i class="bi bi-graph-down-arrow me-1"></i>Toner History
                    </button>
                    <div class="collapse mt-2" id="tonerHistoryChart">
                        <canvas id="tonerChart" height="80"></canvas>
                    </div>
                </div>
            </div>

            {{-- Paper Trays & Consumables --}}
            <div class="col-md-4">
                @if($printer->paper_trays && count($printer->paper_trays) > 0)
                <h6 class="fw-semibold small text-muted mb-2"><i class="bi bi-layers me-1"></i>Paper Trays</h6>
                @foreach($printer->paper_trays as $tray)
                @php $trayPct = ($tray['max'] ?? 0) > 0 ? round(($tray['current'] ?? 0) / $tray['max'] * 100) : 0; @endphp
                <div class="d-flex align-items-center gap-2 mb-2">
                    <small class="text-muted fw-semibold" style="width:60px">{{ $tray['name'] ?? 'Tray' }}</small>
                    <div class="progress flex-grow-1" style="height:16px">
                        <div class="progress-bar {{ $trayPct <= 10 ? 'bg-danger' : ($trayPct <= 30 ? 'bg-warning' : 'bg-info') }}"
                             style="width:{{ $trayPct }}%">
                            <span style="font-size:0.65rem">{{ $tray['current'] ?? 0 }}/{{ $tray['max'] ?? '?' }}</span>
                        </div>
                    </div>
                </div>
                @endforeach
                @endif

                @if($otherSupplies->isNotEmpty())
                <h6 class="fw-semibold small text-muted mb-2 {{ $printer->paper_trays ? 'mt-3' : '' }}"><i class="bi bi-gear me-1"></i>Supplies</h6>
                @foreach($otherSupplies as $supply)
                <div class="d-flex align-items-center gap-2 mb-2">
                    <i class="bi {{ $supply->typeIcon() }} text-muted" style="font-size:0.8rem;width:14px"></i>
                    <small class="text-muted fw-semibold text-truncate" style="width:60px" title="{{ $supply->supply_descr }}">
                        {{ ucfirst($supply->supply_type) }}
                    </small>
                    <div class="progress flex-grow-1" style="height:16px">
                        @if($supply->supply_percent !== null)
                        <div class="progress-bar bg-{{ $supply->colorClass() }}"
                             style="width:{{ $supply->supply_percent }}%">
                            <span style="font-size:0.65rem">{{ $supply->supply_percent }}%</span>
                        </div>
                        @else
                        <div class="progress-bar bg-secondary" style="width:100%">
                            <span style="font-size:0.65rem">N/A</span>
                        </div>
                        @endif
                    </div>
                    @if($supply->isCritical())
                    <span class="badge bg-danger" style="font-size:0.6rem">Critical</span>
                    @elseif($supply->isLow())
                    <span class="badge bg-warning text-dark" style="font-size:0.6rem">Low</span>
                    @endif
                </div>
                @if($supply->estimated_days_remaining !== null && $supply->estimated_days_remaining <= 14)
                <div class="ms-5 mb-1">
                    <small class="text-{{ $supply->colorClass() }} fw-semibold">
                        <i class="bi bi-calendar me-1"></i>~{{ $supply->estimated_days_remaining }}d remaining
                    </small>
                </div>
                @endif
                @endforeach
                @elseif($printer->drum_black !== null || $printer->fuser_level !== null)
                {{-- Fallback to legacy drum/fuser columns --}}
                <h6 class="fw-semibold small text-muted mb-2 {{ $printer->paper_trays ? 'mt-3' : '' }}"><i class="bi bi-gear me-1"></i>Consumables</h6>
                @if($printer->drum_black !== null)
                <div class="d-flex align-items-center gap-2 mb-2">
                    <small class="text-muted fw-semibold" style="width:60px">Drum</small>
                    <div class="progress flex-grow-1" style="height:16px">
                        <div class="progress-bar bg-secondary" style="width:{{ $printer->drum_black }}%">
                            <span style="font-size:0.65rem">{{ $printer->drum_black }}%</span>
                        </div>
                    </div>
                </div>
                @endif
                @if($printer->fuser_level !== null)
                <div class="d-flex align-items-center gap-2 mb-2">
                    <small class="text-muted fw-semibold" style="width:60px">Fuser</small>
                    <div class="progress flex-grow-1" style="height:16px">
                        <div class="progress-bar bg-secondary" style="width:{{ $printer->fuser_level }}%">
                            <span style="font-size:0.65rem">{{ $printer->fuser_level }}%</span>
                        </div>
                    </div>
                </div>
                @endif
                @endif
            </div>
        </div>

        {{-- Page Counters Row --}}
        @if($printer->page_count_total)
        <div class="border-top pt-3 mt-3">
            <h6 class="fw-semibold small text-muted mb-2"><i class="bi bi-bar-chart me-1"></i>Page Counters</h6>
            <div class="row g-2 text-center">
                <div class="col">
                    <div class="card bg-light py-2">
                        <div class="fw-bold">{{ number_format($printer->page_count_total) }}</div>
                        <small class="text-muted">Total</small>
                    </div>
                </div>
                @if($printer->page_count_color !== null)
                <div class="col">
                    <div class="card bg-light py-2">
                        <div class="fw-bold text-primary">{{ number_format($printer->page_count_color) }}</div>
                        <small class="text-muted">Color</small>
                    </div>
                </div>
                @endif
                @if($printer->page_count_mono !== null)
                <div class="col">
                    <div class="card bg-light py-2">
                        <div class="fw-bold">{{ number_format($printer->page_count_mono) }}</div>
                        <small class="text-muted">Mono</small>
                    </div>
                </div>
                @endif
                @if($printer->page_count_print !== null)
                <div class="col">
                    <div class="card bg-light py-2">
                        <div class="fw-bold">{{ number_format($printer->page_count_print) }}</div>
                        <small class="text-muted">Print</small>
                    </div>
                </div>
                @endif
                @if($printer->page_count_copy !== null)
                <div class="col">
                    <div class="card bg-light py-2">
                        <div class="fw-bold">{{ number_format($printer->page_count_copy) }}</div>
                        <small class="text-muted">Copy</small>
                    </div>
                </div>
                @endif
                @if($printer->page_count_scan !== null)
                <div class="col">
                    <div class="card bg-light py-2">
                        <div class="fw-bold">{{ number_format($printer->page_count_scan) }}</div>
                        <small class="text-muted">Scan</small>
                    </div>
                </div>
                @endif
                @if($printer->page_count_fax !== null)
                <div class="col">
                    <div class="card bg-light py-2">
                        <div class="fw-bold">{{ number_format($printer->page_count_fax) }}</div>
                        <small class="text-muted">Fax</small>
                    </div>
                </div>
                @endif
            </div>
        </div>
        @endif
    </div>
</div>
@elseif(!$printer->snmp_enabled && $printer->ip_address && $printer->snmp_community)
<div class="card shadow-sm border-0 mt-3">
    <div class="card-body text-center py-3">
        <i class="bi bi-activity text-muted fs-4"></i>
        <p class="text-muted mb-2 small">SNMP monitoring is available but not enabled.</p>
        <form method="POST" action="{{ route('admin.printers.snmp.toggle', $printer) }}" class="d-inline">
            @csrf
            <button type="submit" class="btn btn-sm btn-outline-primary">
                <i class="bi bi-toggle-on me-1"></i>Enable SNMP Monitoring
            </button>
        </form>
    </div>
</div>
@endif

{{-- Maintenance History --}}
<div class="card shadow-sm border-0 mt-3">
    <div class="card-header bg-transparent d-flex justify-content-between align-items-center">
        <strong><i class="bi bi-tools me-2"></i>Maintenance History</strong>
        @can('manage-printers')
        <a href="{{ route('admin.printers.maintenance.index', $printer) }}" class="btn btn-sm btn-outline-primary">
            <i class="bi bi-plus-lg me-1"></i>Add Record
        </a>
        @endcan
    </div>
    <div class="card-body p-0">
        @if($maintenanceLogs->isEmpty())
        <div class="text-center py-3 text-muted small"><i class="bi bi-wrench me-1"></i>No maintenance records yet.</div>
        @else
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 small">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">Date</th>
                        <th>Type</th>
                        <th>Performed By</th>
                        <th>Cost</th>
                        <th class="pe-3">Notes</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($maintenanceLogs->take(5) as $log)
                    <tr>
                        <td class="ps-3 text-nowrap">{{ $log->performed_at->format('d M Y') }}</td>
                        <td>
                            <span class="badge {{ $log->typeBadgeClass() }}">
                                <i class="{{ $log->typeIcon() }} me-1"></i>{{ $log->typeLabel() }}
                            </span>
                        </td>
                        <td>{{ $log->performerName() }}</td>
                        <td>{{ $log->cost ? number_format($log->cost, 2) . ' SAR' : '—' }}</td>
                        <td class="pe-3 text-muted">{{ \Illuminate\Support\Str::limit($log->notes ?? $log->description, 60) }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @if($maintenanceLogs->count() > 5)
        <div class="text-center py-2 border-top">
            <a href="{{ route('admin.printers.maintenance.index', $printer) }}" class="btn btn-sm btn-link">
                <i class="bi bi-arrow-right me-1"></i>View all {{ $maintenanceLogs->count() }} records
            </a>
        </div>
        @endif
        @endif
    </div>
</div>

@can('manage-printers')
<div class="mt-4">
    <form method="POST" action="{{ route('admin.printers.destroy', $printer) }}"
          onsubmit="return confirm('Delete printer \'{{ addslashes($printer->printer_name) }}\'? This cannot be undone.')">
        @csrf @method('DELETE')
        <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash me-1"></i>Delete Printer</button>
    </form>
</div>
@endcan

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
(function () {
    let tonerChart = null;
    let tonerLoaded = false;

    document.getElementById('tonerHistoryChart').addEventListener('show.bs.collapse', function () {
        if (tonerLoaded) return;
        tonerLoaded = true;

        fetch('/admin/printers/{{ $printer->id }}/toner-history?days=14')
            .then(r => r.json())
            .then(datasets => {
                if (!datasets || !datasets.length) {
                    document.getElementById('tonerChart').insertAdjacentHTML('afterend',
                        '<p class="text-muted small text-center mt-2">No toner history data available yet.</p>');
                    return;
                }

                const colorMap = {
                    'black':   '#343a40',
                    'cyan':    '#17a2b8',
                    'magenta': '#e83e8c',
                    'yellow':  '#ffc107',
                };

                function resolveColor(label) {
                    const lower = label.toLowerCase();
                    for (const [key, val] of Object.entries(colorMap)) {
                        if (lower.includes(key)) return val;
                    }
                    return '#6c757d';
                }

                tonerChart = new Chart(document.getElementById('tonerChart'), {
                    type: 'line',
                    data: {
                        labels: datasets[0].data.map(d => new Date(d.ts).toLocaleDateString()),
                        datasets: datasets.map(ds => ({
                            label: ds.label,
                            data: ds.data.map(d => d.v),
                            borderColor: resolveColor(ds.label),
                            backgroundColor: 'transparent',
                            fill: false,
                            tension: 0.3,
                            pointRadius: ds.data.length > 100 ? 0 : 3,
                            borderWidth: 2,
                        }))
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            y: {
                                min: 0,
                                max: 100,
                                title: { display: true, text: '%' },
                                ticks: { callback: v => v + '%' },
                            },
                            x: {
                                ticks: { maxTicksLimit: 10, maxRotation: 0 },
                                grid: { display: false },
                            }
                        },
                        plugins: {
                            legend: { position: 'top' },
                            tooltip: {
                                callbacks: { label: ctx => `${ctx.dataset.label}: ${ctx.parsed.y}%` }
                            }
                        }
                    }
                });
            })
            .catch(err => console.error('Toner history load failed:', err));
    });
})();
</script>
@endpush
@endsection
