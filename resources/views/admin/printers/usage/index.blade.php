@extends('layouts.admin')

@section('content')
<div class="container-fluid py-4">
    <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
        <div>
            <h4 class="mb-0 fw-bold"><i class="bi bi-bar-chart-fill me-2 text-success"></i>Printer Usage Report</h4>
            <small class="text-muted">Pages printed per period, computed from daily counter snapshots.</small>
        </div>
        <div class="d-flex gap-2">
            <form method="POST" action="{{ route('admin.printers.usage.snapshot') }}">
                @csrf
                <button type="submit" class="btn btn-outline-success btn-sm" title="Take a counter snapshot for all printers right now">
                    <i class="bi bi-camera-fill me-1"></i>Snapshot Now
                </button>
            </form>
            <a href="{{ route('admin.printers.unified.index') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-collection me-1"></i>Unified View</a>
            <a href="{{ route('admin.printers.dashboard') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-speedometer2 me-1"></i>Dashboard</a>
        </div>
    </div>

    @if (session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif
    @if (session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    @if (! $earliestSnapshot)
        <div class="alert alert-info small mb-3">
            <i class="bi bi-info-circle me-1"></i>
            No historical snapshots yet — today's baseline was just captured. Period diffs will be available starting tomorrow.
            The <strong>Current Total</strong> column shows the live SNMP page counter right now.
        </div>
    @elseif (\Carbon\Carbon::parse($earliestSnapshot)->gt($from))
        <div class="alert alert-info small mb-3">
            <i class="bi bi-info-circle me-1"></i>
            Snapshots only go back to <strong>{{ \Carbon\Carbon::parse($earliestSnapshot)->format('Y-m-d') }}</strong>.
            Period diffs before that date use the live counter as a fallback.
        </div>
    @endif

    <form method="GET" class="row g-2 mb-3">
        <div class="col-md-3">
            <label class="form-label small mb-0">From</label>
            <input type="date" name="from" class="form-control form-control-sm" value="{{ $from->toDateString() }}">
        </div>
        <div class="col-md-3">
            <label class="form-label small mb-0">To</label>
            <input type="date" name="to" class="form-control form-control-sm" value="{{ $to->toDateString() }}">
        </div>
        <div class="col-md-3">
            <label class="form-label small mb-0">Branch</label>
            <select name="branch" class="form-select form-select-sm">
                <option value="">All branches</option>
                @foreach ($branches as $b)
                    <option value="{{ $b->id }}" @selected($branchId == $b->id)>{{ $b->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-md-3 d-flex align-items-end gap-1">
            <button type="submit" class="btn btn-primary btn-sm flex-grow-1">Apply</button>
            <a href="{{ route('admin.printers.usage') }}" class="btn btn-outline-secondary btn-sm">Reset</a>
        </div>
    </form>

    <div class="row g-3 mb-3">
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body text-center py-3">
                    <div class="fs-3 fw-bold text-primary">{{ number_format($totalPages) }}</div>
                    <div class="small text-muted">Period Pages — {{ $from->format('Y-m-d') }} → {{ $to->format('Y-m-d') }}</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body text-center py-3">
                    <div class="fs-3 fw-bold text-info">{{ number_format($totalColor) }}</div>
                    <div class="small text-muted">Color Pages (Period)</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body text-center py-3">
                    <div class="fs-3 fw-bold text-secondary">{{ number_format($totalMono) }}</div>
                    <div class="small text-muted">Mono Pages (Period)</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body text-center py-3">
                    <div class="fs-3 fw-bold text-success">{{ number_format($totalCurrent ?? 0) }}</div>
                    <div class="small text-muted">Current Total (Live SNMP)</div>
                </div>
            </div>
        </div>
    </div>

    @php
        $sortLink = function ($field) use ($sort, $dir) {
            $newDir = ($sort === $field && $dir === 'desc') ? 'asc' : 'desc';
            $params = array_merge(request()->query(), ['sort' => $field, 'dir' => $newDir]);
            return '?' . http_build_query($params);
        };
        $arrow = fn ($field) => $sort === $field ? ($dir === 'desc' ? ' ↓' : ' ↑') : '';
    @endphp

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle small">
                <thead class="table-light">
                    <tr>
                        <th><a href="{{ $sortLink('name') }}" class="text-decoration-none">Printer{{ $arrow('name') }}</a></th>
                        <th><a href="{{ $sortLink('branch') }}" class="text-decoration-none">Branch{{ $arrow('branch') }}</a></th>
                        <th>First Snapshot</th>
                        <th>Last Snapshot</th>
                        <th class="text-end"><a href="{{ $sortLink('pages') }}" class="text-decoration-none">Period Pages{{ $arrow('pages') }}</a></th>
                        <th class="text-end"><a href="{{ $sortLink('color') }}" class="text-decoration-none">Color{{ $arrow('color') }}</a></th>
                        <th class="text-end"><a href="{{ $sortLink('mono') }}" class="text-decoration-none">Mono{{ $arrow('mono') }}</a></th>
                        <th class="text-end"><a href="{{ $sortLink('current') }}" class="text-decoration-none">Current Total{{ $arrow('current') }}</a></th>
                    </tr>
                </thead>
                <tbody>
                @forelse ($rows as $r)
                    <tr>
                        <td><a href="{{ route('admin.printers.unified.show', $r['id']) }}" class="text-decoration-none">{{ $r['name'] }}</a></td>
                        <td>{{ $r['branch'] ?? '—' }}</td>
                        <td>
                            {{ $r['first'] ?? '—' }}
                            @if ($r['first_total'] !== null) <small class="text-muted d-block">{{ number_format($r['first_total']) }}</small>@endif
                        </td>
                        <td>
                            {{ $r['last'] ?? '—' }}
                            @if (($r['last_is_live'] ?? false))
                                <span class="badge bg-success-subtle text-success border border-success-subtle">live</span>
                            @endif
                            @if ($r['last_total'] !== null) <small class="text-muted d-block">{{ number_format($r['last_total']) }}</small>@endif
                        </td>
                        <td class="text-end fw-semibold">
                            {{ number_format($r['pages']) }}
                            @if ($r['anomaly']) <span class="badge bg-warning text-dark" title="Counter rolled back">⚠</span>@endif
                        </td>
                        <td class="text-end">{{ number_format($r['color']) }}</td>
                        <td class="text-end">{{ number_format($r['mono']) }}</td>
                        <td class="text-end text-success fw-semibold">
                            {{ $r['current_total'] !== null ? number_format($r['current_total']) : '—' }}
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="text-center text-muted py-4">No usage data for this period.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if (! empty($branchTotals))
        <div class="card border-0 shadow-sm mt-3">
            <div class="card-body">
                <h6 class="text-uppercase small fw-bold text-muted mb-2">Branch Totals</h6>
                <table class="table table-sm mb-0">
                    <thead><tr><th>Branch</th><th class="text-end">Period Pages</th><th class="text-end">Color</th><th class="text-end">Mono</th><th class="text-end">Current Total</th></tr></thead>
                    <tbody>
                        @foreach ($branchTotals as $branch => $t)
                            <tr>
                                <td>{{ $branch }}</td>
                                <td class="text-end">{{ number_format($t['pages']) }}</td>
                                <td class="text-end">{{ number_format($t['color']) }}</td>
                                <td class="text-end">{{ number_format($t['mono']) }}</td>
                                <td class="text-end text-success">{{ number_format($t['current'] ?? 0) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>
@endsection
