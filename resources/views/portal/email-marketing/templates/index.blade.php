@extends('layouts.portal')

@section('title', 'Templates')

@section('content')
<div class="container-fluid py-4">
    <h3 class="mb-3"><i class="bi bi-envelope-paper me-2"></i>Email Marketing</h3>
    @include('portal.email-marketing._nav')

    @if (session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif

    <div class="d-flex justify-content-between align-items-center mb-3 gap-2 flex-wrap">
        <div>
            <a href="{{ route('portal.marketing.templates.index') }}"
               class="btn btn-sm {{ ($showArchived ?? false) ? 'btn-outline-secondary' : 'btn-secondary' }}">Active</a>
            <a href="{{ route('portal.marketing.templates.index', ['archived' => 1]) }}"
               class="btn btn-sm {{ ($showArchived ?? false) ? 'btn-secondary' : 'btn-outline-secondary' }}">
                <i class="bi bi-archive me-1"></i>Archived
            </a>
        </div>
        <div class="btn-group">
            <a href="{{ route('portal.marketing.templates.create') }}" class="btn btn-primary btn-sm">
                <i class="bi bi-plus me-1"></i>New template (Unlayer)
            </a>
            <button type="button" class="btn btn-primary btn-sm dropdown-toggle dropdown-toggle-split"
                    data-bs-toggle="dropdown" aria-expanded="false">
                <span class="visually-hidden">Toggle</span>
            </button>
            <ul class="dropdown-menu dropdown-menu-end">
                <li>
                    <a class="dropdown-item" href="{{ route('portal.marketing.templates.create') }}">
                        <i class="bi bi-easel me-2"></i>Unlayer
                        <small class="text-muted d-block ms-4">Drag-and-drop, recommended</small>
                    </a>
                </li>
                <li>
                    <a class="dropdown-item" href="{{ route('portal.marketing.templates.create', ['editor' => 'grapesjs']) }}">
                        <i class="bi bi-code-square me-2"></i>GrapesJS + MJML
                        <small class="text-muted d-block ms-4">Code-friendly, icon-rich, fully open source</small>
                    </a>
                </li>
            </ul>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light"><tr><th>Name</th><th>Editor</th><th>Preview text</th><th>Created</th><th></th></tr></thead>
                <tbody>
                @forelse ($templates as $t)
                    <tr>
                        <td><a href="{{ route('portal.marketing.templates.edit', $t) }}"><strong>{{ $t->name }}</strong></a></td>
                        <td>
                            @if ($t->editor_type === 'grapesjs')
                                <span class="badge bg-warning text-dark"><i class="bi bi-code-square me-1"></i>GrapesJS</span>
                            @else
                                <span class="badge bg-info"><i class="bi bi-easel me-1"></i>Unlayer</span>
                            @endif
                        </td>
                        <td><small class="text-muted">{{ $t->preview_text }}</small></td>
                        <td><small>{{ $t->created_at?->diffForHumans() }}</small></td>
                        <td class="text-end">
                            <form method="POST" action="{{ route('portal.marketing.templates.duplicate', $t) }}" class="d-inline">
                                @csrf
                                <button class="btn btn-sm btn-outline-secondary" title="Duplicate"><i class="bi bi-files"></i></button>
                            </form>
                            <form method="POST" action="{{ route('portal.marketing.templates.archive', $t) }}" class="d-inline">
                                @csrf
                                <button class="btn btn-sm btn-outline-warning"
                                        title="{{ $t->archived_at ? 'Restore' : 'Archive' }}">
                                    <i class="bi bi-{{ $t->archived_at ? 'arrow-counterclockwise' : 'archive' }}"></i>
                                </button>
                            </form>
                            <form method="POST" action="{{ route('portal.marketing.templates.destroy', $t) }}" class="d-inline"
                                  onsubmit="return confirm('Delete template? Existing campaigns retain their rendered HTML.')">
                                @csrf @method('DELETE')
                                <button class="btn btn-sm btn-outline-danger" title="Delete"><i class="bi bi-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-center text-muted py-4">No templates yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer">{{ $templates->links() }}</div>
    </div>
</div>
@endsection
