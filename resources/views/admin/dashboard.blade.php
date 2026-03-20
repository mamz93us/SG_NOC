@extends('layouts.admin')

@section('content')

{{-- ═══════════════════════════════════════════════════════════════════
     PAGE HEADER
══════════════════════════════════════════════════════════════════════ --}}
<div class="d-flex align-items-center mb-4">
    <div>
        <h1 class="h3 mb-0 fw-bold">Dashboard</h1>
        <small class="text-muted">System overview — UCM stats cached for 5 minutes</small>
    </div>
    <div class="ms-auto">
        <a href="{{ route('admin.gdms.ucm') }}" class="btn btn-sm btn-outline-primary">
            <i class="bi bi-arrow-repeat me-1"></i>Full UCM Status
        </a>
    </div>
</div>

{{-- ═══════════════════════════════════════════════════════════════════
     ROW 1 — DIRECTORY STATS
══════════════════════════════════════════════════════════════════════ --}}
<div class="row g-3 mb-4">

    {{-- Contacts --}}
    <div class="col-6 col-md-3">
        <a href="{{ route('admin.contacts.index') }}" class="text-decoration-none">
            <div class="card border-0 shadow-sm h-100 bg-primary bg-gradient text-white">
                <div class="card-body d-flex align-items-center gap-3">
                    <div class="rounded-circle bg-white bg-opacity-25 d-flex align-items-center justify-content-center" style="width:52px;height:52px;flex-shrink:0;">
                        <i class="bi bi-person-lines-fill fs-4"></i>
                    </div>
                    <div>
                        <div class="fs-2 fw-bold lh-1">{{ number_format($contactCount) }}</div>
                        <div class="small opacity-75 mt-1">Contacts</div>
                    </div>
                </div>
            </div>
        </a>
    </div>

    {{-- Branches --}}
    <div class="col-6 col-md-3">
        <a href="{{ route('admin.branches.index') }}" class="text-decoration-none">
            <div class="card border-0 shadow-sm h-100 bg-info bg-gradient text-white">
                <div class="card-body d-flex align-items-center gap-3">
                    <div class="rounded-circle bg-white bg-opacity-25 d-flex align-items-center justify-content-center" style="width:52px;height:52px;flex-shrink:0;">
                        <i class="bi bi-diagram-3-fill fs-4"></i>
                    </div>
                    <div>
                        <div class="fs-2 fw-bold lh-1">{{ number_format($branchCount) }}</div>
                        <div class="small opacity-75 mt-1">Branches</div>
                    </div>
                </div>
            </div>
        </a>
    </div>

    {{-- UCM Online --}}
    <div class="col-6 col-md-3">
        <a href="{{ route('admin.gdms.ucm') }}" class="text-decoration-none">
            <div class="card border-0 shadow-sm h-100 {{ $ucmOnline > 0 ? 'bg-success' : 'bg-secondary' }} bg-gradient text-white">
                <div class="card-body d-flex align-items-center gap-3">
                    <div class="rounded-circle bg-white bg-opacity-25 d-flex align-items-center justify-content-center" style="width:52px;height:52px;flex-shrink:0;">
                        <i class="bi bi-router-fill fs-4"></i>
                    </div>
                    <div>
                        <div class="fs-2 fw-bold lh-1">{{ $ucmOnline }}<span class="fs-5 opacity-75">/{{ count($ucmStats) }}</span></div>
                        <div class="small opacity-75 mt-1">UCM Online</div>
                    </div>
                </div>
            </div>
        </a>
    </div>

    {{-- Phones requesting XML --}}
    <div class="col-6 col-md-3">
        <a href="{{ route('admin.phone-logs.index') }}" class="text-decoration-none">
            <div class="card border-0 shadow-sm h-100 bg-warning bg-gradient text-dark">
                <div class="card-body d-flex align-items-center gap-3">
                    <div class="rounded-circle bg-dark bg-opacity-10 d-flex align-items-center justify-content-center" style="width:52px;height:52px;flex-shrink:0;">
                        <i class="bi bi-phone-fill fs-4"></i>
                    </div>
                    <div>
                        <div class="fs-2 fw-bold lh-1">{{ number_format($phoneRequestCount) }}</div>
                        <div class="small opacity-75 mt-1">Phones Requesting XML</div>
                        <div class="small opacity-50">{{ number_format($totalXmlRequests) }} total requests</div>
                    </div>
                </div>
            </div>
        </a>
    </div>

</div>

{{-- ═══════════════════════════════════════════════════════════════════
     ROW 2 — UCM AGGREGATE EXTENSION & TRUNK STATS
══════════════════════════════════════════════════════════════════════ --}}
@if(count($ucmStats) > 0)
<div class="row g-3 mb-4">

    {{-- Total Extensions --}}
    <div class="col-6 col-md-3">
        <a href="{{ route('admin.extensions.index') }}" class="text-decoration-none">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body text-center py-3">
                    <i class="bi bi-telephone-fill text-primary fs-3 mb-2 d-block"></i>
                    <div class="fs-2 fw-bold text-dark">{{ number_format($totalExt) }}</div>
                    <div class="small text-muted">Total Extensions</div>
                    <div class="mt-2 d-flex justify-content-center gap-1 flex-wrap">
                        <span class="badge bg-success">{{ $totalIdle }} Idle</span>
                        <span class="badge bg-warning text-dark">{{ $totalInUse }} In Use</span>
                        <span class="badge bg-danger">{{ $totalUnavail }} Off</span>
                    </div>
                </div>
            </div>
        </a>
    </div>

    {{-- Trunks (reachable / total) --}}
    <div class="col-6 col-md-3">
        <a href="{{ route('admin.trunks.index') }}" class="text-decoration-none">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body text-center py-3">
                    <i class="bi bi-hdd-network-fill text-secondary fs-3 mb-2 d-block"></i>
                    <div class="fs-2 fw-bold text-dark">
                        {{ number_format($totalReachable) }}<span class="fs-5 text-muted">/{{ number_format($totalTrunks) }}</span>
                    </div>
                    <div class="small text-muted">Trunks Reachable</div>
                    @if($totalUnreachable > 0)
                        <div class="mt-1">
                            <span class="badge bg-danger-subtle text-danger border border-danger-subtle">{{ $totalUnreachable }} Unreachable</span>
                        </div>
                    @endif
                </div>
            </div>
        </a>
    </div>

    {{-- Registered extensions --}}
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body text-center py-3">
                <i class="bi bi-check2-circle text-success fs-3 mb-2 d-block"></i>
                <div class="fs-2 fw-bold text-dark">{{ number_format($totalIdle + $totalInUse) }}</div>
                <div class="small text-muted">Registered Extensions</div>
                @if($totalExt > 0)
                    <div class="small text-muted mt-1">
                        {{ round(($totalIdle + $totalInUse) / $totalExt * 100) }}% of total
                    </div>
                @endif
            </div>
        </div>
    </div>

    {{-- Unregistered --}}
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body text-center py-3">
                <i class="bi bi-x-circle {{ $totalUnavail > 0 ? 'text-danger' : 'text-muted' }} fs-3 mb-2 d-block"></i>
                <div class="fs-2 fw-bold text-dark">{{ number_format($totalUnavail) }}</div>
                <div class="small text-muted">Unregistered Extensions</div>
                @if($totalExt > 0)
                    <div class="small text-muted mt-1">
                        {{ round($totalUnavail / $totalExt * 100) }}% of total
                    </div>
                @endif
            </div>
        </div>
    </div>

</div>
@endif

{{-- ═══════════════════════════════════════════════════════════════════
     ROW 3 — PER-UCM SERVER CARDS
══════════════════════════════════════════════════════════════════════ --}}
@if(count($ucmStats) > 0)
<h5 class="fw-semibold mb-3"><i class="bi bi-router-fill me-2 text-primary"></i>UCM Servers</h5>
<div class="row g-3 mb-4">
    @foreach($ucmStats as $ucm)
    @php
        $s      = $ucm['stats'];
        $server = $ucm['server'];
        $online = $s['online'] ?? false;
        $mac    = $s['mac'] ?? null;
        $uptime = $s['uptime'] ?? null;
    @endphp
    <div class="col-md-6 col-xl-4">
        <div class="card border-0 shadow-sm h-100" style="border-left:4px solid {{ $online ? '#198754' : '#dc3545' }} !important;">
            <div class="card-header bg-transparent d-flex align-items-center gap-2 py-2">
                <span class="badge {{ $online ? 'bg-success' : 'bg-danger' }} rounded-pill">
                    <i class="bi bi-{{ $online ? 'wifi' : 'wifi-off' }} me-1"></i>{{ $online ? 'Online' : 'Offline' }}
                </span>
                <span class="fw-semibold text-dark">{{ $server->name }}</span>
                @if(!$server->is_active)
                    <span class="badge bg-secondary ms-auto">Disabled</span>
                @elseif($online)
                    <span class="badge bg-light text-dark ms-auto border">{{ $s['model'] ?? '' }}</span>
                @endif
            </div>
            <div class="card-body py-2">
                @if($online)
                    <div class="row g-2 text-center mb-2">
                        <div class="col-4">
                            <div class="p-2 rounded bg-light">
                                <div class="fs-4 fw-bold text-primary">{{ $s['extensions']['total'] }}</div>
                                <div class="text-muted" style="font-size:.72rem;">Extensions</div>
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="p-2 rounded bg-light">
                                <div class="fs-4 fw-bold text-secondary">{{ $s['trunk_counts']['total'] ?? 0 }}</div>
                                <div class="text-muted" style="font-size:.72rem;">Trunks</div>
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="p-2 rounded bg-light">
                                <div class="fs-4 fw-bold {{ ($s['extensions']['unavailable'] ?? 0) > 0 ? 'text-danger' : 'text-success' }}">
                                    {{ ($s['extensions']['idle'] ?? 0) + ($s['extensions']['inuse'] ?? 0) }}
                                </div>
                                <div class="text-muted" style="font-size:.72rem;">Registered</div>
                            </div>
                        </div>
                    </div>

                    {{-- Extension status progress bar --}}
                    @if($s['extensions']['total'] > 0)
                    @php
                        $pIdle    = round($s['extensions']['idle']        / $s['extensions']['total'] * 100);
                        $pInUse   = round($s['extensions']['inuse']       / $s['extensions']['total'] * 100);
                        $pUnavail = round($s['extensions']['unavailable'] / $s['extensions']['total'] * 100);
                    @endphp
                    <div class="progress mb-2" style="height:6px;"
                         title="Idle: {{ $s['extensions']['idle'] }} | In Use: {{ $s['extensions']['inuse'] }} | Unavailable: {{ $s['extensions']['unavailable'] }}">
                        <div class="progress-bar bg-success" style="width:{{ $pIdle }}%"></div>
                        <div class="progress-bar bg-warning" style="width:{{ $pInUse }}%"></div>
                        <div class="progress-bar bg-danger"  style="width:{{ $pUnavail }}%"></div>
                    </div>
                    @endif

                    {{-- Details --}}
                    <table class="table table-sm table-borderless mb-1" style="font-size:.8rem;">
                        <tr>
                            <td class="text-muted ps-0" style="width:38%;">Serial No.</td>
                            <td class="fw-semibold font-monospace">{{ $s['serial'] ?? '-' }}</td>
                        </tr>
                        @if($mac)
                        <tr>
                            <td class="text-muted ps-0">MAC</td>
                            <td class="fw-semibold font-monospace">{{ $mac }}</td>
                        </tr>
                        @endif
                        <tr>
                            <td class="text-muted ps-0">Firmware</td>
                            <td class="fw-semibold">{{ $s['firmware'] ?? '-' }}</td>
                        </tr>
                        @if($uptime)
                        <tr>
                            <td class="text-muted ps-0">Uptime</td>
                            <td class="fw-semibold font-monospace">{{ $uptime }}</td>
                        </tr>
                        @endif
                        @if($server->cloud_domain)
                        <tr>
                            <td class="text-muted ps-0">Wave Domain</td>
                            <td class="fw-semibold font-monospace small">{{ $server->cloud_domain }}</td>
                        </tr>
                        @endif
                    </table>

                    <div class="d-flex gap-1 flex-wrap">
                        <span class="badge bg-success-subtle text-success border border-success-subtle">{{ $s['extensions']['idle'] }} Idle</span>
                        <span class="badge bg-warning-subtle text-warning border border-warning-subtle">{{ $s['extensions']['inuse'] }} In Use</span>
                        <span class="badge bg-danger-subtle text-danger border border-danger-subtle">{{ $s['extensions']['unavailable'] }} Unavailable</span>
                    </div>
                @else
                    <div class="text-danger small py-2">
                        <i class="bi bi-exclamation-triangle-fill me-1"></i>{{ $s['error'] ?? 'Cannot connect to UCM' }}
                    </div>
                    <div class="text-muted small font-monospace">{{ $server->url }}</div>
                @endif
            </div>
            <div class="card-footer bg-transparent d-flex gap-2 py-2">
                <a href="{{ route('admin.extensions.index', ['ucm_id' => $server->id]) }}" class="btn btn-sm btn-outline-primary flex-fill">
                    <i class="bi bi-telephone me-1"></i>Extensions
                </a>
                <a href="{{ route('admin.trunks.index', ['ucm_id' => $server->id]) }}" class="btn btn-sm btn-outline-secondary flex-fill">
                    <i class="bi bi-hdd-network me-1"></i>Trunks
                </a>
            </div>
        </div>
    </div>
    @endforeach
</div>
@else
<div class="alert alert-info border-0 shadow-sm">
    <i class="bi bi-info-circle me-2"></i>No UCM servers configured yet.
    <a href="{{ route('admin.settings.index') }}" class="alert-link">Add one in Settings</a>.
</div>
@endif

{{-- ═══════════════════════════════════════════════════════════════════
     QUICK ACTIONS
══════════════════════════════════════════════════════════════════════ --}}
<h5 class="fw-semibold mb-3 mt-2"><i class="bi bi-lightning-fill me-2 text-warning"></i>Quick Actions</h5>
<div class="row g-3">
    <div class="col-6 col-md-3">
        <a href="{{ route('admin.contacts.create') }}" class="btn btn-outline-primary w-100 py-3 d-flex flex-column align-items-center gap-1">
            <i class="bi bi-person-plus-fill fs-4"></i>
            <span class="small">New Contact</span>
        </a>
    </div>
    <div class="col-6 col-md-3">
        <a href="{{ route('admin.extensions.index') }}" class="btn btn-outline-secondary w-100 py-3 d-flex flex-column align-items-center gap-1">
            <i class="bi bi-telephone-plus-fill fs-4"></i>
            <span class="small">Extensions</span>
        </a>
    </div>
    <div class="col-6 col-md-3">
        <a href="{{ route('admin.activity-logs') }}" class="btn btn-outline-dark w-100 py-3 d-flex flex-column align-items-center gap-1">
            <i class="bi bi-shield-check fs-4"></i>
            <span class="small">Audit Log</span>
        </a>
    </div>
    <div class="col-6 col-md-3">
        <a href="{{ route('phonebook.xml') }}" target="_blank" class="btn btn-outline-success w-100 py-3 d-flex flex-column align-items-center gap-1">
            <i class="bi bi-filetype-xml fs-4"></i>
            <span class="small">Download XML</span>
        </a>
    </div>
</div>

{{-- ═══════════════════════════════════════════════════════════════════
     QUICK ADMIN LINKS WIDGET
══════════════════════════════════════════════════════════════════════ --}}
@can('view-admin-links')
@if($quickAdminLinks->count() > 0)
<h5 class="fw-semibold mb-3 mt-4"><i class="bi bi-grid-3x3-gap-fill me-2 text-primary"></i>Quick Admin Links</h5>
<div class="row g-3 mb-3">
    @foreach($quickAdminLinks as $alink)
    <div class="col-6 col-md-3 col-lg-2">
        <a href="{{ route('admin.admin-links.go', $alink) }}" target="_blank" rel="noopener noreferrer"
           class="card border-0 shadow-sm h-100 text-decoration-none">
            <div class="card-body text-center py-3 px-2">
                @if($alink->icon)
                    <i class="bi bi-{{ $alink->icon }} fs-3 text-primary d-block mb-1"></i>
                @else
                    <i class="bi bi-box-arrow-up-right fs-3 text-primary d-block mb-1"></i>
                @endif
                <div class="small fw-semibold text-dark">{{ $alink->name }}</div>
            </div>
        </a>
    </div>
    @endforeach
    <div class="col-6 col-md-3 col-lg-2">
        <a href="{{ route('admin.admin-links.index') }}" class="card border-0 shadow-sm h-100 text-decoration-none bg-light">
            <div class="card-body text-center py-3 px-2 d-flex flex-column align-items-center justify-content-center">
                <i class="bi bi-arrow-right-circle fs-3 text-muted d-block mb-1"></i>
                <div class="small fw-semibold text-muted">View All</div>
            </div>
        </a>
    </div>
</div>
@endif
@endcan

{{-- ═══════════════════════════════════════════════════════════════════
     TONER RISK WIDGET (Phase 7)
══════════════════════════════════════════════════════════════════════ --}}
@can('view-printers')
@php
    $tonerRiskWidget = null;
    try {
        if (\Illuminate\Support\Facades\Schema::hasTable('printer_supplies')) {
            $tonerRiskWidget = \App\Models\PrinterSupply::where('supply_type', 'toner')
                ->where('estimated_days_remaining', '<=', 14)
                ->whereNotNull('estimated_days_remaining')
                ->with('printer')
                ->orderBy('estimated_days_remaining')
                ->limit(10)
                ->get();
        }
    } catch (\Throwable $e) {
        $tonerRiskWidget = null;
    }
@endphp
@if($tonerRiskWidget && $tonerRiskWidget->count() > 0)
<div class="card border-warning mb-4 mt-4">
    <div class="card-header bg-warning text-dark d-flex align-items-center gap-2">
        <i class="bi bi-exclamation-triangle-fill"></i>
        <strong>Toner Risk</strong>
        <span class="ms-1">— {{ $tonerRiskWidget->count() }} printer(s) running low</span>
    </div>
    <div class="card-body p-0">
        <table class="table table-sm mb-0">
            <thead class="table-light">
                <tr>
                    <th class="ps-3">Printer</th>
                    <th>Supply</th>
                    <th>Level</th>
                    <th class="pe-3">Est. Days</th>
                </tr>
            </thead>
            <tbody>
                @foreach($tonerRiskWidget as $supply)
                <tr class="{{ $supply->estimated_days_remaining <= 3 ? 'table-danger' : 'table-warning' }}">
                    <td class="ps-3">
                        @if($supply->printer)
                        <a href="{{ route('admin.printers.show', $supply->printer) }}" class="text-decoration-none fw-semibold">
                            {{ $supply->printer->printer_name }}
                        </a>
                        @else
                        <span class="text-muted">Unknown Printer</span>
                        @endif
                    </td>
                    <td>{{ $supply->supply_descr }}</td>
                    <td>
                        @if($supply->supply_percent !== null)
                        <div class="d-flex align-items-center gap-2">
                            <div class="progress flex-grow-1" style="height:14px;min-width:60px">
                                <div class="progress-bar bg-{{ $supply->estimated_days_remaining <= 3 ? 'danger' : 'warning' }}"
                                     style="width:{{ $supply->supply_percent }}%">
                                    <span style="font-size:0.65rem">{{ $supply->supply_percent }}%</span>
                                </div>
                            </div>
                        </div>
                        @else
                        <span class="text-muted">—</span>
                        @endif
                    </td>
                    <td class="pe-3">
                        <strong class="text-{{ $supply->estimated_days_remaining <= 3 ? 'danger' : 'dark' }}">
                            {{ $supply->estimated_days_remaining }}d
                        </strong>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endif
@endcan

{{-- ═══════════════════════════════════════════════════════════════════
     Network Monitoring Overview Charts
     3-column row:
       1. Device Status Donut
       2. Alert Frequency Bar (last 7 days)
       3. Host Type Breakdown Donut
═══════════════════════════════════════════════════════════════════════ --}}
@can('manage-network-settings')
@php
    use App\Models\MonitoredHost;
    use App\Models\HostCheck;
    use Illuminate\Support\Facades\DB;

    // ── Device status donut ──────────────────────────────────────────────
    $hostStatuses = MonitoredHost::select('status', DB::raw('count(*) as total'))
        ->groupBy('status')
        ->pluck('total', 'status')
        ->toArray();

    $statusLabels = array_keys($hostStatuses);
    $statusCounts = array_values($hostStatuses);
    $statusColors = array_map(fn($s) => match($s) {
        'up'       => '#198754',
        'down'     => '#dc3545',
        'degraded' => '#ffc107',
        default    => '#6c757d',
    }, $statusLabels);

    // ── Alert frequency — count of 'down' host-checks per day last 7 days ──
    $alertDays = HostCheck::select(
            DB::raw("DATE(checked_at) as day"),
            DB::raw("SUM(CASE WHEN status='down' THEN 1 ELSE 0 END) as downs")
        )
        ->where('checked_at', '>=', now()->subDays(6)->startOfDay())
        ->groupBy('day')
        ->orderBy('day')
        ->get()
        ->keyBy('day');

    $alertLabels = [];
    $alertValues = [];
    for ($i = 6; $i >= 0; $i--) {
        $day = now()->subDays($i)->format('Y-m-d');
        $alertLabels[] = now()->subDays($i)->format('D d');
        $alertValues[] = (int) ($alertDays[$day]->downs ?? 0);
    }

    // ── Host type breakdown ───────────────────────────────────────────────
    $typeBreakdown = MonitoredHost::select('type', DB::raw('count(*) as total'))
        ->groupBy('type')
        ->pluck('total', 'type')
        ->toArray();
    $typeLabels = array_map('ucfirst', array_keys($typeBreakdown));
    $typeCounts = array_values($typeBreakdown);

    $totalHosts    = array_sum($statusCounts);
    $upHosts       = $hostStatuses['up'] ?? 0;
    $downHosts     = $hostStatuses['down'] ?? 0;
@endphp

@if($totalHosts > 0)
<div class="mt-5 mb-2">
    <h5 class="fw-bold mb-1"><i class="bi bi-hdd-network me-2 text-primary"></i>Network Device Overview</h5>
    <p class="text-muted small mb-4">Real-time summary from SNMP-monitored hosts</p>
</div>

{{-- Summary KPIs --}}
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="card border-0 shadow-sm text-center py-3">
            <div class="h3 fw-bold text-primary mb-0">{{ $totalHosts }}</div>
            <div class="small text-muted">Total Devices</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card border-0 shadow-sm text-center py-3">
            <div class="h3 fw-bold text-success mb-0">{{ $upHosts }}</div>
            <div class="small text-muted">Online</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card border-0 shadow-sm text-center py-3">
            <div class="h3 fw-bold text-danger mb-0">{{ $downHosts }}</div>
            <div class="small text-muted">Offline / Down</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card border-0 shadow-sm text-center py-3">
            <div class="h3 fw-bold mb-0 {{ $totalHosts > 0 ? 'text-dark' : 'text-muted' }}">
                {{ $totalHosts > 0 ? round($upHosts / $totalHosts * 100) : 0 }}<span class="fs-6 text-muted">%</span>
            </div>
            <div class="small text-muted">Uptime Rate</div>
        </div>
    </div>
</div>

<div class="row g-4 mb-5">
    {{-- 1. Device Status Donut --}}
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white border-0 py-3">
                <h6 class="mb-0 fw-bold"><i class="bi bi-pie-chart-fill me-2 text-primary"></i>Device Status</h6>
            </div>
            <div class="card-body d-flex flex-column align-items-center justify-content-center">
                <div id="dash-status-donut" style="min-height:220px;width:100%;"></div>
            </div>
        </div>
    </div>

    {{-- 2. Alert Frequency Bar Chart --}}
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white border-0 py-3">
                <h6 class="mb-0 fw-bold"><i class="bi bi-bar-chart-fill me-2 text-danger"></i>Down Events — Last 7 Days</h6>
            </div>
            <div class="card-body">
                <div id="dash-alert-bar" style="min-height:220px;"></div>
            </div>
        </div>
    </div>

    {{-- 3. Host Type Donut --}}
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white border-0 py-3">
                <h6 class="mb-0 fw-bold"><i class="bi bi-diagram-3-fill me-2 text-success"></i>Device Types</h6>
            </div>
            <div class="card-body d-flex flex-column align-items-center justify-content-center">
                <div id="dash-type-donut" style="min-height:220px;width:100%;"></div>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/apexcharts@3.54.0/dist/apexcharts.min.js"></script>
<script>
(function () {
    // 1. Status Donut
    new ApexCharts(document.getElementById('dash-status-donut'), {
        chart:  { type: 'donut', height: 220, animations: { speed: 400 } },
        series: @json($statusCounts),
        labels: @json(array_map('ucfirst', $statusLabels)),
        colors: @json($statusColors),
        legend: { position: 'bottom', fontSize: '12px' },
        dataLabels: { enabled: true, style: { fontSize: '11px' } },
        plotOptions: { pie: { donut: { size: '60%', labels: {
            show: true,
            total: { show: true, label: 'Total', color: '#6c757d', fontSize: '12px',
                     formatter: () => '{{ $totalHosts }}' }
        }}}},
        tooltip: { theme: 'light' },
    }).render();

    // 2. Alert Frequency Bar
    new ApexCharts(document.getElementById('dash-alert-bar'), {
        chart:  { type: 'bar', height: 220, toolbar: { show: false }, animations: { speed: 400 } },
        series: [{ name: 'Down Events', data: @json($alertValues) }],
        xaxis:  { categories: @json($alertLabels), labels: { style: { colors: '#6c757d', fontSize: '11px' } } },
        yaxis:  { min: 0, tickAmount: 4, labels: { style: { colors: '#6c757d', fontSize: '11px' }, formatter: v => Math.round(v) } },
        colors: ['#dc3545'],
        fill:   { type: 'solid', opacity: .85 },
        dataLabels: { enabled: false },
        plotOptions: { bar: { borderRadius: 4, columnWidth: '55%' } },
        tooltip: { theme: 'light' },
        grid:   { borderColor: '#e9ecef', strokeDashArray: 4 },
    }).render();

    // 3. Device Types Donut
    new ApexCharts(document.getElementById('dash-type-donut'), {
        chart:  { type: 'donut', height: 220, animations: { speed: 400 } },
        series: @json($typeCounts),
        labels: @json($typeLabels),
        legend: { position: 'bottom', fontSize: '12px' },
        dataLabels: { enabled: true, style: { fontSize: '11px' } },
        plotOptions: { pie: { donut: { size: '60%', labels: {
            show: true,
            total: { show: true, label: 'Types', color: '#6c757d', fontSize: '12px',
                     formatter: () => '{{ count($typeLabels) }}' }
        }}}},
        tooltip: { theme: 'light' },
    }).render();
})();
</script>
@endpush
@endif
@endcan

@endsection
