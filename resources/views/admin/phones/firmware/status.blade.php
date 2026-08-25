@extends('layouts.admin')

@section('content')

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-clipboard-check me-2 text-primary"></i>Phone Firmware Status</h4>
        <small class="text-muted">What each phone is actually running, against what the NOC publishes</small>
    </div>
    <div class="d-flex gap-2">
        <a href="{{ route('admin.phones.firmware.index') }}" class="btn btn-outline-primary btn-sm">
            <i class="bi bi-hdd-network me-1"></i>Firmware library
        </a>
        <a href="{{ route('admin.phones.firmware.downloads') }}" class="btn btn-outline-primary btn-sm">
            <i class="bi bi-download me-1"></i>Download log
        </a>
    </div>
</div>

@if($active->isEmpty())
<div class="alert alert-warning">
    <i class="bi bi-exclamation-triangle me-2"></i>
    Nothing is published yet, so there is no target to compare against.
    <a href="{{ route('admin.phones.firmware.index') }}" class="alert-link">Publish an image</a> first.
</div>
@else
<div class="card shadow-sm mb-4">
    <div class="card-body py-2">
        <span class="text-muted small me-2">Publishing:</span>
        @foreach($active as $fw)
            <span class="badge bg-success-subtle text-success-emphasis border border-success-subtle me-1">
                {{ $fw->covers ?: $fw->model }} &rarr; {{ $fw->version }}
                <code class="ms-1 text-muted">{{ $fw->filename }}</code>
            </span>
        @endforeach
    </div>
</div>
@endif

{{-- Summary --}}
<div class="row g-3 mb-4">
    <div class="col-md-4">
        <a href="{{ route('admin.phones.firmware.status', ['state' => 'current']) }}" class="text-decoration-none">
            <div class="card shadow-sm border-start border-success border-4">
                <div class="card-body text-center">
                    <div class="text-muted small">Up to date</div>
                    <div class="fs-2 fw-bold text-success">{{ $counts['current'] }}</div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-md-4">
        <a href="{{ route('admin.phones.firmware.status', ['state' => 'behind']) }}" class="text-decoration-none">
            <div class="card shadow-sm border-start border-danger border-4">
                <div class="card-body text-center">
                    <div class="text-muted small">Behind</div>
                    <div class="fs-2 fw-bold text-danger">{{ $counts['behind'] }}</div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-md-4">
        <a href="{{ route('admin.phones.firmware.status', ['state' => 'unknown']) }}" class="text-decoration-none">
            <div class="card shadow-sm border-start border-secondary border-4">
                <div class="card-body text-center">
                    <div class="text-muted small">Unknown</div>
                    <div class="fs-2 fw-bold text-secondary">{{ $counts['unknown'] }}</div>
                </div>
            </div>
        </a>
    </div>
</div>

{{-- Filters --}}
<form method="GET" class="row g-2 mb-3 align-items-end">
    <div class="col-md-4">
        <label class="form-label small text-muted mb-1">Search</label>
        <input type="text" name="q" class="form-control form-control-sm"
               placeholder="MAC, model, branch, IP…" value="{{ $q }}">
    </div>
    <div class="col-md-3">
        <label class="form-label small text-muted mb-1">State</label>
        <select name="state" class="form-select form-select-sm">
            <option value="">All</option>
            <option value="behind"  @selected($state === 'behind')>Behind</option>
            <option value="current" @selected($state === 'current')>Up to date</option>
            <option value="unknown" @selected($state === 'unknown')>Unknown</option>
        </select>
    </div>
    <div class="col-md-2">
        <button class="btn btn-sm btn-primary w-100">Filter</button>
    </div>
    <div class="col-md-2">
        <a href="{{ route('admin.phones.firmware.status') }}" class="btn btn-sm btn-outline-secondary w-100">Reset</a>
    </div>
</form>

<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Phone</th>
                    <th>Model</th>
                    <th>Branch</th>
                    <th>IP</th>
                    <th>Running</th>
                    <th>Target</th>
                    <th>Last check-in</th>
                    <th>State</th>
                </tr>
            </thead>
            <tbody>
            @forelse($rows as $row)
                <tr>
                    <td>
                        <div class="fw-semibold">{{ $row['name'] ?? '—' }}</div>
                        <code class="small text-muted">{{ $row['mac'] }}</code>
                        @if($row['unmanaged'] ?? false)
                            <span class="badge bg-warning text-dark ms-1" title="Talks to the NOC but has no ITAM asset">No asset</span>
                        @endif
                    </td>
                    <td>{{ $row['model'] ?? '—' }}</td>
                    <td class="small">{{ $row['branch'] ?? '—' }}</td>
                    <td class="small font-monospace">{{ $row['ip'] ?? '—' }}</td>
                    <td>{{ $row['running'] ?? '—' }}</td>
                    <td class="small text-muted">
                        {{ $row['target'] ?? '—' }}
                        @if($row['target_file'])<br><code class="small">{{ $row['target_file'] }}</code>@endif
                    </td>
                    <td class="small text-muted">
                        @if($row['last_seen'])
                            {{ $row['last_seen']->diffForHumans() }}
                        @else
                            <span class="text-danger">never</span>
                        @endif
                    </td>
                    <td>
                        @if($row['state'] === 'current')
                            <span class="badge bg-success">Up to date</span>
                        @elseif($row['state'] === 'behind')
                            <span class="badge bg-danger">Behind</span>
                        @else
                            <span class="badge bg-secondary">Unknown</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="8" class="text-center text-muted py-4">No phones match.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

<p class="text-muted small mt-3 mb-0">
    <i class="bi bi-info-circle me-1"></i>
    <strong>Running</strong> is what the phone told us in the User-Agent on its last <code>/phonebook.xml</code> fetch,
    falling back to GDMS. A phone that has <em>never</em> checked in cannot reach the NOC at all — check the branch
    tunnel's traffic selector before blaming the firmware server.
</p>

@endsection
