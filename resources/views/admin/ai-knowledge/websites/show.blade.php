@extends('layouts.admin')

@section('title', $source->name)

@section('content')
@php
    $pageBadges = [
        'queued' => ['Waiting', 'text-bg-light border'],
        'indexed' => ['Read', 'text-bg-success'],
        'unchanged' => ['Unchanged', 'text-bg-success'],
        'skipped' => ['Skipped', 'text-bg-warning'],
        'failed' => ['Failed', 'text-bg-danger'],
        'gone' => ['Gone', 'text-bg-secondary'],
    ];
    $reading = in_array($source->status, ['queued', 'crawling'], true);
@endphp

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-globe2 me-2 text-primary"></i>{{ $source->name }}</h4>
        <small class="text-muted">
            <a href="{{ $source->start_url }}" target="_blank" rel="noopener noreferrer" class="text-break">{{ $source->start_url }}</a>
            · following {{ $source->scope_url }}
        </small>
    </div>
    <div class="d-flex gap-2">
        <form method="POST" action="{{ route('admin.ai-assistant.knowledge.websites.crawl', $source) }}">
            @csrf
            <button type="submit" class="btn btn-primary btn-sm" @disabled($source->status === 'crawling')>
                <i class="bi bi-arrow-clockwise me-1"></i>Read now
            </button>
        </form>
        <a href="{{ route('admin.ai-assistant.knowledge.websites.index') }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Websites
        </a>
    </div>
</div>

@if (session('success'))
    <div class="alert alert-success d-flex gap-2 align-items-start">
        <i class="bi bi-check-circle-fill fs-5"></i>
        <div>{{ session('success') }}</div>
    </div>
@endif

@if ($errors->any())
    <div class="alert alert-danger d-flex gap-2 align-items-start">
        <i class="bi bi-x-circle-fill fs-5"></i>
        <ul class="mb-0 ps-3">
            @foreach ($errors->all() as $message)
                <li>{{ $message }}</li>
            @endforeach
        </ul>
    </div>
@endif

@if($source->status === 'failed' && $source->error)
    <div class="alert alert-danger d-flex gap-2 align-items-start">
        <i class="bi bi-exclamation-triangle-fill fs-5"></i>
        <div>{{ $source->error }}</div>
    </div>
@endif

<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body">
                <div class="text-muted small">Status</div>
                <div class="fs-3 fw-semibold">{{ $source->statusLabel() }}</div>
                <div class="small text-muted">
                    @if($reading)
                        this page refreshes itself
                    @elseif($source->next_crawl_at)
                        next read {{ $source->next_crawl_at->diffForHumans() }}
                    @else
                        read again only on request
                    @endif
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body">
                <div class="text-muted small">Pages read</div>
                <div class="fs-3 fw-semibold">{{ number_format(($counts['indexed'] ?? 0) + ($counts['unchanged'] ?? 0)) }}</div>
                <div class="small text-muted">of {{ number_format($counts->sum()) }} found · at most {{ $source->max_pages }}</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body">
                <div class="text-muted small">Last read</div>
                <div class="fs-3 fw-semibold">{{ $source->last_crawled_at?->diffForHumans(short: true) ?? 'Not yet' }}</div>
                <div class="small text-muted">{{ $source->refresh_days ? 'every '.$source->refresh_days.' '.Str::plural('day', $source->refresh_days) : 'on request' }}</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body">
                <div class="text-muted small">Translation tokens</div>
                <div class="fs-3 fw-semibold">{{ number_format($source->prompt_tokens + $source->completion_tokens) }}</div>
                <div class="small text-muted">{{ number_format($source->prompt_tokens) }} sent · {{ number_format($source->completion_tokens) }} returned</div>
            </div>
        </div>
    </div>
</div>

<div class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
        <span class="fw-semibold"><i class="bi bi-files me-1 text-primary"></i>Pages</span>
        <span class="small">
            @foreach($counts as $status => $count)
                <span class="badge {{ $pageBadges[$status][1] ?? 'text-bg-light border' }} fw-normal ms-1">{{ $pageBadges[$status][0] ?? $status }} {{ $count }}</span>
            @endforeach
        </span>
    </div>
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Page</th>
                    <th>Status</th>
                    <th>Language</th>
                    <th class="text-end">Characters</th>
                    <th>Article</th>
                    <th>Read</th>
                </tr>
            </thead>
            <tbody>
                @forelse($pages as $page)
                    <tr>
                        <td style="min-width:260px">
                            <div class="fw-semibold">{{ $page->title ?: '—' }}</div>
                            <a href="{{ $page->url }}" target="_blank" rel="noopener noreferrer" class="small text-break">{{ $page->url }}</a>
                        </td>
                        <td>
                            <span class="badge {{ $pageBadges[$page->status][1] ?? 'text-bg-light border' }} fw-normal">{{ $pageBadges[$page->status][0] ?? $page->status }}</span>
                            @if($page->error)
                                <div class="small {{ $page->status === 'failed' ? 'text-danger' : 'text-muted' }} mt-1">{{ $page->error }}</div>
                            @endif
                        </td>
                        <td class="small">{{ $page->language ? strtoupper($page->language) : '—' }}</td>
                        <td class="text-end small" style="font-variant-numeric: tabular-nums">{{ $page->characters ? number_format($page->characters) : '—' }}</td>
                        <td class="small">
                            @if($page->article)
                                <a href="{{ route('admin.ai-assistant.knowledge.edit', $page->article) }}">{{ $page->article->title }}</a>
                                @unless($page->article->is_published)
                                    <span class="badge text-bg-secondary fw-normal">Draft</span>
                                @endunless
                            @else
                                —
                            @endif
                        </td>
                        <td class="small text-muted text-nowrap">{{ $page->fetched_at?->diffForHumans() ?? '—' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="text-center text-muted py-5">No pages yet. They appear here as the site is read.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

@if($pages->hasPages())
    <div class="mb-4">{{ $pages->links() }}</div>
@endif

<details class="card shadow-sm border-0" @if($errors->any()) open @endif>
    <summary class="card-header bg-white fw-semibold"><i class="bi bi-sliders me-1 text-primary"></i>Settings</summary>
    <form method="POST" action="{{ route('admin.ai-assistant.knowledge.websites.update', $source) }}">
        @csrf
        @method('PUT')
        <div class="card-body">
            @include('admin.ai-knowledge.websites._form', ['source' => $source])
        </div>
        <div class="card-footer bg-white d-flex justify-content-between align-items-center">
            <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Save</button>
        </div>
    </form>
    <form method="POST" action="{{ route('admin.ai-assistant.knowledge.websites.destroy', $source) }}" class="card-footer bg-white border-top-0 pt-0"
          onsubmit="return confirm('Delete “{{ addslashes($source->name) }}” and every article read from it?');">
        @csrf
        @method('DELETE')
        <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash me-1"></i>Delete the website and its articles</button>
    </form>
</details>

@if($reading)
    @push('scripts')
    <script>
        // Still reading: show the pages as they come in.
        setTimeout(function () {
            if (!document.querySelector('details[open]')) {
                window.location.reload();
            }
        }, 20000);
    </script>
    @endpush
@endif
@endsection
