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
            <div class="card shadow-sm h-100">
                <div class="card-header fw-semibold">
                    <i class="bi bi-upload me-1"></i> Upload Report / Document
                </div>
                <div class="card-body">
                    <form action="{{ route('admin.documentation.store') }}" method="POST"
                          enctype="multipart/form-data">
                        @csrf

                        <div class="mb-3">
                            <label for="doc_title" class="form-label">
                                Display Title <small class="text-muted">(optional)</small>
                            </label>
                            <input type="text" name="title" id="doc_title"
                                   class="form-control @error('title') is-invalid @enderror"
                                   placeholder="e.g. Network Audit Q1 2026"
                                   value="{{ old('title') }}">
                            @error('title')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            <div class="form-text">If blank, the original filename is used.</div>
                        </div>

                        <div class="mb-3">
                            <label for="doc_file" class="form-label">
                                HTML File <span class="text-danger">*</span>
                            </label>
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
                                <p class="text-muted small">Use the upload form to add your first report.</p>
                            @endcan
                        </div>
                    @else
                        <table class="table table-hover table-sm mb-0" id="docsTable">
                            <thead class="table-light">
                                <tr>
                                    <th>Filename</th>
                                    <th class="text-end">Size</th>
                                    <th>Last Modified</th>
                                    <th class="text-center" style="width:160px">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($files as $doc)
                                <tr class="doc-row">
                                    <td>
                                        <i class="bi bi-file-earmark-code text-primary me-1"></i>
                                        <a href="{{ route('admin.documentation.show', $doc['name']) }}"
                                           class="doc-name">{{ $doc['name'] }}</a>
                                        @if($doc['is_public'])
                                            <span class="badge bg-success ms-1">Public</span>
                                        @endif
                                    </td>
                                    <td class="text-end text-muted small">
                                        {{ number_format($doc['size'] / 1024, 1) }} KB
                                    </td>
                                    <td class="text-muted small">
                                        {{ \Carbon\Carbon::createFromTimestamp($doc['modified'])->diffForHumans() }}
                                    </td>
                                    <td class="text-center">
                                        {{-- View (iframe) --}}
                                        <a href="{{ route('admin.documentation.show', $doc['name']) }}"
                                           class="btn btn-sm btn-outline-primary" title="View">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                        {{-- Open raw in new tab --}}
                                        <a href="{{ route('admin.documentation.raw', $doc['name']) }}"
                                           target="_blank"
                                           class="btn btn-sm btn-outline-secondary" title="Open in new tab">
                                            <i class="bi bi-box-arrow-up-right"></i>
                                        </a>
                                        @can('manage-documentation')
                                        {{-- Toggle public --}}
                                        <form action="{{ route('admin.documentation.toggle-public', $doc['name']) }}"
                                              method="POST" class="d-inline">
                                            @csrf
                                            <button type="submit"
                                                    class="btn btn-sm {{ $doc['is_public'] ? 'btn-success' : 'btn-outline-success' }}"
                                                    title="{{ $doc['is_public'] ? 'Make Private' : 'Make Public' }}">
                                                <i class="bi bi-globe"></i>
                                            </button>
                                        </form>
                                        {{-- Delete --}}
                                        <form action="{{ route('admin.documentation.destroy', $doc['name']) }}"
                                              method="POST" class="d-inline"
                                              onsubmit="return confirm('Delete {{ $doc['name'] }}?')">
                                            @csrf @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                        @endcan
                                    </td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                </div>
            </div>
        </div>

    </div>{{-- /row --}}
</div>
@endsection

@push('scripts')
<script>
document.getElementById('docSearch')?.addEventListener('input', function () {
    const q = this.value.toLowerCase();
    document.querySelectorAll('#docsTable .doc-row').forEach(row => {
        const name = row.querySelector('.doc-name')?.textContent.toLowerCase() ?? '';
        row.style.display = name.includes(q) ? '' : 'none';
    });
});
</script>
@endpush
