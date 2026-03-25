@extends('layouts.admin')
@section('title', 'Voice Quality Reports')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-telephone-fill me-2 text-primary"></i>Voice Quality Reports</h4>
        <small class="text-muted">All captured call quality records</small>
    </div>
    <div class="d-flex gap-2">
        <a href="{{ route('admin.voice-quality.dashboard') }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-speedometer2 me-1"></i>Dashboard</a>
        <a href="{{ route('admin.voice-quality.export', request()->query()) }}" class="btn btn-sm btn-outline-success"><i class="bi bi-download me-1"></i>Export CSV</a>
    </div>
</div>

<form method="GET" class="row g-2 mb-3 align-items-end">
    <div class="col-auto">
        <select name="branch" class="form-select form-select-sm">
            <option value="">All Branches</option>
            @foreach($branches as $b)
            <option value="{{ $b }}" {{ request('branch') == $b ? 'selected' : '' }}>{{ $b }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-auto">
        <input type="text" name="extension" class="form-control form-control-sm" placeholder="Extension" value="{{ request('extension') }}">
    </div>
    <div class="col-auto">
        <select name="quality_label" class="form-select form-select-sm">
            <option value="">All Quality</option>
            @foreach(['excellent','good','fair','poor','bad'] as $ql)
            <option value="{{ $ql }}" {{ request('quality_label') == $ql ? 'selected' : '' }}>{{ ucfirst($ql) }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-auto">
        <select name="codec" class="form-select form-select-sm">
            <option value="">All Codecs</option>
            @foreach($codecs as $c)
            <option value="{{ $c }}" {{ request('codec') == $c ? 'selected' : '' }}>{{ $c }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-auto">
        <input type="date" name="date_from" class="form-control form-control-sm" value="{{ request('date_from') }}" placeholder="From">
    </div>
    <div class="col-auto">
        <input type="date" name="date_to" class="form-control form-control-sm" value="{{ request('date_to') }}" placeholder="To">
    </div>
    <div class="col-auto">
        <button type="submit" class="btn btn-sm btn-secondary">Filter</button>
        <a href="{{ route('admin.voice-quality.index') }}" class="btn btn-sm btn-outline-secondary">Clear</a>
    </div>
</form>

<div class="card shadow-sm">
    <div class="card-body p-0">
        @if($reports->isEmpty())
        <div class="text-center py-5 text-muted"><i class="bi bi-telephone-x display-4 d-block mb-2"></i>No reports found.</div>
        @else
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 small">
                <thead class="table-light">
                    <tr>
                        <th>Extension</th>
                        <th>Remote</th>
                        <th>Branch</th>
                        <th>Codec</th>
                        <th>
                            <a href="{{ request()->fullUrlWithQuery(['sort'=>'mos_lq','dir'=>request('dir')=='asc'?'desc':'asc']) }}" class="text-decoration-none text-dark">
                                MOS-LQ <i class="bi bi-arrow-down-up text-muted"></i>
                            </a>
                        </th>
                        <th>
                            <a href="{{ request()->fullUrlWithQuery(['sort'=>'jitter_avg','dir'=>request('dir')=='asc'?'desc':'asc']) }}" class="text-decoration-none text-dark">
                                Jitter <i class="bi bi-arrow-down-up text-muted"></i>
                            </a>
                        </th>
                        <th>
                            <a href="{{ request()->fullUrlWithQuery(['sort'=>'packet_loss','dir'=>request('dir')=='asc'?'desc':'asc']) }}" class="text-decoration-none text-dark">
                                Loss% <i class="bi bi-arrow-down-up text-muted"></i>
                            </a>
                        </th>
                        <th>RTT</th>
                        <th>Quality</th>
                        <th>Duration</th>
                        <th>
                            <a href="{{ request()->fullUrlWithQuery(['sort'=>'created_at','dir'=>request('dir')=='asc'?'desc':'asc']) }}" class="text-decoration-none text-dark">
                                Time <i class="bi bi-arrow-down-up text-muted"></i>
                            </a>
                        </th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($reports as $r)
                    <tr>
                        <td class="fw-semibold">{{ $r->extension ?: '—' }}</td>
                        <td class="text-muted">{{ $r->remote_extension ?: '—' }}</td>
                        <td>{{ $r->branch ?: '—' }}</td>
                        <td class="font-monospace text-muted small">{{ $r->codec ?: '—' }}</td>
                        <td>
                            @if($r->mos_lq !== null)
                            <span class="badge bg-{{ $r->mos_lq >= 4.0 ? 'success' : ($r->mos_lq >= 3.6 ? 'warning' : 'danger') }}">
                                {{ number_format($r->mos_lq, 2) }}
                            </span>
                            @else
                            <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td class="text-muted">{{ $r->jitter_avg !== null ? number_format($r->jitter_avg, 1).'ms' : '—' }}</td>
                        <td class="text-muted">{{ $r->packet_loss !== null ? number_format($r->packet_loss, 2).'%' : '—' }}</td>
                        <td class="text-muted">{{ $r->rtt !== null ? $r->rtt.'ms' : '—' }}</td>
                        <td>
                            @if($r->quality_label)
                            @php
                                $qColors = ['excellent'=>'success','good'=>'info','fair'=>'warning','poor'=>'danger','bad'=>'dark'];
                            @endphp
                            <span class="badge bg-{{ $qColors[$r->quality_label] ?? 'secondary' }}">{{ ucfirst($r->quality_label) }}</span>
                            @else
                            <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td class="text-muted">{{ $r->call_duration_seconds !== null ? gmdate('i:s', $r->call_duration_seconds) : '—' }}</td>
                        <td class="text-muted small">{{ $r->created_at->format('d M H:i') }}</td>
                        <td>
                            <a href="{{ route('admin.voice-quality.show', $r) }}" class="btn btn-sm btn-outline-primary py-0 px-1"><i class="bi bi-eye"></i></a>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="p-3">{{ $reports->links() }}</div>
        @endif
    </div>
</div>
@endsection
