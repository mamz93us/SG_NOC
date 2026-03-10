@extends('layouts.admin')
@section('content')

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-graph-up me-2 text-primary"></i>SLA Dashboard</h4>
        <small class="text-muted">ISP link uptime, latency & packet loss for {{ now()->format('F Y') }}</small>
    </div>
</div>

@if($stats->isEmpty())
<div class="card shadow-sm">
    <div class="card-body text-center py-5 text-muted">
        <i class="bi bi-graph-up display-4 d-block mb-2"></i>
        No ISP connections configured yet.<br>
        <a href="{{ route('admin.network.isp.create') }}" class="btn btn-primary btn-sm mt-3"><i class="bi bi-plus-lg me-1"></i>Add ISP Connection</a>
    </div>
</div>
@else

{{-- Summary Row --}}
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card shadow-sm border-start border-primary border-4">
            <div class="card-body text-center">
                <div class="text-muted small">Total ISPs</div>
                <div class="fs-2 fw-bold text-primary">{{ $stats->count() }}</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card shadow-sm border-start border-success border-4">
            <div class="card-body text-center">
                <div class="text-muted small">Avg Uptime</div>
                @php $avgUp = $stats->avg('uptime'); @endphp
                <div class="fs-2 fw-bold {{ $avgUp >= 99 ? 'text-success' : ($avgUp >= 95 ? 'text-warning' : 'text-danger') }}">
                    {{ number_format($avgUp, 1) }}%
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card shadow-sm border-start border-info border-4">
            <div class="card-body text-center">
                <div class="text-muted small">Avg Latency</div>
                @php $avgLat = $stats->avg('avg_latency'); @endphp
                <div class="fs-2 fw-bold text-info">{{ number_format($avgLat, 1) }}ms</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card shadow-sm border-start border-warning border-4">
            <div class="card-body text-center">
                <div class="text-muted small">Avg Packet Loss</div>
                @php $avgLoss = $stats->avg('avg_loss'); @endphp
                <div class="fs-2 fw-bold {{ $avgLoss <= 1 ? 'text-success' : ($avgLoss <= 5 ? 'text-warning' : 'text-danger') }}">
                    {{ number_format($avgLoss, 2) }}%
                </div>
            </div>
        </div>
    </div>
</div>

{{-- ISP Cards --}}
<div class="row g-3">
    @foreach($stats as $s)
    @php $isp = $s['isp']; @endphp
    <div class="col-lg-6 col-xl-4">
        <div class="card shadow-sm h-100">
            <div class="card-header bg-transparent d-flex justify-content-between align-items-center">
                <div>
                    <strong>{{ $isp->provider }}</strong>
                    <div class="text-muted small">{{ $isp->circuit_id ?: 'No circuit ID' }} &middot; {{ $isp->branch?->name ?: 'Unassigned' }}</div>
                </div>
                <a href="{{ route('admin.network.sla.detail', $isp->id) }}" class="btn btn-sm btn-outline-primary">
                    <i class="bi bi-graph-up"></i>
                </a>
            </div>
            <div class="card-body">
                {{-- Uptime --}}
                <div class="mb-3">
                    <div class="d-flex justify-content-between small mb-1">
                        <span class="text-muted">Monthly Uptime</span>
                        <span class="fw-bold {{ $s['uptime'] >= 99 ? 'text-success' : ($s['uptime'] >= 95 ? 'text-warning' : 'text-danger') }}">
                            {{ number_format($s['uptime'], 2) }}%
                        </span>
                    </div>
                    <div class="progress" style="height:6px">
                        <div class="progress-bar {{ $s['uptime'] >= 99 ? 'bg-success' : ($s['uptime'] >= 95 ? 'bg-warning' : 'bg-danger') }}"
                             style="width:{{ min($s['uptime'], 100) }}%"></div>
                    </div>
                </div>

                {{-- Latency & Loss --}}
                <div class="row text-center small">
                    <div class="col-4">
                        <div class="text-muted">Latency</div>
                        <div class="fw-bold {{ $s['avg_latency'] <= 50 ? 'text-success' : ($s['avg_latency'] <= 100 ? 'text-warning' : 'text-danger') }}">
                            {{ number_format($s['avg_latency'], 1) }}ms
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="text-muted">Pkt Loss</div>
                        <div class="fw-bold {{ $s['avg_loss'] <= 1 ? 'text-success' : ($s['avg_loss'] <= 5 ? 'text-warning' : 'text-danger') }}">
                            {{ number_format($s['avg_loss'], 2) }}%
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="text-muted">Checks (24h)</div>
                        <div class="fw-bold">{{ $s['checks_24h'] }}</div>
                    </div>
                </div>

                {{-- Last Check --}}
                @if($s['last_check'])
                <div class="mt-3 small text-muted border-top pt-2">
                    <i class="bi bi-clock me-1"></i>Last check: {{ $s['last_check']->checked_at->diffForHumans() }}
                    &mdash;
                    @if($s['last_check']->success)
                    <span class="text-success"><i class="bi bi-check-circle-fill"></i> OK</span>
                    @else
                    <span class="text-danger"><i class="bi bi-x-circle-fill"></i> Failed</span>
                    @endif
                </div>
                @else
                <div class="mt-3 small text-muted border-top pt-2">
                    <i class="bi bi-clock me-1"></i>No checks recorded yet
                </div>
                @endif
            </div>
        </div>
    </div>
    @endforeach
</div>
@endif

@endsection
