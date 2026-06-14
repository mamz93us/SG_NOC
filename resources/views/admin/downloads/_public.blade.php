@php($state = $f->publicState())
@if($state === 'Active')
    <div class="input-group input-group-sm mb-1">
        <input type="text" class="form-control" readonly value="{{ $f->publicShareUrl() }}"
               onclick="this.select()" id="share-{{ $f->id }}">
        <button class="btn btn-outline-secondary" type="button" title="Copy"
                onclick="navigator.clipboard.writeText(document.getElementById('share-{{ $f->id }}').value)">
            <i class="bi bi-clipboard"></i>
        </button>
    </div>
    <div class="small text-muted">
        @if($f->public_expires_at)
            Expires {{ $f->public_expires_at->format('Y-m-d H:i') }}
        @else
            Never expires
        @endif
        · {{ $f->download_count }} download{{ $f->download_count == 1 ? '' : 's' }}
    </div>
    @can('manage-downloads')
    <div class="mt-1">
        <form action="{{ route('admin.downloads.rotate', $f) }}" method="POST" class="d-inline">
            @csrf
            <button class="btn btn-sm btn-link p-0 text-decoration-none" title="Generate a new URL">Rotate</button>
        </form>
        <span class="text-muted">·</span>
        <form action="{{ route('admin.downloads.public', $f) }}" method="POST" class="d-inline">
            @csrf
            <input type="hidden" name="enabled" value="0">
            <button class="btn btn-sm btn-link p-0 text-danger text-decoration-none">Revoke</button>
        </form>
    </div>
    @endcan
@elseif($state === 'Expired')
    <span class="badge bg-secondary-subtle text-secondary-emphasis">Expired</span>
    @can('manage-downloads')
    <form action="{{ route('admin.downloads.public', $f) }}" method="POST" class="d-inline ms-1">
        @csrf
        <input type="hidden" name="enabled" value="0">
        <button class="btn btn-sm btn-link p-0 text-danger text-decoration-none">Revoke</button>
    </form>
    @endcan
@else
    @can('manage-downloads')
    @if($f->isStored())
    <form action="{{ route('admin.downloads.public', $f) }}" method="POST" class="row g-1 align-items-center">
        @csrf
        <input type="hidden" name="enabled" value="1">
        <div class="col-auto">
            <input type="datetime-local" name="expires_at" class="form-control form-control-sm"
                   title="Optional expiry — leave blank for never">
        </div>
        <div class="col-auto">
            <button class="btn btn-sm btn-outline-success" title="Create a public link">
                <i class="bi bi-share"></i> Share
            </button>
        </div>
    </form>
    @else
        <span class="text-muted small">—</span>
    @endif
    @else
        <span class="text-muted small">Private</span>
    @endcan
@endif
