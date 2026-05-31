@extends('layouts.marketing')

@section('title', $icon->exists ? 'Edit icon' : 'New icon')

@section('content')
<div class="container-fluid py-4">
    <h3 class="mb-3"><i class="bi bi-envelope-paper me-2"></i>Email Marketing</h3>
    @include('portal.email-marketing._nav')

    @if ($errors->any())
        <div class="alert alert-danger"><ul class="mb-0">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
    @endif

    <h4 class="mb-3">
        <i class="bi bi-stars text-warning me-1"></i>
        {{ $icon->exists ? 'Edit icon: '.$icon->label : 'New icon' }}
    </h4>

    <form class="card shadow-sm"
          method="POST"
          action="{{ $icon->exists ? route('portal.marketing.icons.update', $icon) : route('portal.marketing.icons.store') }}">
        @csrf
        @if ($icon->exists) @method('PUT') @endif

        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Label</label>
                    <input type="text" name="label" class="form-control" required
                           value="{{ old('label', $icon->label) }}">
                    <small class="text-muted">Human-readable name shown to marketing users.</small>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Name (slug)</label>
                    <input type="text" name="name" class="form-control" required pattern="[a-z0-9_-]+"
                           value="{{ old('name', $icon->name) }}">
                    <small class="text-muted">Lowercase letters/numbers/dashes/underscores. Used as a key in the data — don't reuse one.</small>
                </div>

                <div class="col-12">
                    <label class="form-label">SVG path <code>d</code> attribute</label>
                    <textarea name="svg_path" class="form-control font-monospace" rows="4" required>{{ old('svg_path', $icon->svg_path) }}</textarea>
                    <small class="text-muted">
                        Just the contents of <code>d="…"</code> from a 24×24 SVG. Grab one from
                        <a href="https://icons.getbootstrap.com" target="_blank" rel="noopener">Bootstrap Icons</a> or
                        <a href="https://heroicons.com" target="_blank" rel="noopener">Heroicons</a>.
                    </small>
                </div>

                <div class="col-md-4">
                    <label class="form-label">Default color</label>
                    <input type="color" name="default_color" class="form-control form-control-color"
                           value="{{ old('default_color', $icon->default_color ?: '#dc3545') }}">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Default size (px)</label>
                    <input type="number" name="default_size" class="form-control" min="12" max="256"
                           value="{{ old('default_size', $icon->default_size ?: 48) }}">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Sort order</label>
                    <input type="number" name="sort_order" class="form-control"
                           value="{{ old('sort_order', $icon->sort_order ?: 100) }}">
                    <small class="text-muted">Lower = earlier in the picker.</small>
                </div>
            </div>

            @if (! empty(old('svg_path', $icon->svg_path)))
                <div class="mt-3">
                    <strong>Preview:</strong>
                    <svg width="64" height="64" viewBox="0 0 24 24" fill="none"
                         stroke="{{ old('default_color', $icon->default_color ?: '#dc3545') }}"
                         stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="{{ old('svg_path', $icon->svg_path) }}"/>
                    </svg>
                </div>
            @endif
        </div>

        <div class="card-footer d-flex justify-content-end">
            <a href="{{ route('portal.marketing.icons.index') }}" class="btn btn-link">Cancel</a>
            <button class="btn btn-primary"><i class="bi bi-check2-circle me-1"></i>Save icon</button>
        </div>
    </form>
</div>
@endsection
