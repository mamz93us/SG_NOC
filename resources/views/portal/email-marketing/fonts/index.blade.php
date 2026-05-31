@extends('layouts.marketing')

@section('title', 'Fonts')

@section('content')
<div class="container-fluid py-4">
    <h3 class="mb-3"><i class="bi bi-envelope-paper me-2"></i>Email Marketing</h3>
    @include('portal.email-marketing._nav')

    @if (session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif

    {{-- Load every configured font on this page so we can preview them in the table below. --}}
    @foreach ($fonts as $f)
        @if ($f->url)
            <link rel="stylesheet" href="{{ $f->url }}">
        @endif
    @endforeach

    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="mb-0"><i class="bi bi-fonts text-info me-1"></i>Fonts available in the editor</h4>
        <a href="{{ route('portal.marketing.fonts.create') }}" class="btn btn-primary btn-sm">
            <i class="bi bi-plus me-1"></i>New font
        </a>
    </div>

    <div class="alert alert-info py-2">
        <i class="bi bi-info-circle me-1"></i>
        Fonts listed here are registered with the Unlayer editor's font picker. Use a
        <a href="https://fonts.google.com" target="_blank" rel="noopener">Google Fonts</a> stylesheet URL
        (recommended — works in most email clients) or host your own @font-face stylesheet.
    </div>

    <div class="card shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr><th>Label</th><th>CSS family</th><th>Preview</th><th>Source</th><th>Order</th><th class="text-end">Actions</th></tr>
                </thead>
                <tbody>
                @forelse ($fonts as $f)
                    <tr>
                        <td>
                            <strong>{{ $f->label }}</strong>
                            @if ($f->is_default)
                                <span class="badge bg-success ms-1">default</span>
                            @endif
                        </td>
                        <td><small><code>{{ $f->family }}</code></small></td>
                        <td style="font-family: {{ $f->family }}; font-size: 16px;">
                            The quick brown fox jumps over 1234567890
                            <br><small>أبجد هوز حطي كلمن سعفص قرشت</small>
                        </td>
                        <td>
                            @if ($f->source === 'google')
                                <span class="badge bg-light text-dark"><i class="bi bi-google"></i> Google</span>
                            @else
                                <span class="badge bg-light text-dark"><i class="bi bi-link-45deg"></i> Custom</span>
                            @endif
                            @if ($f->url)
                                <br><small class="text-muted text-truncate d-block" style="max-width: 240px;" title="{{ $f->url }}">
                                    <a href="{{ $f->url }}" target="_blank" rel="noopener">{{ \Illuminate\Support\Str::limit($f->url, 40) }}</a>
                                </small>
                            @endif
                        </td>
                        <td><small>{{ $f->sort_order }}</small></td>
                        <td class="text-end">
                            <a href="{{ route('portal.marketing.fonts.edit', $f) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil"></i></a>
                            <form method="POST" action="{{ route('portal.marketing.fonts.destroy', $f) }}" class="d-inline"
                                  onsubmit="return confirm('Delete this font? Templates that already use it will keep working as long as the URL is still reachable.')">
                                @csrf @method('DELETE')
                                <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-center text-muted py-4">No fonts yet — Unlayer will fall back to its built-in choices.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer">{{ $fonts->links() }}</div>
    </div>
</div>
@endsection
