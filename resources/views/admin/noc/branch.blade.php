@extends('layouts.admin')
@section('content')

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-building me-2 text-primary"></i>{{ $branch->name }}</h4>
        <small class="text-muted">Branch health drill-down</small>
    </div>
    <a href="{{ route('admin.noc.dashboard') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>NOC Dashboard</a>
</div>

{{-- Score Cards --}}
<div class="row g-3 mb-4">
    @foreach([
        ['identity', 'Identity', 'bi-people-fill', 'primary'],
        ['voice',    'Voice',    'bi-telephone-fill', 'info'],
        ['network',  'Network',  'bi-diagram-3-fill', 'success'],
        ['asset',    'Assets',   'bi-cpu-fill', 'warning'],
    ] as [$key, $label, $icon, $color])
    @php $s = $score[$key] ?? 0; @endphp
    <div class="col-6 col-lg-3">
        <div class="card shadow-sm border-0 text-center">
            <div class="card-body py-4">
                <i class="bi {{ $icon }} fs-2 text-{{ $color }} mb-2 d-block"></i>
                <div class="display-5 fw-bold text-{{ $color }}">{{ $s }}%</div>
                <div class="small text-muted mt-1">{{ $label }} Health</div>
                <div class="progress mt-2" style="height:6px">
                    <div class="progress-bar bg-{{ $color }}" style="width:{{ $s }}%"></div>
                </div>
            </div>
        </div>
    </div>
    @endforeach
</div>

{{-- Overall Score --}}
<div class="alert alert-{{ $score['total'] >= 90 ? 'success' : ($score['total'] >= 70 ? 'info' : ($score['total'] >= 50 ? 'warning' : 'danger')) }} d-flex align-items-center gap-3 mb-4">
    <div class="display-6 fw-bold">{{ $score['total'] }}%</div>
    <div>
        <strong>Overall Branch Health</strong>
        <div class="small">Average of all 4 module scores</div>
    </div>
</div>

<div class="row g-4">
    {{-- Network Switches --}}
    <div class="col-12 col-lg-6">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-transparent"><strong><i class="bi bi-diagram-3-fill me-1"></i>Switches ({{ $switches->count() }})</strong></div>
            <div class="card-body p-0">
                @if($switches->isEmpty())
                <div class="text-center py-3 text-muted small">No switches in this branch.</div>
                @else
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0 small">
                        <tbody>
                            @foreach($switches as $sw)
                            <tr>
                                <td class="ps-3">
                                    <span class="badge {{ $sw->statusBadgeClass() }}"><i class="bi bi-circle-fill me-1" style="font-size:7px"></i>{{ ucfirst($sw->status) }}</span>
                                </td>
                                <td class="fw-semibold">{{ $sw->name }}</td>
                                <td class="text-muted">{{ $sw->model }}</td>
                                <td class="pe-3 text-end">{{ $sw->port_count }} ports</td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @endif
            </div>
        </div>
    </div>

    {{-- Devices --}}
    <div class="col-12 col-lg-6">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-transparent"><strong><i class="bi bi-cpu me-1"></i>Devices ({{ $devices->count() }})</strong></div>
            <div class="card-body p-0">
                @if($devices->isEmpty())
                <div class="text-center py-3 text-muted small">No devices in this branch.</div>
                @else
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0 small">
                        <tbody>
                            @foreach($devices->take(10) as $dev)
                            <tr>
                                <td class="ps-3"><span class="badge {{ $dev->typeBadgeClass() }}">{{ $dev->typeLabel() }}</span></td>
                                <td class="fw-semibold">{{ $dev->name }}</td>
                                <td class="text-muted">
                                    @if($dev->credentials->isEmpty())
                                    <span class="badge bg-warning text-dark"><i class="bi bi-key me-1"></i>No Creds</span>
                                    @else
                                    <span class="text-success small"><i class="bi bi-check-circle me-1"></i>{{ $dev->credentials->count() }} creds</span>
                                    @endif
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if($devices->count() > 10)
                <div class="px-3 py-2 border-top text-muted small">+ {{ $devices->count() - 10 }} more devices</div>
                @endif
                @endif
            </div>
        </div>
    </div>
</div>
@endsection
