@extends('layouts.admin')

@section('title', 'Employee Documents')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-folder2-open me-2 text-primary"></i>Employee Documents</h4>
        <small class="text-muted">
            Manuals, guides, forms and IT policies read on the employee home portal
            under <strong>Documentation &amp; Manuals</strong> and <strong>IT Policy</strong>.
        </small>
    </div>
    <a href="{{ route('admin.portal-documents.create') }}" class="btn btn-primary btn-sm">
        <i class="bi bi-plus-lg me-1"></i>New Document
    </a>
</div>

@if (session('success'))
    <div class="alert alert-success d-flex gap-2 align-items-start">
        <i class="bi bi-check-circle-fill fs-5"></i>
        <div>{{ session('success') }}</div>
    </div>
@endif

<div class="mb-3 d-flex gap-2 flex-wrap">
    <a href="{{ route('admin.portal-documents.index') }}"
       class="btn btn-sm {{ $category === null ? 'btn-dark' : 'btn-outline-secondary' }}">All</a>
    @foreach(\App\Models\PortalDocument::CATEGORIES as $key => $label)
        <a href="{{ route('admin.portal-documents.index', ['category' => $key]) }}"
           class="btn btn-sm {{ $category === $key ? 'btn-dark' : 'btn-outline-secondary' }}">{{ $label }}</a>
    @endforeach
</div>

<div class="card shadow-sm border-0">
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Title</th>
                    <th style="width:120px">Category</th>
                    <th style="width:150px">Source</th>
                    <th style="min-width:150px">Audience</th>
                    <th style="width:110px">Version</th>
                    <th style="width:100px" class="text-center">Downloads</th>
                    <th style="width:100px" class="text-center">Status</th>
                    <th style="width:150px"></th>
                </tr>
            </thead>
            <tbody>
                @forelse($documents as $doc)
                    <tr>
                        <td>
                            <div class="fw-semibold">
                                @if($doc->pinned)
                                    <i class="bi bi-pin-angle-fill text-warning me-1" title="Pinned to the top"></i>
                                @endif
                                {{ $doc->title }}
                            </div>
                            @if($doc->description)
                                <div class="small text-muted text-truncate" style="max-width:420px">
                                    {{ $doc->description }}
                                </div>
                            @endif
                        </td>
                        <td>
                            <span class="badge {{ $doc->isPolicy() ? 'bg-danger' : 'bg-secondary' }}">
                                {{ $doc->categoryLabel() }}
                            </span>
                        </td>
                        <td class="small">
                            @if($doc->isFile())
                                <a href="{{ route('admin.portal-documents.download', $doc) }}" class="text-decoration-none">
                                    <i class="bi bi-file-earmark-arrow-down me-1"></i>{{ $doc->typeTag() }}
                                </a>
                                <span class="text-muted">{{ $doc->humanSize() }}</span>
                            @else
                                <a href="{{ $doc->link_url }}" target="_blank" rel="noopener noreferrer"
                                   class="text-decoration-none text-truncate d-inline-block" style="max-width:140px">
                                    <i class="bi bi-box-arrow-up-right me-1"></i>Link
                                </a>
                            @endif
                        </td>
                        <td class="small">
                            @if($doc->audience === 'branch')
                                Branch: {{ $doc->branch?->name ?? '—' }}
                            @elseif($doc->audience === 'department')
                                Dept: {{ $doc->department?->name ?? '—' }}
                            @else
                                Everyone
                            @endif
                        </td>
                        <td class="small text-muted">
                            {{ $doc->version ? 'v'.$doc->version : '—' }}
                            @if($doc->effective_date)
                                <div>{{ $doc->effective_date->format('d M Y') }}</div>
                            @endif
                        </td>
                        <td class="text-center small text-muted">{{ $doc->download_count }}</td>
                        <td class="text-center">
                            @if($doc->is_published)
                                <span class="badge bg-success">Live</span>
                            @else
                                <span class="badge bg-secondary">Draft</span>
                            @endif
                        </td>
                        <td class="text-end">
                            <a href="{{ route('admin.portal-documents.edit', $doc) }}" class="btn btn-sm btn-outline-secondary">
                                <i class="bi bi-pencil"></i>
                            </a>
                            <form method="POST" action="{{ route('admin.portal-documents.destroy', $doc) }}"
                                  class="d-inline"
                                  onsubmit="return confirm('Delete “{{ addslashes($doc->title) }}”? The uploaded file goes with it.');">
                                @csrf
                                @method('DELETE')
                                <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="text-center text-muted py-5">
                            <i class="bi bi-folder2-open fs-3 d-block mb-2"></i>
                            Nothing published yet. The portal cards stay in place and simply
                            show their static wording until there is something to open.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

@if($documents->hasPages())
    <div class="mt-3">{{ $documents->links() }}</div>
@endif
@endsection
