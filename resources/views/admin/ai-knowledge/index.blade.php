@extends('layouts.admin')

@section('title', 'AI Assistant Knowledge')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-robot me-2 text-primary"></i>AI Assistant Knowledge</h4>
        <small class="text-muted">
            Articles the AI IT Assistant searches and cites — written here, or imported from PDFs and translated into English.
            Publishing re-indexes automatically — see <a href="{{ route('admin.ai-assistant.usage') }}">Usage &amp; Gaps</a> for what employees ask that this library cannot yet answer.
        </small>
    </div>
    <div class="d-flex gap-2">
        <a href="{{ route('admin.ai-assistant.knowledge-stats') }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-bar-chart me-1"></i>Statistics
        </a>
        <a href="{{ route('admin.ai-assistant.instructions.edit') }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-card-text me-1"></i>Instructions
        </a>
        <form method="POST" action="{{ route('admin.ai-assistant.knowledge.reindex-all') }}" class="d-inline"
              onsubmit="return confirm('Re-chunk and re-embed every published article? This runs in the background.');">
            @csrf
            <button type="submit" class="btn btn-outline-primary btn-sm">
                <i class="bi bi-arrow-repeat me-1"></i>Reindex All
            </button>
        </form>
        <button type="button" class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#importPdfModal">
            <i class="bi bi-file-earmark-arrow-up me-1"></i>Import PDFs
        </button>
        <a href="{{ route('admin.ai-assistant.knowledge.create') }}" class="btn btn-primary btn-sm">
            <i class="bi bi-plus-lg me-1"></i>New Article
        </a>
    </div>
</div>

@if (session('success'))
    <div class="alert alert-success d-flex gap-2 align-items-start">
        <i class="bi bi-check-circle-fill fs-5"></i>
        <div>{{ session('success') }}</div>
    </div>
@endif

@if (session('error'))
    <div class="alert alert-danger d-flex gap-2 align-items-start">
        <i class="bi bi-x-circle-fill fs-5"></i>
        <div>{{ session('error') }}</div>
    </div>
@endif

@if ($errors->any())
    <div class="alert alert-danger d-flex gap-2 align-items-start">
        <i class="bi bi-x-circle-fill fs-5"></i>
        <div>
            Nothing was uploaded:
            <ul class="mb-0 ps-3">
                @foreach ($errors->all() as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        </div>
    </div>
@endif

@if(! $aiSettings->embeddingsConfigured())
    <div class="alert alert-warning d-flex gap-2 align-items-start">
        <i class="bi bi-exclamation-triangle-fill fs-5"></i>
        <div>
            {{ $aiSettings->configurationIssue() ?? 'No embedding deployment is configured.' }}
            Articles will publish but the assistant cannot search them until this is fixed — see
            <a href="{{ route('admin.settings.index') }}#ai-assistant">AI Assistant settings</a>.
            Once fixed, use <strong>Reindex All</strong> to catch up articles saved while it was broken.
        </div>
    </div>
@endif

<div id="reindex-status"
     data-poll-url="{{ route('admin.ai-assistant.knowledge.reindex-status') }}"
     data-running="{{ $aiSettings->reindexRunning() ? '1' : '0' }}"
     class="alert {{ $aiSettings->reindexRunning() ? 'alert-info' : 'alert-secondary' }} d-flex gap-2 align-items-start small"
     @if(! $aiSettings->last_reindex_started_at) hidden @endif>
    <i class="bi bi-arrow-repeat me-1" id="reindex-status-icon"></i>
    <div id="reindex-status-text">
        @if($aiSettings->reindexRunning())
            Reindexing since {{ $aiSettings->last_reindex_started_at->diffForHumans() }} — this banner updates itself when it's done.
        @elseif($aiSettings->last_reindex_finished_at)
            Last reindex finished {{ $aiSettings->last_reindex_finished_at->diffForHumans() }}:
            {{ $aiSettings->last_reindex_result['indexed'] ?? 0 }} indexed,
            {{ $aiSettings->last_reindex_result['failed'] ?? 0 }} failed.
            @if(!empty($aiSettings->last_reindex_result['failed_titles']))
                <span class="text-danger">Failed: {{ implode(', ', $aiSettings->last_reindex_result['failed_titles']) }}</span>
            @endif
            @if(!empty($aiSettings->last_reindex_result['error']))
                <span class="text-danger">Crashed: {{ $aiSettings->last_reindex_result['error'] }}</span>
            @endif
        @endif
    </div>
</div>

@if($imports->isNotEmpty())
    <div class="card shadow-sm border-0 mb-4" id="pdf-imports"
         data-poll-url="{{ route('admin.ai-assistant.knowledge.imports.status') }}">
        <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
            <span class="fw-semibold"><i class="bi bi-file-earmark-pdf me-1 text-danger"></i>PDF imports</span>
            <small class="text-muted">Read page by page and translated into English in the background.</small>
        </div>
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>File</th>
                        <th style="min-width:240px">Progress</th>
                        <th style="min-width:200px">Article</th>
                        <th style="width:100px"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($imports as $import)
                        <tr data-import-id="{{ $import->id }}" data-status="{{ $import->status }}">
                            <td>
                                <a href="{{ route('admin.ai-assistant.knowledge.imports.file', $import) }}" target="_blank" rel="noopener"
                                   class="fw-semibold text-break">{{ $import->file_name }}</a>
                                <div class="small text-muted">
                                    {{ number_format($import->file_size / 1048576, 1) }} MB
                                    @if($import->page_count)
                                        · {{ $import->page_count }} {{ Str::plural('page', $import->page_count) }}
                                    @endif
                                    · {{ $import->created_at->diffForHumans() }}
                                </div>
                            </td>
                            <td>
                                <div class="small {{ $import->status === 'failed' ? 'text-danger fw-semibold' : '' }}" data-role="label">{{ $import->statusLabel() }}</div>
                                @if($import->isActive())
                                    <div class="progress mt-1" style="height:6px" role="progressbar" aria-label="Pages read">
                                        <div class="progress-bar" data-role="bar" style="width: {{ $import->progressPercent() }}%"></div>
                                    </div>
                                @endif
                                <div class="small mt-1 {{ $import->status === 'failed' ? 'text-danger' : 'text-muted' }}"
                                     data-role="error" @if(! $import->error) hidden @endif>{{ $import->error }}</div>
                            </td>
                            <td class="small">
                                @if($import->article)
                                    <a href="{{ route('admin.ai-assistant.knowledge.edit', $import->article) }}" class="fw-semibold">{{ $import->article->title }}</a>
                                    <div class="text-muted">
                                        {{ $import->article->is_published ? 'Published' : 'Draft — check it, then publish' }}
                                        @if($import->sourceLanguageName())
                                            · from {{ $import->sourceLanguageName() }}
                                        @endif
                                    </div>
                                @elseif($import->isActive())
                                    <span class="text-muted">{{ $import->publish ? 'Published when done' : 'Saved as a draft when done' }}</span>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td class="text-end text-nowrap">
                                @if($import->status === 'failed')
                                    <form method="POST" action="{{ route('admin.ai-assistant.knowledge.imports.retry', $import) }}" class="d-inline">
                                        @csrf
                                        <button type="submit" class="btn btn-sm btn-outline-primary" title="Try again from page {{ $import->pages_done + 1 }}">
                                            <i class="bi bi-arrow-clockwise"></i>
                                        </button>
                                    </form>
                                @endif
                                @unless($import->article_id)
                                    <form method="POST" action="{{ route('admin.ai-assistant.knowledge.imports.destroy', $import) }}" class="d-inline"
                                          onsubmit="return confirm('{{ $import->isActive() ? 'Stop and delete' : 'Delete' }} the import of “{{ addslashes($import->file_name) }}”?');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete"><i class="bi bi-trash"></i></button>
                                    </form>
                                @endunless
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif

<div class="card shadow-sm border-0">
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Title</th>
                    <th style="width:140px">Category</th>
                    <th style="min-width:150px">Audience</th>
                    <th style="width:100px" class="text-center">Chunks</th>
                    <th style="width:100px" class="text-center">Status</th>
                    <th style="width:120px"></th>
                </tr>
            </thead>
            <tbody>
                @forelse($articles as $article)
                    <tr>
                        <td>
                            <div class="fw-semibold">
                                {{ $article->title }}
                                @if($article->import)
                                    <a href="{{ route('admin.ai-assistant.knowledge.imports.file', $article->import) }}" target="_blank" rel="noopener"
                                       class="badge text-bg-light border text-decoration-none fw-normal ms-1"
                                       title="Imported from {{ $article->import->file_name }}">
                                        <i class="bi bi-file-earmark-pdf text-danger"></i> PDF
                                    </a>
                                @endif
                            </div>
                            @if($article->title_ar)
                                <div class="small text-muted" dir="rtl">{{ $article->title_ar }}</div>
                            @endif
                        </td>
                        <td><span class="badge bg-secondary">{{ $article->category ?: '—' }}</span></td>
                        <td class="small">
                            @if($article->audience === 'branch')
                                Branch: {{ $article->branch?->name ?? '—' }}
                            @elseif($article->audience === 'department')
                                Dept: {{ $article->department?->name ?? '—' }}
                            @else
                                Everyone
                            @endif
                        </td>
                        <td class="text-center small text-muted">{{ $article->chunks_count }}</td>
                        <td class="text-center">
                            @if($article->is_published && (int) $article->chunks_count === 0)
                                <span class="badge bg-danger" title="Published but not searchable — embedding never succeeded. Try Reindex All.">
                                    <i class="bi bi-exclamation-triangle-fill me-1"></i>Not indexed
                                </span>
                            @elseif($article->is_published)
                                <span class="badge bg-success">Live</span>
                            @else
                                <span class="badge bg-secondary">Draft</span>
                            @endif
                        </td>
                        <td class="text-end">
                            <a href="{{ route('admin.ai-assistant.knowledge.edit', $article) }}" class="btn btn-sm btn-outline-secondary">
                                <i class="bi bi-pencil"></i>
                            </a>
                            <form method="POST" action="{{ route('admin.ai-assistant.knowledge.destroy', $article) }}"
                                  class="d-inline"
                                  onsubmit="return confirm('Delete “{{ addslashes($article->title) }}”?{{ $article->import ? ' Its imported PDF is deleted too.' : '' }}');">
                                @csrf
                                @method('DELETE')
                                <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="text-center text-muted py-5">
                            <i class="bi bi-robot fs-3 d-block mb-2"></i>
                            No articles yet — the assistant has nothing to search until you publish some.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

@if($articles->hasPages())
    <div class="mt-3">{{ $articles->links() }}</div>
@endif

<div class="modal fade" id="importPdfModal" tabindex="-1" aria-labelledby="importPdfModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <form method="POST" action="{{ route('admin.ai-assistant.knowledge.imports.store') }}" enctype="multipart/form-data"
              class="modal-content" id="importPdfForm">
            @csrf
            <div class="modal-header">
                <h5 class="modal-title" id="importPdfModalLabel">
                    <i class="bi bi-file-earmark-pdf me-2 text-danger"></i>Import PDFs
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="small text-muted">
                    Each page is read from an image of the page, so scanned documents work too, and translated into English.
                    Every PDF becomes one article: the English translation, plus the original text when the document is in Arabic,
                    so employees can ask in either language. It runs in the background, and this page shows the progress.
                </p>

                <div class="mb-3">
                    <label for="importFiles" class="form-label fw-semibold">PDF files <span class="text-danger">*</span></label>
                    <input type="file" id="importFiles" name="files[]" class="form-control" accept="application/pdf,.pdf" multiple required>
                    <div class="form-text">Up to 20 at a time; 50 MB and 300 pages each.</div>
                </div>

                <div class="row g-3">
                    <div class="col-md-6">
                        <label for="importCategory" class="form-label fw-semibold">Category</label>
                        <input type="text" id="importCategory" name="category" maxlength="50" class="form-control"
                               value="{{ old('category') }}" placeholder="e.g. hr-policy, payroll, vpn">
                    </div>
                    <div class="col-md-6">
                        <label for="importAudience" class="form-label fw-semibold">Audience</label>
                        <select name="audience" id="importAudience" class="form-select">
                            <option value="all" @selected(old('audience', 'all') === 'all')>Everyone</option>
                            <option value="branch" @selected(old('audience') === 'branch')>One branch</option>
                            <option value="department" @selected(old('audience') === 'department')>One department</option>
                        </select>
                    </div>
                    <div class="col-md-6" id="importBranchWrap" hidden>
                        <label for="importBranch" class="form-label fw-semibold">Branch</label>
                        <select name="audience_branch_id" id="importBranch" class="form-select">
                            <option value="">Choose…</option>
                            @foreach($branches as $branch)
                                <option value="{{ $branch->id }}" @selected((string) old('audience_branch_id') === (string) $branch->id)>{{ $branch->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-6" id="importDeptWrap" hidden>
                        <label for="importDept" class="form-label fw-semibold">Department</label>
                        <select name="audience_department_id" id="importDept" class="form-select">
                            <option value="">Choose…</option>
                            @foreach($departments as $dept)
                                <option value="{{ $dept->id }}" @selected((string) old('audience_department_id') === (string) $dept->id)>{{ $dept->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div class="form-check form-switch mt-3">
                    <input class="form-check-input" type="checkbox" role="switch" id="importPublish" name="publish" value="1" @checked(old('publish'))>
                    <label class="form-check-label fw-semibold" for="importPublish">Publish as soon as it is translated</label>
                    <div class="form-text">
                        Left off, each article waits as a draft for someone to check it against the PDF — worth doing for a policy,
                        because employees will be given answers from the translation.
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-upload me-1"></i>Upload and import
                </button>
            </div>
        </form>
    </div>
</div>

@push('scripts')
<script>
(function () {
    const banner = document.getElementById('reindex-status');
    if (!banner || banner.dataset.running !== '1') {
        return; // nothing to poll — already finished (or never run) as of page load
    }

    const text = document.getElementById('reindex-status-text');
    const url = banner.dataset.pollUrl;

    const poll = setInterval(function () {
        fetch(url, { headers: { 'Accept': 'application/json' } })
            .then(r => r.json())
            .then(function (data) {
                if (data.running) {
                    return; // still going — check again next tick
                }

                clearInterval(poll);

                const result = data.result || {};
                const failed = result.failed || 0;
                text.textContent = 'Finished — ' + (result.indexed || 0) + ' indexed, ' + failed + ' failed. Reloading…';
                banner.classList.remove('alert-info');
                banner.classList.add(failed > 0 || result.error ? 'alert-danger' : 'alert-success');

                setTimeout(() => window.location.reload(), 1200);
            })
            .catch(function () {
                clearInterval(poll); // don't hammer a failing endpoint forever
            });
    }, 5000);
})();

(function () {
    const select = document.getElementById('importAudience');
    const branchWrap = document.getElementById('importBranchWrap');
    const deptWrap = document.getElementById('importDeptWrap');

    function sync() {
        branchWrap.hidden = select.value !== 'branch';
        deptWrap.hidden = select.value !== 'department';
    }
    select.addEventListener('change', sync);
    sync();

    // A 50 MB upload takes a while, and a second click would only send the files again.
    const form = document.getElementById('importPdfForm');
    form.addEventListener('submit', function () {
        const button = form.querySelector('button[type="submit"]');
        button.disabled = true;
        button.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Uploading…';
    });
})();

(function () {
    const card = document.getElementById('pdf-imports');
    if (!card) {
        return;
    }

    const rows = Array.from(card.querySelectorAll('tr[data-import-id]'))
        .filter(row => row.dataset.status === 'queued' || row.dataset.status === 'processing');
    if (rows.length === 0) {
        return; // nothing still being read
    }

    const url = card.dataset.pollUrl + '?ids=' + rows.map(row => row.dataset.importId).join(',');

    const poll = setInterval(function () {
        fetch(url, { headers: { 'Accept': 'application/json' } })
            .then(r => r.json())
            .then(function (data) {
                const imports = data.imports || {};
                let finished = false;

                rows.forEach(function (row) {
                    const state = imports[row.dataset.importId];

                    // Done, failed or deleted: the row needs the server's version (article link, retry button).
                    if (!state || state.status === 'done' || state.status === 'failed') {
                        finished = true;
                        return;
                    }

                    row.dataset.status = state.status;
                    row.querySelector('[data-role="label"]').textContent = state.label;
                    row.querySelector('[data-role="bar"]').style.width = state.percent + '%';

                    const error = row.querySelector('[data-role="error"]');
                    error.textContent = state.error || '';
                    error.hidden = !state.error;
                });

                // Not while the upload dialog is open: reloading would throw away the files chosen in it.
                if (finished && !document.querySelector('.modal.show')) {
                    clearInterval(poll);
                    window.location.reload();
                }
            })
            .catch(function () {
                clearInterval(poll); // don't hammer a failing endpoint forever
            });
    }, 5000);
})();
</script>
@endpush
@endsection
