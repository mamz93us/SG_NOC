@extends('layouts.admin')

@section('content')

@php
    $fmtBytes = function ($b) {
        if ($b <= 0) return '—';
        $u = ['B', 'KB', 'MB'];
        $i = (int) floor(log($b, 1024));
        $i = min($i, count($u) - 1);
        return round($b / (1024 ** $i), 1) . ' ' . $u[$i];
    };
@endphp

<div class="d-flex align-items-center mb-4">
    <div>
        <h1 class="h3 mb-0 fw-bold">Signature Assets</h1>
        <small class="text-muted">Images and font files your signature templates can use</small>
    </div>
    <div class="ms-auto">
        <a href="{{ route('admin.signatures.index') }}" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>Back to Signatures
        </a>
    </div>
</div>

@if(session('success'))
    <div class="alert alert-success alert-dismissible fade show">
        {{ session('success') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
@endif
@if($errors->any())
    <div class="alert alert-danger alert-dismissible fade show">
        <ul class="mb-0 ps-3">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
@endif

<div class="row g-4">

    {{-- ── Images ── --}}
    <div class="col-xl-7">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-transparent border-0">
                <span class="fw-semibold"><i class="bi bi-images me-2 text-muted"></i>Images</span>
                <div class="form-text mt-1">
                    Hosted at a public NOC URL so email clients can load them. Use the URL in a template's
                    <code>Logo URL</code> field or paste it into an <code>&lt;img src="…"&gt;</code>.
                </div>
            </div>
            <div class="card-body">
                {{-- Upload form --}}
                <form method="POST" action="{{ route('admin.signatures.assets.store') }}"
                      enctype="multipart/form-data" class="mb-4">
                    @csrf
                    <input type="hidden" name="kind" value="image">
                    <div class="input-group">
                        <input type="file" name="file" class="form-control"
                               accept=".png,.jpg,.jpeg,.gif,.webp,.svg" required>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-upload me-1"></i>Upload
                        </button>
                    </div>
                    <div class="form-text">PNG, JPG, GIF, WebP or SVG · up to 3 MB</div>
                </form>

                @if($images->isEmpty())
                    <p class="text-muted small mb-0">No images uploaded yet.</p>
                @else
                    <div class="row g-3">
                        @foreach($images as $img)
                        <div class="col-sm-6 col-md-4">
                            <div class="border rounded p-2 h-100 d-flex flex-column">
                                <div class="d-flex align-items-center justify-content-center mb-2"
                                     style="height:90px;background:#f5f5f5;border-radius:4px;overflow:hidden;">
                                    <img src="{{ $img['url'] }}" alt="{{ $img['name'] }}"
                                         style="max-width:100%;max-height:90px;object-fit:contain;">
                                </div>
                                <div class="small text-truncate fw-semibold" title="{{ $img['name'] }}">{{ $img['name'] }}</div>
                                <div class="text-muted" style="font-size:11px;">{{ $fmtBytes($img['size']) }}</div>
                                <div class="mt-2 d-flex gap-1">
                                    <button type="button" class="btn btn-sm btn-outline-secondary flex-grow-1 copy-url"
                                            data-url="{{ $img['url'] }}" title="Copy URL">
                                        <i class="bi bi-clipboard"></i> Copy URL
                                    </button>
                                    <form method="POST" action="{{ route('admin.signatures.assets.destroy') }}"
                                          onsubmit="return confirm('Delete {{ $img['name'] }}?')">
                                        @csrf @method('DELETE')
                                        <input type="hidden" name="kind" value="image">
                                        <input type="hidden" name="name" value="{{ $img['name'] }}">
                                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    </div>

    {{-- ── Fonts ── --}}
    <div class="col-xl-5">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-transparent border-0">
                <span class="fw-semibold"><i class="bi bi-fonts me-2 text-muted"></i>Font Files</span>
                <div class="form-text mt-1">
                    Uploaded fonts appear in the editor's font picker and preview live.
                </div>
            </div>
            <div class="card-body">
                <div class="alert alert-warning py-2 small">
                    <i class="bi bi-exclamation-triangle me-1"></i>
                    <strong>Outlook &amp; Gmail ignore custom fonts.</strong> Uploaded fonts render in the editor and
                    some web mail clients only — most recipients see the web-safe fallback. Use for design/preview.
                </div>

                {{-- Upload form --}}
                <form method="POST" action="{{ route('admin.signatures.assets.store') }}"
                      enctype="multipart/form-data" class="mb-4">
                    @csrf
                    <input type="hidden" name="kind" value="font">
                    <div class="mb-2">
                        <label class="form-label small fw-semibold mb-1">Font file</label>
                        <input type="file" name="file" class="form-control form-control-sm"
                               accept=".woff2,.woff,.ttf,.otf" required>
                        <div class="form-text">WOFF2 (best), WOFF, TTF or OTF · up to 5 MB</div>
                    </div>
                    <div class="row g-2">
                        <div class="col-7">
                            <label class="form-label small fw-semibold mb-1">Display name</label>
                            <input type="text" name="family" class="form-control form-control-sm"
                                   placeholder="e.g. Samir Sans" maxlength="60" required>
                        </div>
                        <div class="col-5">
                            <label class="form-label small fw-semibold mb-1">Weight</label>
                            <select name="weight" class="form-select form-select-sm">
                                <option value="400">Regular (400)</option>
                                <option value="600">Semibold (600)</option>
                                <option value="700">Bold (700)</option>
                            </select>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm mt-3 w-100">
                        <i class="bi bi-upload me-1"></i>Upload Font
                    </button>
                    <div class="form-text">
                        Upload each weight separately with the <em>same display name</em> to group them into one family.
                    </div>
                </form>

                @if($fonts->isEmpty())
                    <p class="text-muted small mb-0">No fonts uploaded yet.</p>
                @else
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr class="text-muted" style="font-size:11px;">
                                <th>Family</th><th>Weight</th><th>Size</th><th></th>
                            </tr>
                        </thead>
                        <tbody>
                        @foreach($fonts as $f)
                            <tr>
                                <td>
                                    <span class="fw-semibold">{{ $f['family'] }}</span>
                                    @unless($f['exists'])
                                        <span class="badge bg-danger ms-1" title="File missing on disk">missing</span>
                                    @endunless
                                    <div class="text-muted text-truncate" style="font-size:11px;max-width:160px;" title="{{ $f['file'] }}">{{ $f['file'] }}</div>
                                </td>
                                <td class="small">{{ $f['weight'] }}</td>
                                <td class="small text-muted">{{ $fmtBytes($f['size']) }}</td>
                                <td class="text-end">
                                    <form method="POST" action="{{ route('admin.signatures.assets.destroy') }}"
                                          onsubmit="return confirm('Delete font {{ $f['family'] }} ({{ $f['weight'] }})?')">
                                        @csrf @method('DELETE')
                                        <input type="hidden" name="kind" value="font">
                                        <input type="hidden" name="name" value="{{ $f['file'] }}">
                                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </div>
    </div>

</div>

@endsection

@push('scripts')
<script>
document.querySelectorAll('.copy-url').forEach(function (btn) {
    btn.addEventListener('click', function () {
        const url = btn.getAttribute('data-url');
        navigator.clipboard.writeText(url).then(function () {
            const original = btn.innerHTML;
            btn.innerHTML = '<i class="bi bi-check2"></i> Copied';
            btn.classList.add('btn-success');
            btn.classList.remove('btn-outline-secondary');
            setTimeout(function () {
                btn.innerHTML = original;
                btn.classList.remove('btn-success');
                btn.classList.add('btn-outline-secondary');
            }, 1500);
        });
    });
});
</script>
@endpush
