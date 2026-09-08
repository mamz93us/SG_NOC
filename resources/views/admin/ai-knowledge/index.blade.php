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
    <a href="{{ route('admin.ai-assistant.knowledge.create') }}" class="btn btn-primary btn-sm">
        <i class="bi bi-plus-lg me-1"></i>New Article
    </a>
</div>

@if (session('success'))
    <div class="alert alert-success d-flex gap-2 align-items-start">
        <i class="bi bi-check-circle-fill fs-5"></i>
        <div>{{ session('success') }}</div>
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
                            @if($article->is_published)
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
@endsection
