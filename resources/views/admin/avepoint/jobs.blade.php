@extends('layouts.admin')

@section('content')

<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0"><i class="bi bi-cloud-arrow-down-fill text-info me-2"></i>AvePoint · Live Jobs</h1>
    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="location.reload()">
        <i class="bi bi-arrow-clockwise me-1"></i>Refresh
    </button>
</div>

@include('admin.avepoint._nav')

@if(! $configured)
    <div class="alert alert-warning py-2">AvePoint is not configured — live jobs are unavailable.</div>
@endif

<div class="alert alert-light border py-2 small mb-3">
    <i class="bi bi-info-circle me-1"></i>
    <strong>Tip:</strong> when you trigger an export from AvePoint's web UI (Restore → Export to PST/ZIP),
    it appears here as <code>JobType = Export</code>. Filter to "Export" below to see only those. The
    job will show <em>Finished</em> when the file is ready in AvePoint, but the public API does NOT
    expose a download endpoint — you must download from AvePoint's UI and upload via NOC.
</div>

<form method="GET" class="row g-2 mb-3">
    <div class="col-md-3">
        <label class="form-label small text-muted">Service</label>
        <select name="object_type" class="form-select form-select-sm">
            <option value="">All</option>
            <option value="1" {{ $objectType==='1'?'selected':'' }}>Exchange Online</option>
            <option value="3" {{ $objectType==='3'?'selected':'' }}>OneDrive</option>
            <option value="2" {{ $objectType==='2'?'selected':'' }}>SharePoint Online</option>
            <option value="7" {{ $objectType==='7'?'selected':'' }}>Teams</option>
        </select>
    </div>
    <div class="col-md-3">
        <label class="form-label small text-muted">Job Type</label>
        <select name="job_type" class="form-select form-select-sm">
            <option value="">All</option>
            <option value="1" {{ ($jobType??'')==='1'?'selected':'' }}>Backup</option>
            <option value="2" {{ ($jobType??'')==='2'?'selected':'' }}>Restore</option>
            <option value="3" {{ ($jobType??'')==='3'?'selected':'' }}>Export</option>
            <option value="4" {{ ($jobType??'')==='4'?'selected':'' }}>Deletion</option>
            <option value="5" {{ ($jobType??'')==='5'?'selected':'' }}>Retention</option>
        </select>
    </div>
    <div class="col-md-3">
        <label class="form-label small text-muted">State</label>
        <select name="state" class="form-select form-select-sm">
            <option value="">All</option>
            <option value="1" {{ $state==='1'?'selected':'' }}>In Progress</option>
            <option value="2" {{ $state==='2'?'selected':'' }}>Finished</option>
            <option value="3" {{ $state==='3'?'selected':'' }}>Failed</option>
            <option value="4" {{ $state==='4'?'selected':'' }}>Finished w/ Exceptions</option>
            <option value="5" {{ $state==='5'?'selected':'' }}>Partially Finished</option>
        </select>
    </div>
    <div class="col-md-3 align-self-end">
        <button class="btn btn-outline-secondary btn-sm w-100"><i class="bi bi-funnel me-1"></i>Filter</button>
    </div>
</form>

<div class="card border-0 shadow-sm">
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Job ID</th>
                    <th>State</th>
                    <th>Start</th>
                    <th>Finish</th>
                    <th>Duration</th>
                    <th class="text-end">Total</th>
                    <th class="text-end">Success</th>
                    <th class="text-end">Failed</th>
                    <th>Errors</th>
                </tr>
            </thead>
            <tbody>
                @forelse($jobs as $j)
                    @php
                        $state = strtolower((string)($j['state'] ?? ''));
                        $badge = str_contains($state,'fail')        ? 'bg-danger'
                              : (str_contains($state,'progress')||str_contains($state,'running') ? 'bg-info text-dark'
                              : (str_contains($state,'finish')      ? 'bg-success'
                              : (str_contains($state,'partial')     ? 'bg-warning text-dark'
                              : 'bg-secondary')));
                        $bd = $j['backupDetails'] ?? [];
                    @endphp
                    <tr>
                        <td><code class="small">{{ $j['id'] ?? '—' }}</code></td>
                        <td><span class="badge {{ $badge }}">{{ $j['state'] ?? '—' }}</span></td>
                        <td class="small text-muted">{{ $j['startTime'] ?? '—' }}</td>
                        <td class="small text-muted">{{ $j['finishTime'] ?? '—' }}</td>
                        <td class="small">{{ $j['duration'] ?? '—' }} h</td>
                        <td class="text-end">{{ $bd['totalCount'] ?? $bd['totalNumber'] ?? '—' }}</td>
                        <td class="text-end text-success">{{ $bd['successfulCount'] ?? $bd['successfulNumber'] ?? '—' }}</td>
                        <td class="text-end text-danger">{{ $bd['failedCount'] ?? $bd['failedNumber'] ?? '—' }}</td>
                        <td class="small text-muted">
                            @if(! empty($j['jobErrors']))
                                {{ count($j['jobErrors']) }} error(s)
                            @else
                                —
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="text-muted py-3 px-3">
                        @if(!empty($jobsError))
                            <div class="text-danger"><i class="bi bi-exclamation-triangle me-1"></i>{{ $jobsError }}</div>
                            @if(!empty($requestUrl))
                                <div class="small font-monospace mt-1" style="word-break:break-all;color:#888;">{{ $requestUrl }}</div>
                            @endif
                        @else
                            <div class="text-center">No jobs match the current filter (last 30 days by default).</div>
                            @if(!empty($requestUrl))
                                <div class="small font-monospace mt-1 text-center" style="word-break:break-all;color:#888;">URL hit: {{ $requestUrl }}</div>
                            @endif
                        @endif
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<p class="text-muted small mt-2">
    Data is read live from AvePoint's <code>/backup/m365/cloudbackupjobs</code> endpoint each time this page loads.
    Auto-refresh: <span id="autoRefresh">disabled</span> ·
    <button type="button" class="btn btn-link btn-sm p-0" id="toggleAutoRefresh">enable</button>
</p>

@push('scripts')
<script>
(function () {
    let timer = null;
    const btn  = document.getElementById('toggleAutoRefresh');
    const lbl  = document.getElementById('autoRefresh');
    btn.addEventListener('click', () => {
        if (timer) {
            clearInterval(timer); timer = null;
            lbl.textContent = 'disabled'; btn.textContent = 'enable';
        } else {
            timer = setInterval(() => location.reload(), 30000);
            lbl.textContent = 'every 30s'; btn.textContent = 'disable';
        }
    });
})();
</script>
@endpush

@endsection
