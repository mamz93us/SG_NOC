@extends('layouts.admin')
@section('title', 'Voice Quality Report #' . $report->id)

@section('content')
<div class="mb-3">
    <a href="{{ route('admin.voice-quality.index') }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Back to Reports</a>
</div>

@php
    $mos = $report->mos_lq;
    $label = $report->quality_label ?? ($mos ? \App\Models\VoiceQualityReport::mosLabel($mos) : null);
    $colorMap = ['excellent'=>'success','good'=>'info','fair'=>'warning','poor'=>'danger','bad'=>'dark'];
    $color = $colorMap[$label] ?? 'secondary';
@endphp

{{-- Quality verdict banner --}}
<div class="alert alert-{{ $color }} mb-4 d-flex align-items-center gap-3">
    <i class="bi bi-{{ $color === 'success' || $color === 'info' ? 'check-circle-fill' : ($color === 'warning' ? 'exclamation-triangle-fill' : 'x-circle-fill') }} fs-4"></i>
    <div>
        <strong>{{ ucfirst($label ?? 'Unknown') }} Quality</strong>
        @if($mos)
        — MOS-LQ: <strong>{{ number_format($mos, 3) }}</strong>
        @endif
        <div class="small">Extension {{ $report->extension }} &rarr; {{ $report->remote_extension ?: 'Unknown' }}
            @if($report->branch) &bull; {{ $report->branch }} @endif
        </div>
    </div>
</div>

@if($mos)
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-transparent fw-semibold">MOS-LQ Score</div>
    <div class="card-body">
        <div class="progress mb-1" style="height:20px;">
            <div class="progress-bar bg-{{ $color }}" style="width:{{ ($mos / 5.0) * 100 }}%">
                {{ number_format($mos, 2) }} / 5.0
            </div>
        </div>
        <div class="d-flex justify-content-between small text-muted mt-1">
            <span>0 — Bad</span><span>3.0 — Poor</span><span>3.6 — Fair</span><span>4.0 — Good</span><span>4.3 — Excellent</span><span>5.0</span>
        </div>
    </div>
</div>
@endif

<div class="row g-4">
    <div class="col-md-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-transparent fw-semibold"><i class="bi bi-telephone me-1"></i>Call Details</div>
            <div class="card-body">
                <table class="table table-sm mb-0">
                    <tr><th class="text-muted fw-normal w-50">Extension</th><td class="fw-semibold">{{ $report->extension ?: '—' }}</td></tr>
                    <tr><th class="text-muted fw-normal">Remote Extension</th><td>{{ $report->remote_extension ?: '—' }}</td></tr>
                    <tr><th class="text-muted fw-normal">Remote IP</th><td class="font-monospace">{{ $report->remote_ip ?: '—' }}</td></tr>
                    <tr><th class="text-muted fw-normal">Branch</th><td>{{ $report->branch ?: '—' }}</td></tr>
                    <tr><th class="text-muted fw-normal">Codec</th><td class="font-monospace">{{ $report->codec ?: '—' }}</td></tr>
                    <tr><th class="text-muted fw-normal">Call Start</th><td>{{ $report->call_start?->format('d M Y H:i:s') ?: '—' }}</td></tr>
                    <tr><th class="text-muted fw-normal">Call End</th><td>{{ $report->call_end?->format('d M Y H:i:s') ?: '—' }}</td></tr>
                    <tr><th class="text-muted fw-normal">Duration</th><td>{{ $report->call_duration_seconds !== null ? gmdate('H:i:s', $report->call_duration_seconds) : '—' }}</td></tr>
                    <tr><th class="text-muted fw-normal">Recorded At</th><td>{{ $report->created_at->format('d M Y H:i:s') }}</td></tr>
                </table>
            </div>
        </div>
    </div>

    <div class="col-md-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-transparent fw-semibold"><i class="bi bi-activity me-1"></i>Quality Metrics</div>
            <div class="card-body">
                <table class="table table-sm mb-0">
                    <tr>
                        <th class="text-muted fw-normal w-50">MOS-LQ</th>
                        <td>
                            @if($report->mos_lq !== null)
                            <span class="badge bg-{{ $color }}">{{ number_format($report->mos_lq, 3) }}</span>
                            @else <span class="text-muted">—</span> @endif
                        </td>
                    </tr>
                    <tr>
                        <th class="text-muted fw-normal">MOS-CQ</th>
                        <td>{{ $report->mos_cq !== null ? number_format($report->mos_cq, 3) : '—' }}</td>
                    </tr>
                    <tr>
                        <th class="text-muted fw-normal">R-Factor</th>
                        <td>{{ $report->r_factor !== null ? number_format($report->r_factor, 1) : '—' }}</td>
                    </tr>
                    <tr>
                        <th class="text-muted fw-normal">Jitter <small class="text-muted">(IAJ)</small></th>
                        <td>{{ $report->jitter_avg !== null ? number_format($report->jitter_avg, 2).' ms' : '—' }}</td>
                    </tr>
                    <tr>
                        <th class="text-muted fw-normal">JitterBuffer Max <small class="text-muted">(JBM)</small></th>
                        <td>{{ $report->jitter_max !== null ? number_format($report->jitter_max, 2).' ms' : '—' }}</td>
                    </tr>
                    <tr>
                        <th class="text-muted fw-normal">Packet Loss Rate <small class="text-muted">(NLR)</small></th>
                        <td class="{{ $report->packet_loss !== null && $report->packet_loss > 5 ? 'text-danger fw-semibold' : '' }}">
                            {{ $report->packet_loss !== null ? number_format($report->packet_loss, 2).'%' : '—' }}
                        </td>
                    </tr>
                    <tr>
                        <th class="text-muted fw-normal">Packets Lost <small class="text-muted">(count)</small></th>
                        <td class="{{ $report->packets_lost > 0 ? 'text-warning fw-semibold' : '' }}">
                            {{ $report->packets_lost !== null ? $report->packets_lost : '—' }}
                        </td>
                    </tr>
                    <tr>
                        <th class="text-muted fw-normal">Burst Loss</th>
                        <td>{{ $report->burst_loss !== null ? number_format($report->burst_loss, 3).'%' : '—' }}</td>
                    </tr>
                    <tr>
                        <th class="text-muted fw-normal">Round Trip Delay <small class="text-muted">(RTD)</small></th>
                        <td class="{{ $report->rtt !== null && $report->rtt > 300 ? 'text-danger fw-semibold' : '' }}">
                            {{ $report->rtt !== null ? $report->rtt.' ms' : '—' }}
                        </td>
                    </tr>
                    <tr>
                        <th class="text-muted fw-normal">One-Way Delay <small class="text-muted">(SOWD)</small></th>
                        <td class="{{ $report->sowd !== null && $report->sowd > 150 ? 'text-warning fw-semibold' : '' }}">
                            {{ $report->sowd !== null ? $report->sowd.' ms' : '—' }}
                        </td>
                    </tr>
                    <tr>
                        <th class="text-muted fw-normal">End System Delay <small class="text-muted">(ESD)</small></th>
                        <td>{{ $report->esd !== null ? $report->esd.' ms' : '—' }}</td>
                    </tr>
                    <tr>
                        <th class="text-muted fw-normal">Quality Label</th>
                        <td>
                            @if($label)
                            <span class="badge bg-{{ $color }}">{{ ucfirst($label) }}</span>
                            @else <span class="text-muted">—</span> @endif
                        </td>
                    </tr>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection
