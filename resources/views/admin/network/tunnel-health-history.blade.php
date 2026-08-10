@extends('layouts.admin')

@section('content')
@php
    $fmtDuration = function (int $minutes) {
        if ($minutes <= 0) return '0m';
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;
        return $h > 0 ? ($m > 0 ? "{$h}h {$m}m" : "{$h}h") : "{$m}m";
    };
    $uptimeClass = function (?float $pct) {
        if ($pct === null) return 'text-muted';
        if ($pct >= 99.5) return 'text-success';
        if ($pct >= 95)   return 'text-warning';
        return 'text-danger';
    };
@endphp

<div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-2">
    <div>
        <h2 class="h3 mb-1">Tunnel Watchdog — 7 Day History</h2>
        <p class="text-muted small mb-0" style="max-width: 62ch;">
            Every hour of the last week for each branch tunnel. An hour is only green if
            <em>every</em> check in it was fully up — one dip to <strong>Degraded</strong> or
            <strong>Down</strong> colours the whole hour, so a brief flap is still visible days later.
        </p>
    </div>
    <div class="d-flex gap-2 align-items-center">
        <span class="text-muted small">Since {{ $since->format('D d M') }}</span>
        <a href="{{ route('admin.network.tunnel-health.index') }}" class="btn btn-outline-secondary">
            <i class="bi bi-activity me-1"></i> Live board
        </a>
    </div>
</div>

{{-- Roll-up counters --}}
<div class="row g-2 mb-3">
    <div class="col-6 col-lg-3">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body py-3">
                <div class="text-muted small text-uppercase" style="letter-spacing:.04em;">Avg uptime</div>
                <div class="h3 mb-0 {{ $uptimeClass($summary['avg_uptime']) }}">
                    {{ $summary['avg_uptime'] !== null ? $summary['avg_uptime'].'%' : '—' }}
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body py-3">
                <div class="text-muted small text-uppercase" style="letter-spacing:.04em;">Clean all week</div>
                <div class="h3 mb-0 text-success">{{ $summary['perfect'] }}<span class="fs-6 text-muted">/{{ $summary['tracked'] }}</span></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body py-3">
                <div class="text-muted small text-uppercase" style="letter-spacing:.04em;">Impacted hours</div>
                <div class="h3 mb-0 {{ $summary['impacted_hours'] > 0 ? 'text-warning' : '' }}">{{ $summary['impacted_hours'] }}</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body py-3">
                <div class="text-muted small text-uppercase" style="letter-spacing:.04em;">Total downtime</div>
                <div class="h3 mb-0 {{ $summary['down_minutes'] > 0 ? 'text-danger' : '' }}">{{ $fmtDuration($summary['down_minutes']) }}</div>
            </div>
        </div>
    </div>
</div>

{{-- Tunnel cards --}}
@forelse($rows as $r)
    <div class="card shadow-sm mb-3">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <span class="badge {{ $r['state_badge'] }}">{{ $r['state_label'] }}</span>
                    <span class="fw-semibold">{{ $r['name'] }}</span>
                    @if($r['branch'])
                        <span class="text-muted small">{{ $r['branch'] }}</span>
                    @endif
                    <code class="small text-muted">{{ $r['firewall_ip'] }}</code>
                    @unless($r['is_active'])
                        <span class="badge bg-secondary">Paused</span>
                    @endunless
                </div>
                <div class="d-flex align-items-center gap-3 small text-muted flex-wrap">
                    @if($r['has_data'])
                        <span>
                            <span class="fw-semibold {{ $uptimeClass($r['uptime']) }}">{{ $r['uptime'] }}%</span> up
                        </span>
                        @if($r['down_minutes'] > 0)
                            <span class="text-danger">{{ $fmtDuration($r['down_minutes']) }} down</span>
                        @endif
                        @if($r['degraded_minutes'] > 0)
                            <span class="text-warning">{{ $fmtDuration($r['degraded_minutes']) }} degraded</span>
                        @endif
                        @if($r['worst_day'] && $r['worst_day']['uptime'] !== null && $r['worst_day']['uptime'] < 100)
                            <span>worst {{ $r['worst_day']['label'] }} ({{ $r['worst_day']['uptime'] }}%)</span>
                        @endif
                    @else
                        <span>No checks recorded this week</span>
                    @endif
                </div>
            </div>

            {{-- 7 x 24 heat grid: one row per day, one cell per hour --}}
            <div class="twh-grid">
                <div class="twh-head">
                    <span class="twh-daylabel"></span>
                    <div class="twh-hours">
                        @for($h = 0; $h < 24; $h++)
                            <span class="twh-hourlabel">{{ $h % 6 === 0 ? sprintf('%02d', $h) : '' }}</span>
                        @endfor
                    </div>
                    <span class="twh-daypct"></span>
                </div>

                @foreach($r['grid'] as $day)
                    <div class="twh-row">
                        <span class="twh-daylabel {{ $day['is_today'] ? 'fw-semibold' : '' }}">
                            {{ $day['label'] }}@if($day['is_today'])<span class="text-primary"> •</span>@endif
                        </span>
                        <div class="twh-hours">
                            @foreach($day['cells'] as $cell)
                                <span class="twh-cell {{ $cell['state'] }}" title="{{ $cell['title'] }}"></span>
                            @endforeach
                        </div>
                        <span class="twh-daypct {{ $uptimeClass($day['uptime']) }}">
                            {{ $day['uptime'] !== null ? $day['uptime'].'%' : '—' }}
                        </span>
                    </div>
                @endforeach
            </div>

            @if($r['probe_labels'])
                <div class="text-muted small mt-2">
                    <i class="bi bi-diagram-3 me-1"></i>Carries {{ count($r['probe_labels']) }} subnet{{ count($r['probe_labels']) === 1 ? '' : 's' }}:
                    {{ implode(' · ', $r['probe_labels']) }}
                </div>
            @endif
        </div>
    </div>
@empty
    <div class="card shadow-sm">
        <div class="card-body text-center text-muted py-5">
            No tunnels configured yet.
            <a href="{{ route('admin.network.tunnel-health.index') }}">Add one on the live board</a>.
        </div>
    </div>
@endforelse

{{-- Legend --}}
<div class="d-flex align-items-center gap-3 small text-muted flex-wrap mb-4">
    <span class="d-inline-flex align-items-center gap-1"><span class="twh-cell up twh-key"></span> Fully up</span>
    <span class="d-inline-flex align-items-center gap-1"><span class="twh-cell degraded twh-key"></span> Degraded — a carried subnet was dark</span>
    <span class="d-inline-flex align-items-center gap-1"><span class="twh-cell down twh-key"></span> Down</span>
    <span class="d-inline-flex align-items-center gap-1"><span class="twh-cell none twh-key"></span> No checks</span>
    <span class="ms-auto">History is kept for 7 days, then pruned. Downtime is derived from the one-minute check cadence.</span>
</div>

<style>
.twh-grid { display:flex; flex-direction:column; gap:3px; }
.twh-row, .twh-head { display:flex; align-items:center; gap:8px; }
.twh-daylabel { flex:0 0 84px; font-size:.75rem; color:var(--bs-secondary-color, #6c757d); white-space:nowrap; }
.twh-daypct   { flex:0 0 46px; font-size:.72rem; text-align:right; }
.twh-hours    { display:flex; gap:2px; flex:1 1 auto; }
.twh-cell     { flex:1 1 0; min-width:4px; height:16px; border-radius:2px; background:var(--bs-secondary-bg, #dee2e6); }
.twh-cell.up        { background:var(--bs-success); }
.twh-cell.degraded  { background:var(--bs-warning); }
.twh-cell.down      { background:var(--bs-danger); }
.twh-cell.none      { background:var(--bs-secondary-bg, #e9ecef); opacity:.5; }
.twh-cell.twh-key   { flex:0 0 14px; width:14px; height:14px; min-width:14px; }
.twh-hourlabel { flex:1 1 0; min-width:4px; font-size:.6rem; color:var(--bs-secondary-color, #adb5bd); text-align:left; }
@media (max-width: 768px) {
    .twh-daylabel { flex-basis:62px; font-size:.68rem; }
    .twh-cell { height:13px; }
}
</style>
@endsection
