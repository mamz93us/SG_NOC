@extends('layouts.admin')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0"><i class="bi bi-cloud-arrow-up-fill me-2"></i>Download Center</h1>
    <form method="GET" action="{{ route('admin.downloads.index') }}" class="d-flex" style="max-width:320px;">
        <input type="text" name="search" class="form-control form-control-sm me-2"
               placeholder="Search files…" value="{{ request('search') }}">
        <button type="submit" class="btn btn-sm btn-outline-primary">Search</button>
    </form>
</div>

@can('manage-downloads')
<div class="row g-3 mb-4">
    {{-- Direct upload --}}
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-header fw-semibold"><i class="bi bi-upload me-2"></i>Upload a file</div>
            <div class="card-body">
                <form id="uploadForm" action="{{ route('admin.downloads.store') }}"
                      method="POST" enctype="multipart/form-data">
                    @csrf
                    <div class="mb-2">
                        <input type="text" name="title" class="form-control" placeholder="Title (optional)">
                    </div>
                    <div class="mb-2">
                        <input type="file" name="file" id="uploadFile" class="form-control" required>
                    </div>
                    <div class="progress mb-2 d-none" id="uploadProgressWrap" style="height:20px;">
                        <div id="uploadProgressBar" class="progress-bar progress-bar-striped progress-bar-animated"
                             role="progressbar" style="width:0%;">0%</div>
                    </div>
                    <div id="uploadError" class="text-danger small mb-2"></div>
                    <button type="submit" class="btn btn-primary" id="uploadBtn">
                        <i class="bi bi-cloud-upload me-1"></i>Upload to Azure
                    </button>
                </form>
            </div>
        </div>
    </div>

    {{-- Fetch from URL --}}
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-header fw-semibold"><i class="bi bi-link-45deg me-2"></i>Fetch from a URL</div>
            <div class="card-body">
                <form action="{{ route('admin.downloads.store-url') }}" method="POST">
                    @csrf
                    <div class="mb-2">
                        <input type="text" name="title" class="form-control" placeholder="Title (optional)">
                    </div>
                    <div class="mb-2">
                        <input type="url" name="source_url" class="form-control"
                               placeholder="https://example.com/file.zip" required>
                    </div>
                    <div class="form-text mb-2">The NOC downloads it server-side and stores it in Azure.</div>
                    <button type="submit" class="btn btn-outline-primary">
                        <i class="bi bi-cloud-arrow-down me-1"></i>Queue fetch
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
@endcan

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Title</th>
                    <th>Source</th>
                    <th>Size</th>
                    <th>Status</th>
                    <th>Public link</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($files as $f)
                <tr data-id="{{ $f->id }}" data-status-url="{{ route('admin.downloads.status', $f) }}">
                    <td>
                        <div class="fw-semibold">{{ $f->title }}</div>
                        <div class="small text-muted">{{ $f->original_filename }}</div>
                    </td>
                    <td>
                        @if($f->source === \App\Models\DownloadFile::SOURCE_URL)
                            <span class="badge bg-info-subtle text-info-emphasis">URL</span>
                        @else
                            <span class="badge bg-secondary-subtle text-secondary-emphasis">Upload</span>
                        @endif
                    </td>
                    <td class="js-size">{{ $f->humanSize() }}</td>
                    <td>
                        <span class="js-status-badge">@include('admin.downloads._status', ['f' => $f])</span>
                    </td>
                    <td style="min-width:240px;">
                        <span class="js-public">@include('admin.downloads._public', ['f' => $f])</span>
                    </td>
                    <td class="text-end text-nowrap">
                        @if($f->isStored())
                        <a href="{{ route('admin.downloads.download', $f) }}" class="btn btn-sm btn-outline-primary" title="Download">
                            <i class="bi bi-download"></i>
                        </a>
                        @endif
                        @can('manage-downloads')
                        <form action="{{ route('admin.downloads.destroy', $f) }}" method="POST" class="d-inline"
                              onsubmit="return confirm('Delete “{{ $f->title }}” and its file in Azure?');">
                            @csrf @method('DELETE')
                            <button class="btn btn-sm btn-outline-danger" title="Delete"><i class="bi bi-trash"></i></button>
                        </form>
                        @endcan
                    </td>
                </tr>
                @empty
                <tr><td colspan="6" class="text-center text-muted py-4">No files yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="mt-3">{{ $files->links('pagination::bootstrap-5') }}</div>
@endsection

@push('scripts')
<script>
(function () {
    // ── Upload with a live progress bar (XHR so we can read upload.onprogress) ──
    const form = document.getElementById('uploadForm');
    if (form) {
        form.addEventListener('submit', function (e) {
            const fileInput = document.getElementById('uploadFile');
            if (!fileInput.files.length) return; // let native validation handle it
            e.preventDefault();

            const wrap = document.getElementById('uploadProgressWrap');
            const bar = document.getElementById('uploadProgressBar');
            const err = document.getElementById('uploadError');
            const btn = document.getElementById('uploadBtn');
            err.textContent = '';
            wrap.classList.remove('d-none');
            btn.disabled = true;

            const xhr = new XMLHttpRequest();
            xhr.open('POST', form.action, true);
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.upload.onprogress = function (ev) {
                if (ev.lengthComputable) {
                    const pct = Math.round((ev.loaded / ev.total) * 100);
                    bar.style.width = pct + '%';
                    bar.textContent = pct + '%';
                }
            };
            xhr.onload = function () {
                if (xhr.status >= 200 && xhr.status < 300) {
                    window.location.reload();
                } else {
                    let msg = 'Upload failed.';
                    try { msg = JSON.parse(xhr.responseText).message || msg; } catch (_) {}
                    err.textContent = msg;
                    btn.disabled = false;
                    wrap.classList.add('d-none');
                }
            };
            xhr.onerror = function () {
                err.textContent = 'Network error during upload.';
                btn.disabled = false;
                wrap.classList.add('d-none');
            };
            const fd = new FormData(form);
            xhr.send(fd);
        });
    }

    // ── Poll status for any row still ingesting (pending/fetching) ──
    function pollRow(tr) {
        fetch(tr.dataset.statusUrl, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(r => r.json())
            .then(d => {
                const badge = tr.querySelector('.js-status-badge');
                if (badge) badge.innerHTML = renderBadge(d.status, d.error);
                const size = tr.querySelector('.js-size');
                if (size && d.human_size) size.textContent = d.human_size;
                if (d.status === 'stored' || d.status === 'failed') {
                    // Reload once to pick up the download button / share controls.
                    window.location.reload();
                }
            })
            .catch(() => {});
    }
    function renderBadge(status, error) {
        const map = {
            pending:  '<span class="badge bg-warning-subtle text-warning-emphasis">Pending</span>',
            fetching: '<span class="badge bg-primary-subtle text-primary-emphasis">Fetching…</span>',
            stored:   '<span class="badge bg-success-subtle text-success-emphasis">Stored</span>',
            failed:   '<span class="badge bg-danger-subtle text-danger-emphasis" title="' + (error || '') + '">Failed</span>',
        };
        return map[status] || status;
    }
    const pending = Array.from(document.querySelectorAll('tr[data-status-url]'))
        .filter(tr => {
            const t = (tr.querySelector('.js-status-badge')||{}).textContent || '';
            return /Pending|Fetching/i.test(t);
        });
    if (pending.length) {
        setInterval(() => pending.forEach(pollRow), 3000);
    }
})();
</script>
@endpush
