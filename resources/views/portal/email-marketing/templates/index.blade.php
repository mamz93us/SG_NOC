@extends('layouts.marketing')

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
            <a href="{{ route('portal.marketing.icons.index') }}" class="btn btn-sm btn-outline-warning">
                <i class="bi bi-stars me-1"></i>Icon library
            </a>
            <a href="{{ route('portal.marketing.fonts.index') }}" class="btn btn-sm btn-outline-info">
                <i class="bi bi-fonts me-1"></i>Fonts
            </a>
        </div>
        <a href="{{ route('portal.marketing.templates.create') }}" class="btn btn-primary btn-sm">
            <i class="bi bi-plus me-1"></i>New template
        </a>
    </div>

    <div class="card shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light"><tr><th>Name</th><th>Preview text</th><th>Created</th><th></th></tr></thead>
                <tbody>
                @forelse ($templates as $t)
                    <tr>
                        <td><a href="{{ route('portal.marketing.templates.edit', $t) }}"><strong>{{ $t->name }}</strong></a></td>
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
