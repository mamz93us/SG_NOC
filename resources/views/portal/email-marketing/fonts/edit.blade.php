@extends('layouts.marketing')

@section('title', $font->exists ? 'Edit font' : 'New font')

@section('content')
<div class="container-fluid py-4">
    <h3 class="mb-3"><i class="bi bi-envelope-paper me-2"></i>Email Marketing</h3>
    @include('portal.email-marketing._nav')

    @if ($errors->any())
        <div class="alert alert-danger"><ul class="mb-0">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
    @endif

    <h4 class="mb-3">
        <i class="bi bi-fonts text-info me-1"></i>
        {{ $font->exists ? 'Edit font: '.$font->label : 'New font' }}
    </h4>

    @if ($font->url)<link rel="stylesheet" href="{{ $font->url }}">@endif

    <form class="card shadow-sm"
          method="POST"
          action="{{ $font->exists ? route('portal.marketing.fonts.update', $font) : route('portal.marketing.fonts.store') }}">
        @csrf
        @if ($font->exists) @method('PUT') @endif

        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Label</label>
                    <input type="text" name="label" class="form-control" required
                           placeholder="e.g. Cairo (Arabic)"
                           value="{{ old('label', $font->label) }}">
                    <small class="text-muted">Shown in Unlayer's font picker.</small>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Source</label>
                    <select name="source" class="form-select">
                        <option value="google" @selected(old('source', $font->source ?: 'google') === 'google')>Google Fonts</option>
                        <option value="custom" @selected(old('source', $font->source) === 'custom')>Custom @font-face</option>
                    </select>
                </div>

                <div class="col-md-6">
                    <label class="form-label">CSS font-family</label>
                    <input type="text" name="family" class="form-control font-monospace" required
                           placeholder="'Cairo', sans-serif"
                           value="{{ old('family', $font->family) }}">
                    <small class="text-muted">Include the fallback after a comma — e.g. <code>'Cairo', sans-serif</code>.</small>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Stylesheet URL</label>
                    <input type="url" name="url" class="form-control"
                           placeholder="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700&display=swap"
                           value="{{ old('url', $font->url) }}">
                    <small class="text-muted">
                        For Google Fonts pick the family on
                        <a href="https://fonts.google.com" target="_blank" rel="noopener">fonts.google.com</a>
                        and copy the <code>href</code> from the embed snippet.
                    </small>
                </div>

                <div class="col-md-4">
                    <label class="form-label">Sort order</label>
                    <input type="number" name="sort_order" class="form-control"
                           value="{{ old('sort_order', $font->sort_order ?: 100) }}">
                </div>
                <div class="col-md-4 d-flex align-items-end">
                    <div class="form-check form-switch">
                        <input type="checkbox" id="is_default" name="is_default" value="1"
                               class="form-check-input" @checked(old('is_default', $font->is_default))>
                        <label for="is_default" class="form-check-label">Use as default</label>
                    </div>
                </div>
            </div>

            @if (! empty(old('family', $font->family)))
                <div class="mt-3 p-3 border rounded" style="font-family: {{ old('family', $font->family) }};">
                    <strong>Live preview</strong>
                    <div style="font-size: 24px;">The quick brown fox 1234567890</div>
                    <div style="font-size: 18px;">أبجد هوز حطي كلمن سعفص قرشت</div>
                </div>
            @endif
        </div>

        <div class="card-footer d-flex justify-content-end">
            <a href="{{ route('portal.marketing.fonts.index') }}" class="btn btn-link">Cancel</a>
            <button class="btn btn-primary"><i class="bi bi-check2-circle me-1"></i>Save font</button>
        </div>
    </form>
</div>
@endsection
