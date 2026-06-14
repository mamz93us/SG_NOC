@extends('layouts.admin')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0"><i class="bi bi-image me-2"></i>Managed Wallpapers</h1>
    <a href="{{ $scriptUrl }}" class="btn btn-sm btn-outline-primary">
        <i class="bi bi-filetype-ps1 me-1"></i>Download PowerShell script
    </a>
</div>

@if($errors->any())
    <div class="alert alert-danger">
        <ul class="mb-0">
            @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
        </ul>
    </div>
@endif

{{-- ── Deployment links ─────────────────────────────────────────── --}}
<div class="card mb-4 border-primary">
    <div class="card-header bg-primary text-white fw-semibold">
        <i class="bi bi-rocket-takeoff me-2"></i>Deploy to Intune
    </div>
    <div class="card-body">
        <p class="text-muted small mb-3">
            Add this <strong>PowerShell platform script</strong> in Intune
            (<em>Devices → Scripts and remediations → Platform scripts</em>), set
            <strong>Run this script using the logged-on credentials = No</strong> (runs as SYSTEM) and
            <strong>Run script in 64-bit PowerShell = Yes</strong>, then assign it to your device groups.
            The script self-installs a <strong>daily scheduled task</strong>, so it keeps re-applying — and
            because it re-reads the manifest every run, <strong>changing a wallpaper here updates every device
            automatically</strong> (no re-deploy). It also <strong>locks</strong> the wallpaper and lock screen so users can't change them.
        </p>

        <label class="form-label small fw-semibold mb-1">Script URL (paste/download into Intune)</label>
        <div class="input-group input-group-sm mb-3">
            <input type="text" class="form-control font-monospace" id="scriptUrl" value="{{ $scriptUrl }}" readonly>
            <button class="btn btn-outline-secondary" type="button" onclick="copyVal('scriptUrl', this)"><i class="bi bi-clipboard"></i></button>
        </div>

        <label class="form-label small fw-semibold mb-1">Manifest URL (the script reads this — for reference)</label>
        <div class="input-group input-group-sm">
            <input type="text" class="form-control font-monospace" id="manifestUrl" value="{{ $manifestUrl }}" readonly>
            <button class="btn btn-outline-secondary" type="button" onclick="copyVal('manifestUrl', this)"><i class="bi bi-clipboard"></i></button>
        </div>

        <div class="alert alert-light border mt-3 mb-0 small">
            <i class="bi bi-info-circle me-1"></i>
            On a device, the script matches the machine's domain against each row below. If nothing matches,
            the row marked <span class="badge bg-secondary">Default</span> is used. To pin one device manually, set
            registry value <code>HKLM\SOFTWARE\SamirGroup\Wallpaper\DomainOverride</code> to the domain string.
        </div>
    </div>
</div>

{{-- ── Add a domain ─────────────────────────────────────────────── --}}
@can('manage-wallpapers')
<div class="card mb-4">
    <div class="card-header fw-semibold"><i class="bi bi-plus-circle me-2"></i>Add a domain</div>
    <div class="card-body">
        <form method="POST" action="{{ route('admin.wallpapers.store') }}" class="row g-2 align-items-end">
            @csrf
            <div class="col-md-4">
                <label class="form-label small">Label</label>
                <input type="text" name="label" class="form-control" placeholder="e.g. SSS Egypt" required>
            </div>
            <div class="col-md-3">
                <label class="form-label small">Domain match</label>
                <input type="text" name="domain_match" class="form-control font-monospace" placeholder="sssegypt.com" required>
            </div>
            <div class="col-md-3">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="is_default" value="1" id="newDefault">
                    <label class="form-check-label small" for="newDefault">Use as default (fallback)</label>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="enabled" value="1" id="newEnabled" checked>
                    <label class="form-check-label small" for="newEnabled">Enabled</label>
                </div>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary w-100"><i class="bi bi-plus-lg me-1"></i>Add</button>
            </div>
        </form>
    </div>
</div>
@endcan

{{-- ── Domain sets ──────────────────────────────────────────────── --}}
@forelse($sets as $set)
<div class="card mb-3">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span class="fw-semibold">
            <i class="bi bi-hdd-network me-2"></i>{{ $set->label }}
            <code class="ms-1">{{ $set->domain_match }}</code>
            @if($set->is_default)<span class="badge bg-secondary ms-1">Default</span>@endif
            @unless($set->enabled)<span class="badge bg-warning text-dark ms-1">Disabled</span>@endunless
        </span>
        @can('manage-wallpapers')
        <div class="d-flex gap-2">
            <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#edit-{{ $set->id }}">
                <i class="bi bi-pencil"></i> Edit
            </button>
            <form method="POST" action="{{ route('admin.wallpapers.destroy', $set) }}"
                  onsubmit="return confirm('Delete “{{ $set->label }}” and its images?');">
                @csrf @method('DELETE')
                <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
            </form>
        </div>
        @endcan
    </div>

    <div class="card-body">
        @can('manage-wallpapers')
        {{-- inline edit --}}
        <div class="collapse mb-3" id="edit-{{ $set->id }}">
            <form method="POST" action="{{ route('admin.wallpapers.update', $set) }}" class="row g-2 align-items-end border-bottom pb-3">
                @csrf @method('PUT')
                <div class="col-md-4">
                    <label class="form-label small">Label</label>
                    <input type="text" name="label" class="form-control form-control-sm" value="{{ $set->label }}" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label small">Domain match</label>
                    <input type="text" name="domain_match" class="form-control form-control-sm font-monospace" value="{{ $set->domain_match }}" required>
                </div>
                <div class="col-md-3">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="is_default" value="1" id="def-{{ $set->id }}" @checked($set->is_default)>
                        <label class="form-check-label small" for="def-{{ $set->id }}">Default</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="enabled" value="1" id="en-{{ $set->id }}" @checked($set->enabled)>
                        <label class="form-check-label small" for="en-{{ $set->id }}">Enabled</label>
                    </div>
                </div>
                <div class="col-md-2">
                    <button class="btn btn-sm btn-primary w-100">Save</button>
                </div>
            </form>
        </div>
        @endcan

        <div class="row g-4">
            @foreach(['desktop' => 'Desktop wallpaper', 'lockscreen' => 'Lock-screen wallpaper'] as $kind => $title)
            @php
                $url = $kind === 'desktop' ? $set->desktopUrl() : $set->lockscreenUrl();
                // Cache-bust the preview with the image hash so re-uploads / the other
                // kind's upload never leave a stale thumbnail (same stable filename URL).
                $hash = $kind === 'desktop' ? $set->desktop_hash : $set->lockscreen_hash;
                $preview = $url ? $url.'?v='.($hash ?? $set->updated_at?->timestamp) : null;
            @endphp
            <div class="col-md-6">
                <div class="fw-semibold small mb-2">
                    <i class="bi bi-{{ $kind === 'desktop' ? 'display' : 'lock' }} me-1"></i>{{ $title }}
                </div>
                <div class="border rounded bg-light d-flex align-items-center justify-content-center mb-2"
                     style="height:160px; overflow:hidden;">
                    @if($url)
                        <img src="{{ $preview }}" alt="{{ $title }}" style="max-width:100%; max-height:160px; object-fit:contain;">
                    @else
                        <span class="text-muted small"><i class="bi bi-image me-1"></i>No image yet</span>
                    @endif
                </div>
                @can('manage-wallpapers')
                <div class="d-flex gap-2">
                    <form method="POST" action="{{ route('admin.wallpapers.image', $set) }}" enctype="multipart/form-data" class="d-flex gap-2 flex-grow-1">
                        @csrf
                        <input type="hidden" name="kind" value="{{ $kind }}">
                        <input type="file" name="image" class="form-control form-control-sm" accept=".jpg,.jpeg,.png,.bmp" required>
                        <button class="btn btn-sm btn-primary text-nowrap"><i class="bi bi-upload"></i></button>
                    </form>
                    @if($url)
                    <form method="POST" action="{{ route('admin.wallpapers.image.delete', $set) }}"
                          onsubmit="return confirm('Remove this image?');">
                        @csrf @method('DELETE')
                        <input type="hidden" name="kind" value="{{ $kind }}">
                        <button class="btn btn-sm btn-outline-danger"><i class="bi bi-x-lg"></i></button>
                    </form>
                    @endif
                </div>
                @endcan
            </div>
            @endforeach
        </div>
    </div>
</div>
@empty
<div class="alert alert-info">No domains yet — add one above.</div>
@endforelse

{{-- ── Devices that applied the wallpaper ───────────────────────── --}}
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center fw-semibold">
        <span><i class="bi bi-pc-display me-2"></i>Devices ({{ $checkins->count() }})</span>
        <span class="text-muted small">Reported by the script after it applies</span>
    </div>
    <div class="card-body p-0">
        @if($checkins->isEmpty())
            <div class="p-3 text-muted small">
                No devices have checked in yet. Once a device runs the script, it reports here within a minute.
            </div>
        @else
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Device</th>
                        <th>Domain detected</th>
                        <th>Wallpaper set</th>
                        <th>OS</th>
                        <th>Runs</th>
                        <th>Last applied</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($checkins as $c)
                    <tr>
                        <td class="font-monospace">{{ $c->hostname }}</td>
                        <td class="small text-muted">{{ $c->domain_detected ?: '—' }}</td>
                        <td>
                            @if($c->set_label)
                                <span class="badge bg-primary">{{ $c->set_label }}</span>
                            @else
                                <span class="badge bg-secondary">none</span>
                            @endif
                        </td>
                        <td class="small text-muted">{{ $c->os_version ?: '—' }}</td>
                        <td class="small">{{ $c->checkin_count }}</td>
                        <td class="small" title="{{ $c->last_applied_at }}">
                            {{ $c->last_applied_at?->diffForHumans() ?? '—' }}
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>
</div>

<p class="text-muted small">
    <i class="bi bi-lightbulb me-1"></i>
    Tip: use a high-resolution image (1920×1080 or larger). The desktop and lock screen can use the same or different images.
</p>

<script>
function copyVal(id, btn) {
    const el = document.getElementById(id);
    navigator.clipboard.writeText(el.value).then(() => {
        const old = btn.innerHTML;
        btn.innerHTML = '<i class="bi bi-check-lg"></i>';
        setTimeout(() => btn.innerHTML = old, 1200);
    });
}
</script>
@endsection
