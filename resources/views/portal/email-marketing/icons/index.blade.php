@extends('layouts.portal')

@section('title', 'SAMIR icon library')

@section('content')
<div class="container-fluid py-4">
    <h3 class="mb-3"><i class="bi bi-envelope-paper me-2"></i>Email Marketing</h3>
    @include('portal.email-marketing._nav')

    @if (session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif

    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="mb-0"><i class="bi bi-stars text-warning me-1"></i>SAMIR icon library</h4>
        <a href="{{ route('portal.marketing.icons.create') }}" class="btn btn-primary btn-sm">
            <i class="bi bi-plus me-1"></i>New icon
        </a>
    </div>

    <div class="alert alert-info py-2">
        <i class="bi bi-info-circle me-1"></i>
        Icons here show up in every template editor as click-to-copy buttons.
        Use 24×24 viewBox SVG path data — see the
        <a href="https://icons.getbootstrap.com" target="_blank" rel="noopener">Bootstrap Icons</a> or
        <a href="https://heroicons.com" target="_blank" rel="noopener">Heroicons</a> libraries for free path data.
    </div>

    <div class="card shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr><th>Preview</th><th>Label</th><th>Name</th><th>Default color</th><th>Size</th><th>Order</th><th class="text-end">Actions</th></tr>
                </thead>
                <tbody>
                @forelse ($icons as $icon)
                    <tr>
                        <td>
                            <svg width="32" height="32" viewBox="0 0 24 24" fill="none"
                                 stroke="{{ $icon->default_color }}" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="{{ $icon->svg_path }}"/>
                            </svg>
                        </td>
                        <td><strong>{{ $icon->label }}</strong></td>
                        <td><small><code>{{ $icon->name }}</code></small></td>
                        <td>
                            <span class="badge" style="background-color: {{ $icon->default_color }}">&nbsp;</span>
                            <small><code>{{ $icon->default_color }}</code></small>
                        </td>
                        <td><small>{{ $icon->default_size }}px</small></td>
                        <td><small>{{ $icon->sort_order }}</small></td>
                        <td class="text-end">
                            <a href="{{ route('portal.marketing.icons.edit', $icon) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil"></i></a>
                            <form method="POST" action="{{ route('portal.marketing.icons.destroy', $icon) }}" class="d-inline"
                                  onsubmit="return confirm('Delete this icon? Existing templates that already pasted this SVG will keep working.')">
                                @csrf @method('DELETE')
                                <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-center text-muted py-4">No icons yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer">{{ $icons->links() }}</div>
    </div>
</div>
@endsection
