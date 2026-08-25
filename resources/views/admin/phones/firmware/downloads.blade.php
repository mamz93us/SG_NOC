@extends('layouts.admin')

@section('content')

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-download me-2 text-primary"></i>Firmware Downloads</h4>
        <small class="text-muted">Which phone fetched which image, and when</small>
    </div>
    <div class="d-flex gap-2">
        <a href="{{ route('admin.phones.firmware.index') }}" class="btn btn-outline-primary btn-sm">
            <i class="bi bi-hdd-network me-1"></i>Library
        </a>
        <a href="{{ route('admin.phones.firmware.status') }}" class="btn btn-outline-primary btn-sm">
            <i class="bi bi-clipboard-check me-1"></i>Status
        </a>
    </div>
</div>

{{-- Summary --}}
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <a href="{{ route('admin.phones.firmware.downloads') }}" class="text-decoration-none">
            <div class="card shadow-sm border-start border-primary border-4">
                <div class="card-body text-center">
                    <div class="text-muted small">Requests logged</div>
                    <div class="fs-3 fw-bold">{{ number_format($counts['total']) }}</div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-6 col-lg-3">
        <a href="{{ route('admin.phones.firmware.downloads', ['state' => 'delivered']) }}" class="text-decoration-none">
            <div class="card shadow-sm border-start border-success border-4">
                <div class="card-body text-center">
                    <div class="text-muted small">Delivered</div>
                    <div class="fs-3 fw-bold text-success">{{ number_format($counts['delivered']) }}</div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-6 col-lg-3">
        <a href="{{ route('admin.phones.firmware.downloads', ['state' => 'missing']) }}" class="text-decoration-none">
            <div class="card shadow-sm border-start border-danger border-4">
                <div class="card-body text-center">
                    <div class="text-muted small">Not found (404)</div>
                    <div class="fs-3 fw-bold text-danger">{{ number_format($counts['missing']) }}</div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card shadow-sm border-start border-info border-4">
            <div class="card-body text-center">
                <div class="text-muted small">Distinct phones</div>
                <div class="fs-3 fw-bold">{{ number_format($counts['phones']) }}</div>
            </div>
        </div>
    </div>
</div>

{{-- The actionable half of the 404s --}}
@if($wanted->isNotEmpty())
<div class="card shadow-sm mb-4 border-start border-warning border-4">
    <div class="card-body">
        <h6 class="fw-semibold mb-2">
            <i class="bi bi-question-octagon me-2 text-warning"></i>Phones are asking for files that are not published
        </h6>
        <p class="text-muted small mb-3">
            Each of these is a phone requesting a filename the firmware directory does not hold. For a
            <code>*fw.bin</code> that means the image for that model has not been published yet; ringtone files
            (<code>ring1.bin</code> and friends) are normal and harmless — phones just keep their built-in tones.
        </p>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                    <tr><th>Filename</th><th>Requests</th><th>Last asked</th><th></th></tr>
                </thead>
                <tbody>
                @foreach($wanted as $w)
                    <tr>
                        <td><code>{{ $w->filename }}</code></td>
                        <td>{{ number_format($w->hits) }}</td>
                        <td class="small text-muted">{{ \Carbon\Carbon::parse($w->last_seen)->diffForHumans() }}</td>
                        <td>
                            @if(\Illuminate\Support\Str::startsWith($w->filename, 'ring'))
                                <span class="badge bg-secondary">ringtone — ignorable</span>
                            @else
                                <span class="badge bg-warning text-dark">firmware not published</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
@endif

{{-- Filters --}}
<form method="GET" class="row g-2 mb-3 align-items-end">
    <div class="col-md-4">
        <label class="form-label small text-muted mb-1">Search</label>
        <input type="text" name="q" class="form-control form-control-sm"
               placeholder="MAC, IP, model, filename…" value="{{ $q }}">
    </div>
    <div class="col-md-3">
        <label class="form-label small text-muted mb-1">File</label>
        <select name="file" class="form-select form-select-sm">
            <option value="">All files</option>
            @foreach($files as $f)
                <option value="{{ $f }}" @selected($file === $f)>{{ $f }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-md-2">
        <label class="form-label small text-muted mb-1">Outcome</label>
        <select name="state" class="form-select form-select-sm">
            <option value="">All</option>
            <option value="delivered" @selected($state === 'delivered')>Delivered</option>
            <option value="missing"   @selected($state === 'missing')>Not found</option>
        </select>
    </div>
    <div class="col-md-1">
        <button class="btn btn-sm btn-primary w-100">Filter</button>
    </div>
    <div class="col-md-2">
        <a href="{{ route('admin.phones.firmware.downloads') }}" class="btn btn-sm btn-outline-secondary w-100">Reset</a>
    </div>
</form>

<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>When</th>
                    <th>Phone</th>
                    <th>Running</th>
                    <th>IP</th>
                    <th>File</th>
                    <th>Result</th>
                    <th class="text-end">Size</th>
                </tr>
            </thead>
            <tbody>
            @forelse($downloads as $d)
                @php $device = $d->mac ? ($devices[$d->mac] ?? null) : null; @endphp
                <tr>
                    <td class="small text-nowrap">
                        {{ $d->requested_at?->timezone(config('app.timezone'))->format('Y-m-d H:i:s') }}
                        <div class="text-muted">{{ $d->requested_at?->diffForHumans() }}</div>
                    </td>
                    <td>
                        @if($d->model || $d->mac)
                            <div class="fw-semibold">{{ $d->model ?? 'Grandstream' }}</div>
                            <code class="small text-muted">{{ $d->macFormatted() ?? '—' }}</code>
                            @if($device)
                                <a href="{{ route('admin.devices.show', $device) }}" class="small ms-1"
                                   title="Asset {{ $device->asset_code }}"><i class="bi bi-box-seam"></i></a>
                            @endif
                        @else
                            <span class="text-muted small">not a Grandstream client</span>
                        @endif
                    </td>
                    <td class="small">{{ $d->firmware_version ?? '—' }}</td>
                    <td class="small font-monospace">{{ $d->ip }}</td>
                    <td><code>{{ $d->filename }}</code></td>
                    <td><span class="badge {{ $d->statusBadgeClass() }}">{{ $d->status }}</span></td>
                    <td class="text-end small">{{ $d->humanSize() }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="text-center text-muted py-4">
                        Nothing logged yet. Downloads appear once <code>firmware:ingest-log</code> has run —
                        it reads the firmware server's nginx access log every minute.
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @if($downloads->hasPages())
        <div class="card-footer">{{ $downloads->links() }}</div>
    @endif
</div>

<p class="text-muted small mt-3 mb-0">
    <i class="bi bi-info-circle me-1"></i>
    nginx serves the firmware images directly, so these rows come from its access log rather than the app.
    A phone identifies itself in its User-Agent, which is where the model, MAC and running version come from —
    anything without one is some other client that found the directory.
</p>

@endsection
