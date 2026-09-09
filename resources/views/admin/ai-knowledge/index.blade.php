@extends('layouts.admin')

@section('title', 'AI Assistant Knowledge')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-robot me-2 text-primary"></i>AI Assistant Knowledge</h4>
        <small class="text-muted">
            Articles the AI IT Assistant searches and cites. Publishing re-indexes automatically —
            see <a href="{{ route('admin.ai-assistant.usage') }}">Usage &amp; Gaps</a> for what employees ask that this library cannot yet answer.
        </small>
    </div>
    <div class="d-flex gap-2">
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
                            <div class="fw-semibold">{{ $article->title }}</div>
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
                                  onsubmit="return confirm('Delete “{{ addslashes($article->title) }}”?');">
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
</script>
@endpush
@endsection
