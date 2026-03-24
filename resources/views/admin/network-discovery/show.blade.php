@extends('layouts.admin')
@section('content')

{{-- ── Header ── --}}
<div class="d-flex justify-content-between align-items-start mb-4">
    <div>
        <a href="{{ route('admin.network-discovery.index') }}" class="btn btn-sm btn-outline-secondary mb-2">
            <i class="bi bi-arrow-left me-1"></i>Back to Scans
        </a>
        <h4 class="mb-0 fw-bold"><i class="bi bi-radar me-2 text-primary"></i>{{ $scan->name }}</h4>
        <small class="text-muted font-monospace">{{ $scan->range_input }}</small>
        @if($scan->branch)
            <span class="badge bg-light text-dark border ms-2">{{ $scan->branch->name }}</span>
        @endif
    </div>
    <div class="text-end">
        <span class="badge bg-{{ $scan->statusBadgeClass() }} fs-6 mb-1">
            @if($scan->status === 'running')
                <span class="spinner-border spinner-border-sm me-1" style="width:.7rem;height:.7rem"></span>
            @endif
            {{ ucfirst($scan->status) }}
        </span>
        @if($scan->error_message)
        <div class="text-danger small mt-1"><i class="bi bi-exclamation-triangle me-1"></i>{{ $scan->error_message }}</div>
        @endif
    </div>
</div>

@if(session('success'))
<div class="alert alert-success alert-dismissible fade show py-2"><i class="bi bi-check-circle me-1"></i>{{ session('success') }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
@endif
@if(session('error'))
<div class="alert alert-danger alert-dismissible fade show py-2"><i class="bi bi-exclamation-circle me-1"></i>{{ session('error') }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
@endif

{{-- ── Summary Cards ── --}}
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-md-3">
        <div class="card border-0 shadow-sm text-center py-3">
            <div class="fs-3 fw-bold text-primary">{{ $scan->total_hosts }}</div>
            <div class="text-muted small">Hosts Scanned</div>
        </div>
    </div>
    <div class="col-sm-6 col-md-3">
        <div class="card border-0 shadow-sm text-center py-3">
            <div class="fs-3 fw-bold text-success">{{ $scan->reachable_count }}</div>
            <div class="text-muted small">Reachable</div>
        </div>
    </div>
    <div class="col-sm-6 col-md-3">
        <div class="card border-0 shadow-sm text-center py-3">
            <div class="fs-3 fw-bold text-info">{{ $results->where('snmp_accessible', true)->count() }}</div>
            <div class="text-muted small">SNMP Accessible</div>
        </div>
    </div>
    <div class="col-sm-6 col-md-3">
        <div class="card border-0 shadow-sm text-center py-3">
            <div class="fs-3 fw-bold text-secondary">{{ $scan->duration() ?? '—' }}</div>
            <div class="text-muted small">Duration</div>
        </div>
    </div>
</div>

{{-- ── Auto-refresh if still running ── --}}
@if($scan->status === 'running')
<div class="alert alert-info py-2 d-flex align-items-center gap-2 mb-3">
    <span class="spinner-border spinner-border-sm text-info"></span>
    <span>Scan is running — page will refresh automatically every 10 seconds.</span>
</div>
<script>setTimeout(() => location.reload(), 10000);</script>
@endif

{{-- ── Results Table ── --}}
@if($results->isEmpty())
    @if($scan->status === 'pending' || $scan->status === 'running')
    <div class="text-center text-muted py-5">
        <span class="spinner-border text-primary mb-3"></span>
        <p>Waiting for results…</p>
    </div>
    @else
    <div class="text-center text-muted py-5">
        <i class="bi bi-wifi-off fs-2 d-block mb-2"></i>No reachable hosts found in this range.
    </div>
    @endif
@else
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white d-flex justify-content-between align-items-center py-3">
        <span class="fw-semibold"><i class="bi bi-list-ul me-2 text-secondary"></i>Results ({{ $results->count() }} hosts)</span>
        <div class="d-flex gap-2 align-items-center">
            {{-- Filter tabs --}}
            <div class="btn-group btn-group-sm" id="typeFilter">
                <button class="btn btn-outline-secondary active" data-type="all">All</button>
                <button class="btn btn-outline-primary"   data-type="printer"><i class="bi bi-printer me-1"></i>Printers</button>
                <button class="btn btn-outline-info"      data-type="switch"><i class="bi bi-diagram-3 me-1"></i>Switches</button>
                <button class="btn btn-outline-secondary" data-type="device"><i class="bi bi-cpu me-1"></i>Devices</button>
                <button class="btn btn-outline-light text-muted" data-type="unknown"><i class="bi bi-question-circle me-1"></i>Unknown</button>
            </div>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0" id="resultsTable">
            <thead class="table-light">
                <tr>
                    <th>IP Address</th>
                    <th>Hostname / sysName</th>
                    <th>Vendor / Model</th>
                    <th>Type</th>
                    <th>SNMP</th>
                    <th>sysDescr</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach($results as $r)
                @php $isReachable = $r->is_reachable; @endphp
                <tr class="result-row" data-type="{{ $r->device_type }}" data-reachable="{{ $isReachable ? '1' : '0' }}"
                    style="{{ ! $isReachable ? 'opacity:.45' : '' }}">
                    <td>
                        <span class="font-monospace fw-semibold">{{ $r->ip_address }}</span>
                        @if(! $isReachable)
                            <span class="badge bg-light text-muted border ms-1 small">offline</span>
                        @endif
                    </td>
                    <td>
                        @if($r->sys_name)
                            <span class="fw-semibold">{{ $r->sys_name }}</span><br>
                        @endif
                        @if($r->hostname && $r->hostname !== $r->ip_address)
                            <small class="text-muted">{{ $r->hostname }}</small>
                        @endif
                        @if(! $r->sys_name && ! $r->hostname) <span class="text-muted">—</span> @endif
                    </td>
                    <td>
                        @if($r->vendor || $r->model)
                            <span class="text-muted small">{{ $r->vendor }}</span>
                            @if($r->vendor && $r->model) <span class="text-muted"> / </span> @endif
                            <span class="small">{{ $r->model }}</span>
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </td>
                    <td>
                        <span class="badge bg-{{ $r->deviceTypeBadgeClass() }}">
                            <i class="{{ $r->deviceTypeIcon() }} me-1"></i>{{ ucfirst($r->device_type) }}
                        </span>
                    </td>
                    <td>
                        @if($r->snmp_accessible)
                            <i class="bi bi-check-circle-fill text-success" title="SNMP accessible"></i>
                        @elseif($isReachable)
                            <i class="bi bi-x-circle text-muted" title="SNMP not accessible"></i>
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </td>
                    <td class="text-muted small" style="max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
                        title="{{ $r->sys_descr }}">
                        {{ $r->sys_descr ? \Illuminate\Support\Str::limit($r->sys_descr, 80) : '—' }}
                    </td>
                    <td class="text-end" style="min-width:160px;">
                        @if($r->already_imported)
                            <span class="badge bg-success"><i class="bi bi-check2 me-1"></i>Imported</span>
                        @elseif($isReachable)
                            @can('manage-printers')
                            <div class="dropdown d-inline">
                                <button class="btn btn-sm btn-outline-primary dropdown-toggle" data-bs-toggle="dropdown">
                                    <i class="bi bi-box-arrow-in-down me-1"></i>Import
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end">
                                    @foreach(['printer' => ['bi-printer','Printer'], 'switch' => ['bi-diagram-3','Switch'], 'device' => ['bi-cpu','Device']] as $importType => [$icon, $label])
                                    <li>
                                        <form action="{{ route('admin.network-discovery.import', [$scan, $r]) }}" method="POST">
                                            @csrf
                                            <input type="hidden" name="import_as" value="{{ $importType }}">
                                            <button type="submit" class="dropdown-item">
                                                <i class="bi {{ $icon }} me-2"></i>As {{ $label }}
                                            </button>
                                        </form>
                                    </li>
                                    @endforeach
                                </ul>
                            </div>
                            @endcan
                        @else
                            <span class="text-muted small">Not reachable</span>
                        @endif
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endif

@push('scripts')
<script>
document.querySelectorAll('#typeFilter button').forEach(btn => {
    btn.addEventListener('click', function () {
        document.querySelectorAll('#typeFilter button').forEach(b => b.classList.remove('active'));
        this.classList.add('active');
        const type = this.dataset.type;
        document.querySelectorAll('.result-row').forEach(row => {
            row.style.display = (type === 'all' || row.dataset.type === type) ? '' : 'none';
        });
    });
});
</script>
@endpush

@endsection
