@extends('layouts.admin')

@section('title', 'Documentation')

@section('content')
<div class="content-wrapper">

    {{-- ── Page Header ────────────────────────────────────────────── --}}
    <div class="content-header">
        <div class="container-fluid">
            <div class="row mb-2 align-items-center">
                <div class="col-sm-6">
                    <h1 class="m-0">
                        <i class="fas fa-book-open mr-2 text-primary"></i>Documentation
                    </h1>
                </div>
                <div class="col-sm-6">
                    <ol class="breadcrumb float-sm-right">
                        <li class="breadcrumb-item"><a href="{{ route('admin.dashboard') }}">Dashboard</a></li>
                        <li class="breadcrumb-item active">Documentation</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>

    <section class="content">
        <div class="container-fluid">

            {{-- ── Flash Messages ──────────────────────────────────────── --}}
            @if(session('success'))
                <div class="alert alert-success alert-dismissible fade show">
                    <i class="fas fa-check-circle mr-1"></i> {{ session('success') }}
                    <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
                </div>
            @endif
            @if(session('error'))
                <div class="alert alert-danger alert-dismissible fade show">
                    <i class="fas fa-exclamation-circle mr-1"></i> {{ session('error') }}
                    <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
                </div>
            @endif

            <div class="row">

                {{-- ── Upload Card ─────────────────────────────────────────── --}}
                @can('manage-documentation')
                <div class="col-lg-4 col-md-5 mb-4">
                    <div class="card card-outline card-primary shadow-sm">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-upload mr-1"></i> Upload Report / Document</h3>
                        </div>
                        <div class="card-body">
                            <form action="{{ route('admin.documentation.store') }}" method="POST"
                                  enctype="multipart/form-data" id="uploadForm">
                                @csrf

                                <div class="form-group">
                                    <label for="doc_title">Display Title <small class="text-muted">(optional)</small></label>
                                    <input type="text" name="title" id="doc_title"
                                           class="form-control @error('title') is-invalid @enderror"
                                           placeholder="e.g. Network Audit Q1 2026"
                                           value="{{ old('title') }}">
                                    @error('title')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                    <small class="form-text text-muted">If blank, the original filename is used.</small>
                                </div>

                                <div class="form-group">
                                    <label for="doc_file">HTML File <span class="text-danger">*</span></label>
                                    <div class="custom-file">
                                        <input type="file" name="file" id="doc_file" accept=".html,.htm"
                                               class="custom-file-input @error('file') is-invalid @enderror"
                                               required>
                                        <label class="custom-file-label" for="doc_file">Choose .html file&hellip;</label>
                                    </div>
                                    @error('file')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                                    <small class="form-text text-muted">Maximum 10 MB · HTML / HTM only</small>
                                </div>

                                <button type="submit" class="btn btn-primary btn-block" id="uploadBtn">
                                    <i class="fas fa-upload mr-1"></i> Upload
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
                @endcan

                {{-- ── Files List ───────────────────────────────────────────── --}}
                <div class="col-lg-8 col-md-7">
                    <div class="card card-outline card-secondary shadow-sm">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="fas fa-folder-open mr-1"></i>
                                Available Documents
                                <span class="badge badge-secondary ml-1">{{ $files->count() }}</span>
                            </h3>
                            <div class="card-tools">
                                <input type="text" id="docSearch" class="form-control form-control-sm"
                                       placeholder="Search&hellip;" style="width:180px;display:inline-block">
                            </div>
                        </div>
                        <div class="card-body p-0">
                            @if($files->isEmpty())
                                <div class="text-center py-5 text-muted">
                                    <i class="fas fa-folder-open fa-3x mb-3 d-block"></i>
                                    No documents uploaded yet.
                                    @can('manage-documentation')
                                        <br>Use the upload form to add your first report.
                                    @endcan
                                </div>
                            @else
                                <table class="table table-hover table-sm mb-0" id="docsTable">
                                    <thead class="thead-light">
                                        <tr>
                                            <th>Filename</th>
                                            <th class="text-right">Size</th>
                                            <th>Last Modified</th>
                                            <th class="text-center" style="width:130px">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($files as $doc)
                                        <tr class="doc-row">
                                            <td>
                                                <i class="fas fa-file-code text-primary mr-1"></i>
                                                <a href="{{ route('admin.documentation.show', $doc['name']) }}"
                                                   class="doc-name">{{ $doc['name'] }}</a>
                                            </td>
                                            <td class="text-right text-muted small">
                                                {{ number_format($doc['size'] / 1024, 1) }} KB
                                            </td>
                                            <td class="text-muted small">
                                                {{ \Carbon\Carbon::createFromTimestamp($doc['modified'])->diffForHumans() }}
                                            </td>
                                            <td class="text-center">
                                                {{-- View (iframe) --}}
                                                <a href="{{ route('admin.documentation.show', $doc['name']) }}"
                                                   class="btn btn-xs btn-outline-primary" title="View">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                                {{-- Open raw in new tab --}}
                                                <a href="{{ route('admin.documentation.raw', $doc['name']) }}"
                                                   target="_blank"
                                                   class="btn btn-xs btn-outline-secondary" title="Open in new tab">
                                                    <i class="fas fa-external-link-alt"></i>
                                                </a>
                                                {{-- Delete --}}
                                                @can('manage-documentation')
                                                <form action="{{ route('admin.documentation.destroy', $doc['name']) }}"
                                                      method="POST" class="d-inline"
                                                      onsubmit="return confirm('Delete {{ $doc['name'] }}?')">
                                                    @csrf @method('DELETE')
                                                    <button type="submit" class="btn btn-xs btn-outline-danger" title="Delete">
                                                        <i class="fas fa-trash"></i>
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
    </section>
</div>
@endsection

@push('scripts')
<script>
// Custom-file input label
document.getElementById('doc_file')?.addEventListener('change', function () {
    const label = this.nextElementSibling;
    label.textContent = this.files.length ? this.files[0].name : 'Choose .html file\u2026';
});

// Live search
document.getElementById('docSearch')?.addEventListener('input', function () {
    const q = this.value.toLowerCase();
    document.querySelectorAll('#docsTable .doc-row').forEach(row => {
        const name = row.querySelector('.doc-name')?.textContent.toLowerCase() ?? '';
        row.style.display = name.includes(q) ? '' : 'none';
    });
});
</script>
@endpush
