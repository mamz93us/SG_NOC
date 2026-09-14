@extends('layouts.admin')

@section('title', 'AI Knowledge Websites')

@section('content')
@php
    $statusBadges = [
        'queued' => 'text-bg-secondary',
        'crawling' => 'text-bg-info',
        'idle' => 'text-bg-success',
        'failed' => 'text-bg-danger',
    ];
@endphp

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-globe2 me-2 text-primary"></i>Websites</h4>
        <small class="text-muted">
            Websites the AI Assistant reads into its knowledge base. Every page becomes an article, translated into English when it is not, and is read again on a schedule; the assistant cites the page's address.
        </small>
    </div>
    <a href="{{ route('admin.ai-assistant.knowledge.index') }}" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i>Knowledge
    </a>
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
        <div>
            The website was not added:
            <ul class="mb-0 ps-3">
                @foreach ($errors->all() as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        </div>
    </div>
@endif

<div class="row g-4">
    <div class="col-xl-7">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-list-ul me-1 text-primary"></i>Websites</div>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Website</th>
                            <th>Status</th>
                            <th class="text-end">Pages</th>
                            <th>Last read</th>
                            <th style="width:120px"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($sources as $source)
                            <tr>
                                <td style="min-width:220px">
                                    <a href="{{ route('admin.ai-assistant.knowledge.websites.show', $source) }}" class="fw-semibold">{{ $source->name }}</a>
                                    <div class="small text-muted text-break">{{ $source->start_url }}</div>
                                </td>
                                <td>
                                    <span class="badge {{ $statusBadges[$source->status] ?? 'text-bg-secondary' }} fw-normal">{{ $source->statusLabel() }}</span>
                                    @if($source->error)
                                        <div class="small text-danger mt-1">{{ $source->error }}</div>
                                    @endif
                                </td>
                                <td class="text-end text-nowrap" style="font-variant-numeric: tabular-nums">
                                    {{ number_format($source->pages_indexed) }} <span class="text-muted">of {{ number_format($source->pages_found) }}</span>
                                </td>
                                <td class="small text-muted text-nowrap">
                                    {{ $source->last_crawled_at?->diffForHumans() ?? 'Not yet' }}
                                    @if($source->next_crawl_at && $source->status === 'idle')
                                        <div>next {{ $source->next_crawl_at->diffForHumans() }}</div>
                                    @endif
                                </td>
                                <td class="text-end text-nowrap">
                                    <form method="POST" action="{{ route('admin.ai-assistant.knowledge.websites.crawl', $source) }}" class="d-inline">
                                        @csrf
                                        <button type="submit" class="btn btn-sm btn-outline-primary" title="Read now"><i class="bi bi-arrow-clockwise"></i></button>
                                    </form>
                                    <form method="POST" action="{{ route('admin.ai-assistant.knowledge.websites.destroy', $source) }}" class="d-inline"
                                          onsubmit="return confirm('Delete “{{ addslashes($source->name) }}” and every article read from it?');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete"><i class="bi bi-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="text-center text-muted py-5">
                                    <i class="bi bi-globe2 fs-3 d-block mb-2"></i>No websites yet. Add one to let the assistant answer from it.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-xl-5">
        <form method="POST" action="{{ route('admin.ai-assistant.knowledge.websites.store') }}" class="card shadow-sm border-0">
            @csrf
            <div class="card-header bg-white fw-semibold"><i class="bi bi-plus-lg me-1 text-primary"></i>Add a website</div>
            <div class="card-body">
                @include('admin.ai-knowledge.websites._form', ['source' => $newSource])
            </div>
            <div class="card-footer bg-white">
                <button type="submit" class="btn btn-primary"><i class="bi bi-globe2 me-1"></i>Add website</button>
                <span class="small text-muted ms-2">Nothing is read until the next run, within five minutes.</span>
            </div>
        </form>
    </div>
</div>
@endsection
