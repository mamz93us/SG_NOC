@extends('layouts.admin')

@section('content')

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <a href="{{ route('admin.avepoint.backups') }}" class="text-muted text-decoration-none">
            <i class="bi bi-arrow-left me-1"></i>All AvePoint backups
        </a>
        <h1 class="h3 mb-0 mt-1">
            Backup #{{ $backup->id }} — {{ $backup->subject_name ?? $backup->subject_upn }}
            <span class="badge {{ $backup->statusBadgeClass() }} ms-2 align-middle">{{ str_replace('_',' ', $backup->status) }}</span>
        </h1>
    </div>
    <div class="btn-group">
        @if($backup->isDownloadable())
            <a href="{{ url('/avepoint/download/' . $backup->download_token) }}" class="btn btn-success">
                <i class="bi bi-download me-1"></i>Download
            </a>
        @endif
        @if(in_array($backup->status, ['failed','pruned']))
            <button type="button" class="btn btn-outline-warning" id="retryBtn">
                <i class="bi bi-arrow-clockwise me-1"></i>Retry
            </button>
        @endif
    </div>
</div>

@if($backup->status === 'manual_upload_required')
    <div class="alert alert-warning mb-3">
        <h6 class="alert-heading"><i class="bi bi-cloud-upload me-1"></i>Manual upload required</h6>
        <p class="mb-2 small">
            AvePoint's public API doesn't expose a programmatic backup-export endpoint, so IT must run the
            export from the AvePoint web UI and upload the file here.
        </p>
        <ol class="small mb-2">
            <li>Log into <a href="https://www.avepointonlineservices.com/" target="_blank" rel="noopener">AvePoint Online Services</a> →
                Cloud Backup for Microsoft 365.</li>
            <li>Find the latest backup for <code>{{ $backup->subject_upn }}</code> ({{ $backup->typeLabel() }}).</li>
            <li>Run <strong>Export</strong> (mailbox → PST, OneDrive → ZIP) and download the file once ready.</li>
            <li>Upload it below — it streams straight into Azure Blob; SHA-256 is computed inline.</li>
        </ol>
        @include('admin.avepoint._upload_form', ['backup' => $backup])
    </div>
@endif

@if(session('success'))<div class="alert alert-success py-2">{{ session('success') }}</div>@endif
@if(session('error'))<div class="alert alert-danger py-2">{{ session('error') }}</div>@endif

<div class="row g-3">
    <div class="col-md-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header fw-semibold bg-white"><i class="bi bi-person me-1"></i>Subject</div>
            <div class="card-body">
                <table class="table table-sm mb-0">
                    <tr><th style="width:160px">Name</th><td>{{ $backup->subject_name ?? '—' }}</td></tr>
                    <tr><th>UPN</th><td class="font-monospace">{{ $backup->subject_upn }}</td></tr>
                    @if($backup->subjectEmployee)
                        <tr><th>Employee</th><td>
                            <a href="{{ route('admin.employees.show', $backup->subjectEmployee) }}">
                                {{ $backup->subjectEmployee->name }}
                            </a>
                        </td></tr>
                    @endif
                    <tr><th>Requested by</th><td>{{ $backup->requestedBy?->name ?? '—' }}</td></tr>
                    @if($backup->notes)
                        <tr><th>Notes</th><td>{{ $backup->notes }}</td></tr>
                    @endif
                </table>
            </div>
        </div>
    </div>

    <div class="col-md-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header fw-semibold bg-white"><i class="bi bi-archive me-1"></i>Backup</div>
            <div class="card-body">
                <table class="table table-sm mb-0">
                    <tr><th style="width:160px">Type</th><td>{{ $backup->typeLabel() }}</td></tr>
                    <tr><th>Source</th><td>{{ $backup->source }}</td></tr>
                    <tr><th>AvePoint Job ID</th><td class="font-monospace small">{{ $backup->avepoint_job_id ?? '—' }}</td></tr>
                    <tr><th>Size</th><td>{{ $backup->humanSize() }}</td></tr>
                    <tr><th>SHA-256</th><td class="font-monospace small">{{ $backup->file_sha256 ?? '—' }}</td></tr>
                    <tr><th>Blob path</th><td class="font-monospace small">{{ $backup->file_path ?? '—' }}</td></tr>
                    @if($backup->download_expires_at)
                        <tr><th>Link expires</th><td>{{ $backup->download_expires_at->format('Y-m-d H:i') }}</td></tr>
                    @endif
                    @if($backup->requester_notified_at)
                        <tr><th>Requester notified</th><td>{{ $backup->requester_notified_at->format('Y-m-d H:i') }}</td></tr>
                    @endif
                </table>
                @if($backup->error_message)
                    <div class="alert alert-danger mt-3 small mb-0">
                        <strong>Error:</strong> {{ $backup->error_message }}
                    </div>
                @endif
            </div>
        </div>
    </div>

    <div class="col-12">
        <div class="card border-0 shadow-sm">
            <div class="card-header fw-semibold bg-white">
                <i class="bi bi-list-check me-1"></i>Download Audit
                <small class="text-muted ms-2">{{ $backup->downloadAudits->count() }} download(s)</small>
            </div>
            <div class="table-responsive">
                <table class="table table-sm mb-0 align-middle">
                    <thead class="table-light"><tr>
                        <th>When</th><th>User</th><th>IP</th><th>User-Agent</th><th class="text-end">Bytes</th><th>Completed</th>
                    </tr></thead>
                    <tbody>
                        @forelse($backup->downloadAudits->sortByDesc('started_at') as $a)
                            <tr>
                                <td class="small">{{ $a->started_at?->format('Y-m-d H:i:s') }}</td>
                                <td>{{ $a->user?->name ?? 'anonymous' }}</td>
                                <td class="small font-monospace">{{ $a->ip }}</td>
                                <td class="small text-muted text-truncate" style="max-width:300px;" title="{{ $a->user_agent }}">{{ $a->user_agent }}</td>
                                <td class="text-end small">{{ number_format($a->bytes_sent ?? 0) }}</td>
                                <td class="small">{{ $a->completed_at?->format('H:i:s') ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="text-center text-muted py-3">No downloads yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
const retryBtn = document.getElementById('retryBtn');
if (retryBtn) {
    retryBtn.addEventListener('click', () => {
        if (! confirm('Re-dispatch this backup request?')) return;
        retryBtn.disabled = true;
        fetch('{{ route('admin.avepoint.backup.retry', $backup) }}', {
            method: 'POST',
            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
        }).then(r => r.json()).then(data => {
            alert(data.message || (data.ok ? 'Retry dispatched.' : 'Retry failed.'));
            if (data.ok) location.reload();
        }).finally(() => retryBtn.disabled = false);
    });
}
</script>
@endpush

@endsection
