@extends('layouts.admin')

@section('content')

<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0"><i class="bi bi-cloud-arrow-down-fill text-info me-2"></i>AvePoint · NOC Backups</h1>
    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#avepointRequestModal">
        <i class="bi bi-play-fill me-1"></i>Request Backup
    </button>
</div>

@include('admin.avepoint._nav')

<form method="GET" class="row g-2 mb-3">
    <div class="col-md-4">
        <input type="text" name="q" value="{{ $q }}" class="form-control form-control-sm" placeholder="Search subject UPN or name…">
    </div>
    <div class="col-md-3">
        <select name="type" class="form-select form-select-sm">
            <option value="">Any type</option>
            <option value="mailbox"  {{ $type==='mailbox' ?'selected':'' }}>Mailbox</option>
            <option value="onedrive" {{ $type==='onedrive'?'selected':'' }}>OneDrive</option>
        </select>
    </div>
    <div class="col-md-3">
        <select name="status" class="form-select form-select-sm">
            <option value="">Any status</option>
            @foreach(['pending','running','uploading','completed','failed','manual_upload_required','pruned'] as $s)
                <option value="{{ $s }}" {{ $status===$s?'selected':'' }}>{{ str_replace('_',' ',$s) }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-md-2">
        <button class="btn btn-outline-secondary btn-sm w-100"><i class="bi bi-funnel me-1"></i>Filter</button>
    </div>
</form>

<div class="card border-0 shadow-sm">
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>#</th>
                    <th>Subject</th>
                    <th>Type</th>
                    <th>Status</th>
                    <th>Size</th>
                    <th>Requested By</th>
                    <th>Created</th>
                    <th>Download</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($rows as $b)
                    <tr>
                        <td><code>#{{ $b->id }}</code></td>
                        <td>
                            <strong>{{ $b->subject_name ?? $b->subject_upn }}</strong><br>
                            <small class="text-muted font-monospace">{{ $b->subject_upn }}</small>
                        </td>
                        <td><span class="badge bg-light text-dark border">{{ $b->type }}</span></td>
                        <td><span class="badge {{ $b->statusBadgeClass() }}">{{ str_replace('_',' ',$b->status) }}</span></td>
                        <td>{{ $b->humanSize() }}</td>
                        <td class="small">{{ $b->requestedBy?->name ?? '—' }}</td>
                        <td class="small text-muted">{{ $b->created_at->diffForHumans() }}</td>
                        <td>
                            @if($b->isDownloadable())
                                <a href="{{ url('/avepoint/download/' . $b->download_token) }}" class="btn btn-sm btn-outline-success">
                                    <i class="bi bi-download me-1"></i>Download
                                </a>
                            @elseif($b->status === 'manual_upload_required')
                                @include('admin.avepoint._upload_form', ['backup' => $b])
                            @elseif($b->status === 'completed')
                                <span class="text-muted small">link expired</span>
                            @else
                                <span class="text-muted small">—</span>
                            @endif
                        </td>
                        <td><a href="{{ route('admin.avepoint.backup.show', $b) }}" class="small">Details →</a></td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="text-center text-muted py-4">No backups match the current filter.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="mt-3">{{ $rows->links() }}</div>

@include('admin.avepoint._request_modal')

@endsection
