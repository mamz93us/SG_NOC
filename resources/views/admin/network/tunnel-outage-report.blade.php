@extends('layouts.admin')

@section('content')
@php
    // Fully qualified rather than a `use` import: a Blade @php block is emitted
    // inline into the compiled view, and view:cache will happily cache a file
    // that only explodes at render time.
    $dur = fn (int $s) => \App\Models\TunnelOutage::humanDuration($s);

    $availClass = function (?float $pct) {
        if ($pct === null) return 'text-muted';
        if ($pct >= 99.9) return 'text-success';
        if ($pct >= 99)   return 'text-warning';
        return 'text-danger';
    };

    $fmtPct = fn (?float $p) => $p === null ? '—' : rtrim(rtrim(number_format($p, 3), '0'), '.').'%';

    // Quick ranges. Kept as plain links so the report URL is always shareable
    // and printable — the whole page state lives in the query string.
    $range = fn (string $f, string $t) => route('admin.network.tunnel-health.report', array_merge(
        request()->only(['tunnel_id', 'state', 'min_minutes']),
        ['from' => $f, 'to' => $t],
    ));

    $thisMonth = now()->startOfMonth();
    $lastMonth = now()->subMonthNoOverflow()->startOfMonth();
@endphp

<div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
    <div>
        <h2 class="h3 mb-1">Tunnel Outage Report</h2>
        <p class="text-muted small mb-0" style="max-width: 70ch;">
            Every stretch each branch tunnel spent <strong>Down</strong> or <strong>Degraded</strong>, with exact
            start and end times — the form an ISP ticket or an SLA credit claim needs.
            <strong>Down</strong> means the branch firewall stopped answering at all, which is the circuit.
            <strong>Degraded</strong> means the link was up but a carried subnet was unreachable, which is
            almost always a traffic selector on our side — don't send those to the ISP.
        </p>
    </div>
    <div class="d-flex gap-2 align-items-center flex-wrap d-print-none">
        <a href="{{ route('admin.network.tunnel-health.index') }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-activity me-1"></i> Live board
        </a>
        <a href="{{ route('admin.network.tunnel-health.history') }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-calendar-week me-1"></i> 7-day grid
        </a>
        <a href="{{ route('admin.network.tunnel-health.report.export', request()->query()) }}" class="btn btn-outline-success btn-sm">
            <i class="bi bi-filetype-csv me-1"></i> Export CSV
        </a>
        <button type="button" class="btn btn-outline-primary btn-sm" onclick="window.print()">
            <i class="bi bi-printer me-1"></i> Print
        </button>
    </div>
</div>

{{-- Printed copies need to say what period they cover; the filter bar is hidden. --}}
<p class="d-none d-print-block mb-3">
    <strong>Period:</strong> {{ $filters['from']->format('D d M Y H:i') }} &rarr; {{ $filters['to_effective']->format('D d M Y H:i') }}
    &nbsp;·&nbsp; <strong>Generated:</strong> {{ now()->format('D d M Y H:i') }}
</p>

{{-- ── Filters ───────────────────────────────────────────────── --}}
<div class="card shadow-sm mb-3 d-print-none">
    <div class="card-body py-3">
        <form method="GET" action="{{ route('admin.network.tunnel-health.report') }}" class="row g-2 align-items-end">
            <div class="col-6 col-md-2">
                <label class="form-label small mb-1">From</label>
                <input type="date" name="from" class="form-control form-control-sm" value="{{ $filters['from']->toDateString() }}">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small mb-1">To</label>
                <input type="date" name="to" class="form-control form-control-sm" value="{{ $filters['to']->toDateString() }}">
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label small mb-1">Tunnel</label>
                <select name="tunnel_id" class="form-select form-select-sm">
                    <option value="">All tunnels</option>
                    @foreach($tunnels as $t)
                        <option value="{{ $t->id }}" @selected($filters['tunnel_id'] === $t->id)>
                            {{ $t->name }}@if($t->branch) — {{ $t->branch->name }}@endif
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small mb-1">Type</label>
                <select name="state" class="form-select form-select-sm">
                    <option value="all" @selected($filters['state'] === 'all')>Down + Degraded</option>
                    <option value="down" @selected($filters['state'] === 'down')>Down only</option>
                    <option value="degraded" @selected($filters['state'] === 'degraded')>Degraded only</option>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small mb-1" title="Hides brief flaps from the incident list. They stay in the data.">
                    Ignore under
                </label>
                <div class="input-group input-group-sm">
                    <input type="number" name="min_minutes" min="0" max="1440" class="form-control" value="{{ $filters['min_minutes'] }}">
                    <span class="input-group-text">min</span>
                </div>
            </div>
            <div class="col-12 col-md-1">
                <button class="btn btn-primary btn-sm w-100"><i class="bi bi-funnel"></i></button>
            </div>
        </form>

        <div class="mt-2 d-flex gap-2 flex-wrap align-items-center">
            <span class="text-muted small">Quick:</span>
            <a class="btn btn-outline-secondary btn-sm py-0" href="{{ $range(now()->subDays(6)->toDateString(), now()->toDateString()) }}">Last 7 days</a>
            <a class="btn btn-outline-secondary btn-sm py-0" href="{{ $range(now()->subDays(29)->toDateString(), now()->toDateString()) }}">Last 30 days</a>
            <a class="btn btn-outline-secondary btn-sm py-0" href="{{ $range($thisMonth->toDateString(), now()->toDateString()) }}">{{ $thisMonth->format('F') }}</a>
            <a class="btn btn-outline-secondary btn-sm py-0" href="{{ $range($lastMonth->toDateString(), $lastMonth->copy()->endOfMonth()->toDateString()) }}">{{ $lastMonth->format('F') }}</a>
            <a class="btn btn-outline-secondary btn-sm py-0" href="{{ $range(now()->subDays(89)->toDateString(), now()->toDateString()) }}">Last 90 days</a>
        </div>
    </div>
</div>

{{-- ── Roll-up ───────────────────────────────────────────────── --}}
<div class="row g-2 mb-3">
    <div class="col-6 col-lg-3">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body py-3">
                <div class="text-muted small text-uppercase" style="letter-spacing:.04em;">Total downtime</div>
                <div class="h3 mb-0 {{ $totals['down_seconds'] > 0 ? 'text-danger' : 'text-success' }}">{{ $dur($totals['down_seconds']) }}</div>
                <div class="text-muted small">{{ $totals['down_count'] }} outage{{ $totals['down_count'] === 1 ? '' : 's' }} over {{ $totals['days'] }} day{{ $totals['days'] === 1 ? '' : 's' }}</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body py-3">
                <div class="text-muted small text-uppercase" style="letter-spacing:.04em;">Degraded time</div>
                <div class="h3 mb-0 {{ $totals['degraded_seconds'] > 0 ? 'text-warning' : 'text-success' }}">{{ $dur($totals['degraded_seconds']) }}</div>
                <div class="text-muted small">{{ $totals['degraded_count'] }} incident{{ $totals['degraded_count'] === 1 ? '' : 's' }}</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body py-3">
                <div class="text-muted small text-uppercase" style="letter-spacing:.04em;">Tunnels affected</div>
                <div class="h3 mb-0">{{ $totals['affected_tunnels'] }}<span class="fs-6 text-muted">/{{ count($rows) }}</span></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body py-3">
                <div class="text-muted small text-uppercase" style="letter-spacing:.04em;">Still open</div>
                <div class="h3 mb-0 {{ $totals['ongoing'] > 0 ? 'text-danger' : '' }}">{{ $totals['ongoing'] }}</div>
                <div class="text-muted small">incidents running right now</div>
            </div>
        </div>
    </div>
</div>

{{-- ── Per-tunnel summary ────────────────────────────────────── --}}
<div class="card shadow-sm mb-4">
    <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
        <strong>Per tunnel</strong>
        <span class="text-muted small">
            {{ $filters['from']->format('d M Y') }} &rarr; {{ $filters['to_effective']->format('d M Y H:i') }}
            @if($filters['min_minutes'] > 0 || $filters['state'] !== 'all')
                {{-- The availability figures are computed from what is shown, so a
                     filtered page must not be read as the absolute number. --}}
                <span class="badge bg-warning text-dark" title="Availability here counts only the incidents matching the current filter.">filtered</span>
            @endif
        </span>
    </div>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Tunnel</th>
                    <th>ISP / circuit</th>
                    <th class="text-end">Outages</th>
                    <th class="text-end">Downtime</th>
                    <th class="text-end" title="Share of the period the circuit was passing traffic at all. This is the SLA figure.">Link availability</th>
                    <th class="text-end">Degraded</th>
                    <th class="text-end" title="Downtime and degraded time both removed — what the branch actually experienced.">Service availability</th>
                    <th>Longest single outage</th>
                </tr>
            </thead>
            <tbody>
            @forelse($rows as $r)
                <tr>
                    <td>
                        <div class="fw-semibold">{{ $r['tunnel']->name }}</div>
                        <div class="text-muted small">
                            {{ $r['branch'] ?? 'No branch' }} · {{ $r['tunnel']->firewall_ip }}
                        </div>
                        @if($r['partial_window'])
                            <span class="badge bg-secondary" title="This tunnel was only added on {{ $r['window_start']->format('d M Y') }}, so availability is measured from then.">
                                partial period
                            </span>
                        @endif
                        @if($r['has_gap'])
                            <span class="badge bg-secondary" title="At least one incident spans a stretch where the watchdog itself was not running. Its duration is an upper bound.">
                                data gap
                            </span>
                        @endif
                    </td>
                    <td class="small">
                        @if($r['isp'])
                            <div>{{ $r['isp']->provider ?: '—' }}</div>
                            <div class="text-muted">
                                @if($r['isp']->account_number) acct {{ $r['isp']->account_number }} @endif
                                @if($r['isp']->circuit_id) · circuit {{ $r['isp']->circuit_id }} @endif
                            </div>
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </td>
                    <td class="text-end">{{ $r['down_count'] }}</td>
                    <td class="text-end {{ $r['down_seconds'] > 0 ? 'text-danger fw-semibold' : 'text-muted' }}">
                        {{ $r['down_seconds'] > 0 ? $dur($r['down_seconds']) : '—' }}
                    </td>
                    <td class="text-end fw-semibold {{ $availClass($r['availability']) }}">{{ $fmtPct($r['availability']) }}</td>
                    <td class="text-end {{ $r['degraded_seconds'] > 0 ? 'text-warning' : 'text-muted' }}">
                        {{ $r['degraded_seconds'] > 0 ? $dur($r['degraded_seconds']).' ('.$r['degraded_count'].')' : '—' }}
                    </td>
                    <td class="text-end {{ $availClass($r['service_availability']) }}">{{ $fmtPct($r['service_availability']) }}</td>
                    <td class="small">
                        @if($r['longest'])
                            <span class="badge {{ $r['longest']->stateBadgeClass() }}">{{ $r['longest']->stateLabel() }}</span>
                            {{ $dur($r['longest']->seconds()) }}
                            <span class="text-muted">from {{ $r['longest']->started_at->format('d M H:i') }}</span>
                        @else
                            <span class="text-muted">clean</span>
                        @endif
                        @if($r['ongoing'])
                            <div class="text-danger fw-semibold">
                                <i class="bi bi-exclamation-triangle-fill"></i>
                                {{ $r['ongoing']->stateLabel() }} since {{ $r['ongoing']->started_at->format('d M H:i') }}
                            </div>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="8" class="text-center text-muted py-4">No tunnels configured.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

{{-- ── Incident log ──────────────────────────────────────────── --}}
<div class="card shadow-sm">
    <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
        <strong>Incident log</strong>
        <span class="text-muted small">
            {{ $incidents->count() }} incident{{ $incidents->count() === 1 ? '' : 's' }}
            @if($filters['min_minutes'] > 0)
                <span class="badge bg-secondary">flaps under {{ $filters['min_minutes'] }} min hidden</span>
            @endif
        </span>
    </div>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Tunnel</th>
                    <th>Type</th>
                    <th>From</th>
                    <th>To</th>
                    <th class="text-end">Duration</th>
                    <th>What failed</th>
                    <th>ISP ticket</th>
                    @if($canManage)<th class="d-print-none"></th>@endif
                </tr>
            </thead>
            <tbody>
            @forelse($incidents as $o)
                <tr class="{{ $o->isOngoing() ? 'table-warning' : '' }}">
                    <td class="small">
                        <div class="fw-semibold">{{ $o->tunnel?->name ?? '—' }}</div>
                        <div class="text-muted">{{ $o->tunnel?->branch?->name }}</div>
                    </td>
                    <td><span class="badge {{ $o->stateBadgeClass() }}">{{ $o->stateLabel() }}</span></td>
                    <td class="small text-nowrap">{{ $o->started_at->format('D d M Y H:i:s') }}</td>
                    <td class="small text-nowrap">
                        @if($o->isOngoing())
                            <span class="text-danger fw-semibold">ongoing</span>
                        @else
                            {{ $o->ended_at->format('D d M Y H:i:s') }}
                        @endif
                    </td>
                    <td class="text-end fw-semibold text-nowrap">
                        {{ $dur($o->seconds()) }}
                        @if($o->hasMonitoringGap())
                            <i class="bi bi-exclamation-triangle-fill text-warning"
                               title="The watchdog recorded only {{ $o->checks }} checks across this window — it was not running for part of it, so this duration is an upper bound, not a measurement."></i>
                        @endif
                    </td>
                    <td class="small text-muted" style="max-width: 38ch;">
                        {{ $o->reason }}
                        @if($o->source === 'backfill')
                            <span class="badge bg-light text-dark border" title="Reconstructed from the watchdog check history rather than recorded live.">rebuilt</span>
                        @endif
                    </td>
                    <td class="small">
                        @if($o->ticket_ref)
                            <span class="fw-semibold">{{ $o->ticket_ref }}</span>
                        @else
                            <span class="text-muted">—</span>
                        @endif
                        @if($o->notes)
                            <div class="text-muted">{{ $o->notes }}</div>
                        @endif
                    </td>
                    @if($canManage)
                        <td class="text-end d-print-none">
                            <button type="button" class="btn btn-outline-secondary btn-sm js-edit-incident"
                                    data-id="{{ $o->id }}"
                                    data-label="{{ $o->tunnel?->name }} — {{ $o->stateLabel() }} {{ $o->started_at->format('d M H:i') }}"
                                    data-ticket="{{ $o->ticket_ref }}"
                                    data-notes="{{ $o->notes }}">
                                <i class="bi bi-pencil"></i>
                            </button>
                        </td>
                    @endif
                </tr>
            @empty
                <tr>
                    <td colspan="{{ $canManage ? 8 : 7 }}" class="text-center text-muted py-4">
                        No down or degraded incidents in this period.
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="card-footer bg-white text-muted small">
        Incidents are recorded from the first failing watchdog cycle, one minute apart — the start and end
        times are accurate to the minute. Kept indefinitely, unlike the raw check log, which is pruned at 7 days.
    </div>
</div>

@if($canManage)
<div class="modal fade" id="incidentModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" id="incidentForm">
            @csrf
            @method('PUT')
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Record ISP ticket</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted small" id="incidentLabel"></p>
                    <div class="mb-3">
                        <label class="form-label">ISP ticket reference</label>
                        <input type="text" name="ticket_ref" id="incidentTicket" class="form-control" maxlength="255">
                    </div>
                    <div class="mb-0">
                        <label class="form-label">Notes</label>
                        <textarea name="notes" id="incidentNotes" class="form-control" rows="3" maxlength="2000"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button class="btn btn-primary">Save</button>
                </div>
            </div>
        </form>
    </div>
</div>
@endif

@push('scripts')
<script>
(function () {
    const form = document.getElementById('incidentForm');
    if (!form) return;

    const base  = @json(url('admin/network/tunnel-health/outages'));
    const modal = new bootstrap.Modal(document.getElementById('incidentModal'));

    document.querySelectorAll('.js-edit-incident').forEach(function (btn) {
        btn.addEventListener('click', function () {
            form.action = base + '/' + btn.dataset.id;
            document.getElementById('incidentLabel').textContent = btn.dataset.label || '';
            document.getElementById('incidentTicket').value = btn.dataset.ticket || '';
            document.getElementById('incidentNotes').value = btn.dataset.notes || '';
            modal.show();
        });
    });
})();
</script>
@endpush

@push('head')
<style>
@media print {
    /* The report goes to the ISP as a PDF — drop the chrome, keep the tables. */
    .card { border: 1px solid #dee2e6 !important; box-shadow: none !important; break-inside: avoid; }
    .table { font-size: 11px; }
    a[href]:after { content: none !important; }
}
</style>
@endpush
@endsection
