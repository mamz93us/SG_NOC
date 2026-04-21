@extends('layouts.admin')
@section('title', 'Switch QoS: ' . $latestSnapshot->device_name)

@section('content')
<div class="mb-3">
    <a href="{{ route('admin.switch-qos.index') }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Back to Stats</a>
</div>

@if(session('success'))
<div class="alert alert-success alert-dismissible fade show">{{ session('success') }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
@endif
@if(session('error'))
<div class="alert alert-warning alert-dismissible fade show">{{ session('error') }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
@endif

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-speedometer2 me-2 text-primary"></i>{{ $latestSnapshot->device_name }}</h4>
        <small class="text-muted font-monospace">{{ $deviceIp }}</small>
    </div>
    <div class="d-flex gap-2 align-items-center flex-wrap">
        <span class="badge bg-secondary">Last polled {{ $latestSnapshot->polled_at?->diffForHumans() ?: '—' }}</span>
        @if($device)
            @can('manage-credentials')
            <a href="{{ route('admin.switch-qos.telnet', $device->id) }}" class="btn btn-sm btn-dark" title="Open in-browser telnet console">
                <i class="bi bi-terminal me-1"></i>Open Telnet
            </a>
            <form method="POST" action="{{ route('admin.switch-qos.configs.fetch', $device->id) }}" class="d-inline">
                @csrf
                <button type="submit" class="btn btn-sm btn-outline-primary" title="Capture `show running-config` and archive it">
                    <i class="bi bi-file-earmark-code me-1"></i>Fetch Config
                </button>
            </form>
            <a href="{{ route('admin.switch-qos.configs.show', $device->id) }}" class="btn btn-sm btn-outline-secondary" title="View archived configs">
                <i class="bi bi-journal-code"></i>
            </a>
            <form method="POST" action="{{ route('admin.switch-qos.poll', $device->id) }}" class="d-inline">
                @csrf
                <button type="submit" class="btn btn-sm btn-success" title="Run the poller now">
                    <i class="bi bi-play-fill me-1"></i>Poll Now
                </button>
            </form>
            <form method="POST" action="{{ route('admin.switch-qos.clear', $device->id) }}" class="d-inline"
                  onsubmit="return confirm('Reset all MLS QoS counters on the switch?\n\nThis runs `clear mls qos interface statistics` on the device — cumulative drop counters will start from zero after the next poll.');">
                @csrf
                <button type="submit" class="btn btn-sm btn-outline-danger" title="Clear counters on the switch">
                    <i class="bi bi-eraser me-1"></i>Clear Stats
                </button>
            </form>
            @endcan
            <a href="{{ route('admin.switch-qos.setup', $device->id) }}" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-gear me-1"></i>Setup
            </a>
        @endif
        <a href="{{ route('admin.switch-qos.compare', urlencode($deviceIp)) }}" class="btn btn-sm btn-outline-primary">
            <i class="bi bi-arrow-left-right me-1"></i>Compare Polls
        </a>
    </div>
</div>

{{-- ─── Capability + Credentials Row ──────────────────────────────────── --}}
<div class="row g-3 mb-4">
    {{-- Capability card --}}
    <div class="col-lg-5">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-transparent fw-semibold d-flex justify-content-between align-items-center">
                <span><i class="bi bi-patch-check me-1"></i>Device Capability</span>
                @if($device && auth()->user()->can('manage-credentials'))
                <form method="POST" action="{{ route('admin.switch-qos.test', $device->id) }}" class="d-inline">
                    @csrf
                    <button type="submit" class="btn btn-sm btn-outline-primary" title="Probe telnet + MLS QoS support now">
                        <i class="bi bi-broadcast me-1"></i>Test Now
                    </button>
                </form>
                @endif
            </div>
            <div class="card-body">
                @if($device)
                <table class="table table-sm borderless mb-0">
                    <tbody>
                        <tr>
                            <td class="text-muted" style="width:45%">Telnet reachable</td>
                            <td>
                                @if($device->telnet_reachable === true)
                                    <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Yes</span>
                                @elseif($device->telnet_reachable === false)
                                    <span class="badge bg-danger"><i class="bi bi-x-circle me-1"></i>No</span>
                                @else
                                    <span class="badge bg-secondary">Unknown</span>
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <td class="text-muted">MLS QoS supported</td>
                            <td>
                                @if($device->mls_qos_supported === true)
                                    <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Supported</span>
                                @elseif($device->mls_qos_supported === false)
                                    <span class="badge bg-warning text-dark"><i class="bi bi-slash-circle me-1"></i>Not supported</span>
                                @else
                                    <span class="badge bg-secondary">Unknown</span>
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <td class="text-muted">Has telnet credential</td>
                            <td>
                                @if($telnetCred)
                                    <span class="badge bg-success"><i class="bi bi-key me-1"></i>Set</span>
                                @else
                                    <span class="badge bg-danger"><i class="bi bi-exclamation-triangle me-1"></i>Missing</span>
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <td class="text-muted">Has enable credential</td>
                            <td>
                                @if($enableCred)
                                    <span class="badge bg-success"><i class="bi bi-key me-1"></i>Set</span>
                                @else
                                    <span class="badge bg-secondary">Not set</span>
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <td class="text-muted">Last probed</td>
                            <td class="small">{{ $device->qos_probed_at?->diffForHumans() ?? '— never —' }}</td>
                        </tr>
                        @if($device->qos_probe_error)
                        <tr>
                            <td colspan="2">
                                <div class="alert alert-warning py-2 small mb-0">
                                    <i class="bi bi-exclamation-triangle me-1"></i>{{ $device->qos_probe_error }}
                                </div>
                            </td>
                        </tr>
                        @endif
                    </tbody>
                </table>
                @else
                <div class="text-muted small">Device record not found in inventory.</div>
                @endif
            </div>
        </div>
    </div>

    {{-- Credentials card --}}
    <div class="col-lg-7">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-transparent fw-semibold">
                <i class="bi bi-shield-lock me-1"></i>Telnet Credentials
                <small class="text-muted fw-normal ms-2">stored encrypted (AES via APP_KEY)</small>
            </div>
            <div class="card-body">
                @if(!$device)
                    <div class="text-muted small">No device inventory record — cannot manage credentials.</div>
                @elseif(!auth()->user()->can('manage-credentials'))
                    <div class="text-muted small">
                        <i class="bi bi-lock me-1"></i>You don't have the <code>manage-credentials</code> permission.
                    </div>
                    <div class="mt-2">
                        Telnet: {!! $telnetCred ? '<span class="badge bg-success">Set</span>' : '<span class="badge bg-danger">Missing</span>' !!}
                        &nbsp; Enable: {!! $enableCred ? '<span class="badge bg-success">Set</span>' : '<span class="badge bg-secondary">Not set</span>' !!}
                    </div>
                @else
                    {{-- Telnet credential row --}}
                    <form method="POST" action="{{ route('admin.switch-qos.credentials.save', $device->id) }}" class="row g-2 align-items-end mb-3">
                        @csrf
                        <input type="hidden" name="category" value="telnet">
                        <div class="col-md-4">
                            <label class="form-label small text-muted mb-1">Telnet (vty) password</label>
                            <div class="input-group input-group-sm">
                                <input type="password" name="password" class="form-control" placeholder="{{ $telnetCred ? '•••••••• (set)' : 'not set' }}" autocomplete="new-password">
                                <button type="button" class="btn btn-outline-secondary toggle-pw" tabindex="-1"><i class="bi bi-eye"></i></button>
                            </div>
                        </div>
                        <div class="col-auto">
                            <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-save me-1"></i>{{ $telnetCred ? 'Update' : 'Save' }}</button>
                        </div>
                        @if($telnetCred)
                        <div class="col-auto">
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#revealTelnet">
                                <i class="bi bi-eye me-1"></i>Reveal current
                            </button>
                        </div>
                        @endif
                    </form>
                    @if($telnetCred)
                    <form method="POST" action="{{ route('admin.switch-qos.credentials.delete', [$device->id, $telnetCred->id]) }}" class="d-inline mb-3" onsubmit="return confirm('Remove telnet credential?')">
                        @csrf @method('DELETE')
                        <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash me-1"></i>Remove telnet</button>
                    </form>
                    @endif

                    {{-- Enable credential row --}}
                    <form method="POST" action="{{ route('admin.switch-qos.credentials.save', $device->id) }}" class="row g-2 align-items-end">
                        @csrf
                        <input type="hidden" name="category" value="enable">
                        <div class="col-md-4">
                            <label class="form-label small text-muted mb-1">Enable secret</label>
                            <div class="input-group input-group-sm">
                                <input type="password" name="password" class="form-control" placeholder="{{ $enableCred ? '•••••••• (set)' : 'not set' }}" autocomplete="new-password">
                                <button type="button" class="btn btn-outline-secondary toggle-pw" tabindex="-1"><i class="bi bi-eye"></i></button>
                            </div>
                        </div>
                        <div class="col-auto">
                            <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-save me-1"></i>{{ $enableCred ? 'Update' : 'Save' }}</button>
                        </div>
                        @if($enableCred)
                        <div class="col-auto">
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#revealEnable">
                                <i class="bi bi-eye me-1"></i>Reveal current
                            </button>
                        </div>
                        @endif
                    </form>
                    @if($enableCred)
                    <form method="POST" action="{{ route('admin.switch-qos.credentials.delete', [$device->id, $enableCred->id]) }}" class="d-inline mt-2" onsubmit="return confirm('Remove enable credential?')">
                        @csrf @method('DELETE')
                        <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash me-1"></i>Remove enable</button>
                    </form>
                    @endif

                    {{-- Reveal modals --}}
                    @if($telnetCred)
                    <div class="modal fade" id="revealTelnet" tabindex="-1">
                        <div class="modal-dialog modal-sm"><div class="modal-content">
                            <div class="modal-header"><h6 class="modal-title">Telnet password</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                            <div class="modal-body font-monospace small text-break">{{ $telnetCred->password }}</div>
                        </div></div>
                    </div>
                    @endif
                    @if($enableCred)
                    <div class="modal fade" id="revealEnable" tabindex="-1">
                        <div class="modal-dialog modal-sm"><div class="modal-content">
                            <div class="modal-header"><h6 class="modal-title">Enable secret</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                            <div class="modal-body font-monospace small text-break">{{ $enableCred->password }}</div>
                        </div></div>
                    </div>
                    @endif
                @endif
            </div>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-transparent fw-semibold"><i class="bi bi-hdd-network me-1"></i>Per-Interface QoS Summary (latest poll)</div>
    <div class="card-body p-0">
        @if($interfaces->isEmpty())
        <div class="text-muted text-center py-4 small">No interface data available</div>
        @else
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0 small">
                <thead class="table-light">
                    <tr>
                        <th>Interface</th>
                        <th class="text-center" colspan="3">Queue 0 drops</th>
                        <th class="text-center" colspan="3">Queue 1 drops</th>
                        <th class="text-center" colspan="3">Queue 2 drops</th>
                        <th class="text-center" colspan="3">Queue 3 drops</th>
                        <th>Policer OoP</th>
                        <th>Total</th>
                        <th>Polled</th>
                    </tr>
                    <tr class="text-muted" style="font-size:0.7rem">
                        <th></th>
                        <th>t1</th><th>t2</th><th>t3</th>
                        <th>t1</th><th>t2</th><th>t3</th>
                        <th>t1</th><th>t2</th><th>t3</th>
                        <th>t1</th><th>t2</th><th>t3</th>
                        <th></th><th></th><th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($interfaces as $iface)
                    <tr class="{{ $iface->total_drops >= 1000 ? 'table-danger' : ($iface->total_drops >= 100 ? 'table-warning' : '') }}">
                        <td class="fw-semibold font-monospace">{{ $iface->interface_name }}</td>
                        <td>{{ number_format($iface->q0_t1_drop) }}</td><td>{{ number_format($iface->q0_t2_drop) }}</td><td>{{ number_format($iface->q0_t3_drop) }}</td>
                        <td>{{ number_format($iface->q1_t1_drop) }}</td><td>{{ number_format($iface->q1_t2_drop) }}</td><td>{{ number_format($iface->q1_t3_drop) }}</td>
                        <td>{{ number_format($iface->q2_t1_drop) }}</td><td>{{ number_format($iface->q2_t2_drop) }}</td><td>{{ number_format($iface->q2_t3_drop) }}</td>
                        <td>{{ number_format($iface->q3_t1_drop) }}</td><td>{{ number_format($iface->q3_t2_drop) }}</td><td>{{ number_format($iface->q3_t3_drop) }}</td>
                        <td class="text-muted">{{ number_format($iface->policer_out_of_profile) }}</td>
                        <td>
                            <span class="badge bg-{{ $iface->total_drops >= 1000 ? 'danger' : ($iface->total_drops >= 100 ? 'warning text-dark' : ($iface->total_drops > 0 ? 'info' : 'secondary')) }}">
                                {{ number_format($iface->total_drops) }}
                            </span>
                        </td>
                        <td class="text-muted small">{{ $iface->polled_at?->format('H:i') ?: '—' }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>
</div>

{{-- ─── Traffic + Errors + Drop% ─────────────────────────────────────── --}}
@if($ifaceStats->isNotEmpty())
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-transparent fw-semibold d-flex justify-content-between align-items-center">
        <span><i class="bi bi-activity me-1"></i>Interface Traffic, Errors &amp; Drop Percentage</span>
        <small class="text-muted fw-normal">all counters are cumulative since last <code>clear counters</code></small>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0 small">
                <thead class="table-light">
                    <tr>
                        <th>Interface</th>
                        <th class="text-end">In Pkts</th>
                        <th class="text-end">Out Pkts</th>
                        <th class="text-end">QoS Drops</th>
                        <th class="text-end">Drop %</th>
                        <th class="text-end">Out Discards</th>
                        <th class="text-end">FCS Err</th>
                        <th class="text-end">Align Err</th>
                        <th class="text-end">Rcv Err</th>
                        <th class="text-end">Runts</th>
                        <th class="text-end">Giants</th>
                        <th class="text-end">Late Col</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($ifaceStats as $name => $s)
                    @php
                        $qos     = $interfaces->firstWhere('interface_name', $name);
                        $drops   = $qos ? (int) $qos->total_drops : 0;
                        $pct     = $s->drop_percentage;
                        $anyErr  = ($s->fcs_err + $s->align_err + $s->rcv_err + $s->runts + $s->giants + $s->late_col + $s->excess_col) > 0;
                        $rowCls  = $pct !== null && $pct >= 0.1 ? 'table-warning' : ($anyErr ? 'table-danger' : '');
                    @endphp
                    <tr class="{{ $rowCls }}">
                        <td class="fw-semibold font-monospace">{{ $name }}</td>
                        <td class="text-end text-muted">{{ number_format($s->total_in_pkts) }}</td>
                        <td class="text-end text-muted">{{ number_format($s->total_out_pkts) }}</td>
                        <td class="text-end">{{ number_format($drops) }}</td>
                        <td class="text-end fw-bold">
                            @if($pct === null || $pct === 0.0 || $pct === '0.0000')
                                <span class="text-muted">0.00%</span>
                            @else
                                <span class="badge bg-{{ $pct >= 1 ? 'danger' : ($pct >= 0.1 ? 'warning text-dark' : 'info') }}">
                                    {{ number_format($pct, 4) }}%
                                </span>
                            @endif
                        </td>
                        <td class="text-end">{{ $s->out_discards ? number_format($s->out_discards) : '—' }}</td>
                        <td class="text-end">{{ $s->fcs_err     ? number_format($s->fcs_err)     : '—' }}</td>
                        <td class="text-end">{{ $s->align_err   ? number_format($s->align_err)   : '—' }}</td>
                        <td class="text-end">{{ $s->rcv_err     ? number_format($s->rcv_err)     : '—' }}</td>
                        <td class="text-end">{{ $s->runts       ? number_format($s->runts)       : '—' }}</td>
                        <td class="text-end">{{ $s->giants      ? number_format($s->giants)      : '—' }}</td>
                        <td class="text-end">{{ $s->late_col    ? number_format($s->late_col)    : '—' }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
@endif

{{-- ─── CDP Neighbors ────────────────────────────────────────────────── --}}
@if($cdpNeighbors->isNotEmpty())
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-transparent fw-semibold d-flex justify-content-between align-items-center">
        <span><i class="bi bi-diagram-3 me-1"></i>CDP Neighbors</span>
        <a href="{{ route('admin.switch-qos.topology') }}" class="btn btn-sm btn-outline-primary">
            <i class="bi bi-bounding-box me-1"></i>Topology Map
        </a>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0 small">
                <thead class="table-light">
                    <tr>
                        <th>Local Interface</th>
                        <th>Neighbor</th>
                        <th>Match</th>
                        <th>Neighbor IP</th>
                        <th>Neighbor Port</th>
                        <th>Platform</th>
                        <th>Capabilities</th>
                        <th>Holdtime</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($cdpNeighbors as $n)
                    <tr>
                        <td class="font-monospace">{{ $n->local_interface }}</td>
                        <td class="fw-semibold">{{ $n->neighbor_device_id }}</td>
                        <td>
                            @php($phone = $cdpPhonesByMac[$n->neighbor_mac] ?? null)
                            @if($n->merakiSwitch)
                                <a href="{{ route('admin.network.switch-detail', $n->merakiSwitch->serial) }}" class="badge text-decoration-none" style="background:#d1e7dd;color:#146c43;border:1px solid #146c43;">
                                    <i class="bi bi-hdd-network me-1"></i>Meraki · {{ $n->merakiSwitch->name ?: $n->merakiSwitch->serial }}
                                </a>
                            @elseif($phone)
                                @if($phone->extension)
                                    <a href="{{ route('admin.extensions.details', $phone->extension) }}" class="badge text-decoration-none" style="background:#f8d7da;color:#b02a37;border:1px solid #b02a37;">
                                        <i class="bi bi-telephone me-1"></i>Ext {{ $phone->extension }}
                                    </a>
                                @else
                                    <span class="badge" style="background:#f8d7da;color:#b02a37;border:1px solid #b02a37;">
                                        <i class="bi bi-telephone me-1"></i>Phone
                                    </span>
                                @endif
                            @elseif($n->matchedDevice)
                                @if(in_array($n->matchedDevice->type, ['switch','router'], true))
                                    <a href="{{ route('admin.switch-qos.setup', $n->matchedDevice->id) }}" class="badge text-decoration-none" style="background:#fff3cd;color:#997404;border:1px solid #997404;">
                                        <i class="bi bi-router me-1"></i>{{ $n->matchedDevice->name ?: $n->matchedDevice->ip_address }}
                                    </a>
                                @else
                                    <span class="badge" style="background:#fff3cd;color:#997404;border:1px solid #997404;">
                                        {{ $n->matchedDevice->name ?: $n->matchedDevice->ip_address }}
                                    </span>
                                @endif
                            @else
                                <span class="text-muted small">—</span>
                            @endif
                        </td>
                        <td class="font-monospace text-muted">{{ $n->neighbor_ip ?: '—' }}</td>
                        <td class="font-monospace text-muted">{{ $n->neighbor_port ?: '—' }}</td>
                        <td class="text-muted">{{ $n->platform ?: '—' }}</td>
                        <td class="text-muted">{{ $n->capabilities ?: '—' }}</td>
                        <td class="text-muted">{{ $n->holdtime ? $n->holdtime . 's' : '—' }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
@endif

@if($trend->isNotEmpty())
<div class="card border-0 shadow-sm">
    <div class="card-header bg-transparent fw-semibold"><i class="bi bi-graph-up me-1"></i>Cumulative Drop Counter — Last 24h (Top 5 Interfaces)</div>
    <div class="card-body">
        <canvas id="qosTrendChart" height="100"></canvas>
    </div>
</div>
@endif
@endsection

@push('scripts')
<script>
document.querySelectorAll('.toggle-pw').forEach(btn => {
    btn.addEventListener('click', () => {
        const input = btn.parentElement.querySelector('input');
        const icon  = btn.querySelector('i');
        if (input.type === 'password') {
            input.type = 'text';
            icon.classList.replace('bi-eye', 'bi-eye-slash');
        } else {
            input.type = 'password';
            icon.classList.replace('bi-eye-slash', 'bi-eye');
        }
    });
});
</script>
@if($trend->isNotEmpty())
<script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
<script>
const trendData = @json($trend);
const allLabels = [...new Set(Object.values(trendData).flat().map(d => d.label))].sort();
const colors = ['#dc3545','#fd7e14','#ffc107','#0d6efd','#6610f2','#20c997','#0dcaf0','#198754'];

const datasets = Object.entries(trendData).slice(0, 5).map(([ifName, rows], i) => {
    const rowMap = {};
    rows.forEach(r => { rowMap[r.label] = parseInt(r.total_drops) || 0; });
    return {
        label: ifName,
        data: allLabels.map(l => rowMap[l] || 0),
        borderColor: colors[i % colors.length],
        backgroundColor: 'transparent',
        tension: 0.3,
        pointRadius: 2,
    };
});

new Chart(document.getElementById('qosTrendChart'), {
    type: 'line',
    data: { labels: allLabels, datasets },
    options: {
        plugins: { legend: { position: 'top', labels: { boxWidth: 12, font: { size: 11 } } } },
        scales: { y: { beginAtZero: true, ticks: { precision: 0 } } },
        responsive: true
    }
});
</script>
@endif
@endpush
