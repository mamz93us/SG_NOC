@extends('layouts.admin')

@section('title', 'Documentation')

@section('content')
<div class="container-fluid py-4">

    {{-- ── Page Header ────────────────────────────────────────────── --}}
    <div class="d-flex align-items-center justify-content-between mb-4">
        <h4 class="fw-bold mb-0">
            <i class="bi bi-book-fill me-2 text-primary"></i>Documentation
        </h4>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="{{ route('admin.dashboard') }}">Dashboard</a></li>
                <li class="breadcrumb-item active">Documentation</li>
            </ol>
        </nav>
    </div>

    {{-- ── Flash Messages ──────────────────────────────────────── --}}
    @if(session('success'))
        <div class="alert alert-success alert-dismissible fade show">
            <i class="bi bi-check-circle me-1"></i> {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif
    @if(session('error'))
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="bi bi-exclamation-circle me-1"></i> {{ session('error') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    <div class="row g-4">

        {{-- ── Upload Card ─────────────────────────────────────────── --}}
        @can('manage-documentation')
        <div class="col-lg-4 col-md-5">
            <div class="card shadow-sm">
                <div class="card-header fw-semibold">
                    <i class="bi bi-upload me-1"></i> Upload Document
                </div>
                <div class="card-body">
                    <form action="{{ route('admin.documentation.store') }}" method="POST"
                          enctype="multipart/form-data">
                        @csrf

                        <div class="mb-3">
                            <label for="doc_title" class="form-label fw-semibold">Display Title <span class="text-danger">*</span></label>
                            <input type="text" name="title" id="doc_title"
                                   class="form-control @error('title') is-invalid @enderror"
                                   placeholder="e.g. Network Architecture Report"
                                   value="{{ old('title') }}" required>
                            @error('title')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            <div class="form-text">Shown to users instead of the filename.</div>
                        </div>

                        <div class="mb-3">
                            <label for="doc_desc" class="form-label fw-semibold">Description</label>
                            <textarea name="description" id="doc_desc" rows="3"
                                      class="form-control @error('description') is-invalid @enderror"
                                      placeholder="Brief description of what this document covers…">{{ old('description') }}</textarea>
                            @error('description')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="mb-3">
                            <label for="doc_file" class="form-label fw-semibold">HTML File <span class="text-danger">*</span></label>
                            <input type="file" name="file" id="doc_file" accept=".html,.htm"
                                   class="form-control @error('file') is-invalid @enderror"
                                   required>
                            @error('file')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            <div class="form-text">Maximum 10 MB · HTML / HTM only</div>
                        </div>

                        <button type="submit" class="btn btn-primary w-100">
                            <i class="bi bi-upload me-1"></i> Upload
                        </button>
                    </form>
                </div>
            </div>
        </div>
        @endcan

        {{-- ── Files List ───────────────────────────────────────────── --}}
        <div class="col-lg-8 col-md-7">
            <div class="card shadow-sm">
                <div class="card-header d-flex align-items-center justify-content-between">
                    <span class="fw-semibold">
                        <i class="bi bi-folder2-open me-1"></i>
                        Available Documents
                        <span class="badge bg-secondary ms-1">{{ $files->count() }}</span>
                    </span>
                    <input type="text" id="docSearch" class="form-control form-control-sm"
                           placeholder="Search…" style="width:180px">
                </div>
                <div class="card-body p-0">
                    @if($files->isEmpty())
                        <div class="text-center py-5 text-muted">
                            <i class="bi bi-folder2-open" style="font-size:3rem"></i>
                            <p class="mt-3 mb-0">No documents uploaded yet.</p>
                            @can('manage-documentation')
                                <p class="text-muted small">Use the upload form to add your first document.</p>
                            @endcan
                        </div>
                    @else
                        <div class="list-group list-group-flush" id="docsList">
                            @foreach($files as $doc)
                            <div class="list-group-item doc-row px-3 py-3">
                                <div class="d-flex align-items-start gap-3">
                                    <i class="bi bi-file-earmark-code text-primary mt-1" style="font-size:1.4rem;flex-shrink:0"></i>
                                    <div class="flex-grow-1 min-width-0">
                                        {{-- Title + badges --}}
                                        <div class="d-flex align-items-center gap-2 flex-wrap">
                                            <a href="{{ route('admin.documentation.show', $doc['name']) }}"
                                               class="fw-semibold text-decoration-none doc-title">
                                                {{ $doc['title'] ?: $doc['name'] }}
                                            </a>
                                            @if($doc['is_public'])
                                                <span class="badge bg-success">Public</span>
                                            @else
                                                <span class="badge bg-secondary">Private</span>
                                            @endif
                                        </div>
                                        {{-- Description --}}
                                        @if($doc['description'])
                                        <p class="text-muted small mb-1 doc-desc">{{ $doc['description'] }}</p>
                                        @endif
                                        {{-- File info --}}
                                        <small class="text-muted">
                                            {{ $doc['name'] }} &middot;
                                            {{ number_format($doc['size'] / 1024, 1) }} KB &middot;
                                            {{ \Carbon\Carbon::createFromTimestamp($doc['modified'])->diffForHumans() }}
                                        </small>
                                    </div>
                                    {{-- Actions --}}
                                    <div class="d-flex gap-1 flex-shrink-0">
                                        <a href="{{ route('admin.documentation.show', $doc['name']) }}"
                                           class="btn btn-sm btn-outline-primary" title="View">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                        <a href="{{ route('admin.documentation.raw', $doc['name']) }}"
                                           target="_blank"
                                           class="btn btn-sm btn-outline-secondary" title="Open in new tab">
                                            <i class="bi bi-box-arrow-up-right"></i>
                                        </a>
                                        @can('manage-documentation')
                                        <button type="button"
                                                class="btn btn-sm btn-outline-info btn-edit-meta"
                                                title="Edit title & description"
                                                data-filename="{{ $doc['name'] }}"
                                                data-title="{{ $doc['title'] }}"
                                                data-description="{{ $doc['description'] }}"
                                                data-bs-toggle="modal" data-bs-target="#editMetaModal">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <form action="{{ route('admin.documentation.toggle-public', $doc['name']) }}"
                                              method="POST" class="d-inline">
                                            @csrf
                                            <button type="submit"
                                                    class="btn btn-sm {{ $doc['is_public'] ? 'btn-success' : 'btn-outline-success' }}"
                                                    title="{{ $doc['is_public'] ? 'Make Private' : 'Make Public' }}">
                                                <i class="bi bi-globe"></i>
                                            </button>
                                        </form>
                                        <form action="{{ route('admin.documentation.destroy', $doc['name']) }}"
                                              method="POST" class="d-inline"
                                              onsubmit="return confirm('Delete {{ addslashes($doc['name']) }}?')">
                                            @csrf @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                        @endcan
                                    </div>
                                </div>
                            </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        </div>

    </div>{{-- /row --}}
</div>

{{-- ── Edit Meta Modal ─────────────────────────────────────────────── --}}
@can('manage-documentation')
<div class="modal fade" id="editMetaModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="editMetaForm" method="POST">
                @csrf
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Edit Document Info</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Display Title <span class="text-danger">*</span></label>
                        <input type="text" name="title" id="editTitle" class="form-control" required maxlength="120">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Description</label>
                        <textarea name="description" id="editDescription" class="form-control" rows="3" maxlength="500"
                                  placeholder="Brief description of what this document covers…"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg me-1"></i>Save
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
@endcan

@endsection

@push('scripts')
<script>
// Live search across title, description, filename
document.getElementById('docSearch')?.addEventListener('input', function () {
    const q = this.value.toLowerCase();
    document.querySelectorAll('#docsList .doc-row').forEach(row => {
        const title = row.querySelector('.doc-title')?.textContent.toLowerCase() ?? '';
        const desc  = row.querySelector('.doc-desc')?.textContent.toLowerCase()  ?? '';
        row.style.display = (title + desc).includes(q) ? '' : 'none';
    });
});

// Populate edit modal
document.querySelectorAll('.btn-edit-meta').forEach(btn => {
    btn.addEventListener('click', function () {
        const filename = this.dataset.filename;
        document.getElementById('editMetaForm').action = `/admin/documentation/${encodeURIComponent(filename)}/meta`;
        document.getElementById('editTitle').value       = this.dataset.title       ?? '';
        document.getElementById('editDescription').value = this.dataset.description ?? '';
    });
});
</script>
@endpush
