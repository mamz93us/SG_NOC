@extends('layouts.admin')

@section('content')

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-hdd-network me-2 text-primary"></i>Phone Firmware Server</h4>
        <small class="text-muted">Publish a Grandstream image once — every phone picks it up from the firmware path set in the UCM</small>
    </div>
    <a href="{{ route('admin.phones.firmware.status') }}" class="btn btn-outline-primary btn-sm">
        <i class="bi bi-clipboard-check me-1"></i>Who has taken it?
    </a>
</div>

{{-- What to type into the UCM. The whole feature is inert until this is set. --}}
<div class="card shadow-sm mb-4 border-start border-primary border-4">
    <div class="card-body">
        <h6 class="fw-semibold mb-2"><i class="bi bi-signpost-split me-2"></i>Firmware path for the phones</h6>
        <p class="text-muted small mb-3">
            UCM &rarr; Zero Config &rarr; Global Policy &rarr; Upgrade: set <em>Firmware Source</em> to
            <strong>URL Download</strong>, <em>Upgrade Via</em> to <strong>HTTP</strong>, and paste one of these as the
            <em>Firmware Upgrade Server Path</em> (Grandstream wants a bare host, no <code>http://</code>).
        </p>
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label small text-muted mb-1">Internal — preferred</label>
                <input type="text" class="form-control font-monospace" readonly value="{{ $internalPath }}">
                <div class="form-text">Stays inside the branch tunnels, no TLS. Use this unless the branch's tunnel doesn't carry the NOC subnet.</div>
            </div>
            <div class="col-md-6">
                <label class="form-label small text-muted mb-1">Public — fallback</label>
                <input type="text" class="form-control font-monospace" readonly value="{{ $publicPath }}">
                <div class="form-text">For branches that can't reach the NOC internally. Reachable from the internet.</div>
            </div>
        </div>
    </div>
</div>

@can('manage-phone-firmware')
<div class="row g-3 mb-4">
    {{-- Upload --}}
    <div class="col-lg-6">
        <div class="card shadow-sm h-100">
            <div class="card-header fw-semibold"><i class="bi bi-upload me-2"></i>Upload a firmware package</div>
            <div class="card-body">
                <form action="{{ route('admin.phones.firmware.store') }}" method="POST" enctype="multipart/form-data">
                    @csrf
                    <div class="mb-2">
                        <input type="file" name="file" class="form-control" accept=".bin,.zip" required>
                        <div class="form-text">The vendor <code>.zip</code> or a single <code>.bin</code>. Every <code>.bin</code> inside the zip becomes its own entry.</div>
                    </div>
                    <div class="row g-2 mb-2">
                        <div class="col-6">
                            <input type="text" name="version" class="form-control" placeholder="Version (e.g. 1.0.13.59)"
                                   value="{{ old('version') }}">
                        </div>
                        <div class="col-6">
                            <input type="text" name="covers" class="form-control" placeholder="Covers (e.g. GRP2601*)"
                                   value="{{ old('covers') }}">
                        </div>
                    </div>
                    <div class="mb-2">
                        <input type="text" name="notes" class="form-control" placeholder="Notes (optional)" value="{{ old('notes') }}">
                    </div>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-cloud-upload me-1"></i>Add to library
                    </button>
                    <div class="form-text mt-2">
                        Big packages often die on the web server's upload limit — use the URL fetch instead if this 413s.
                    </div>
                </form>
            </div>
        </div>
    </div>

    {{-- URL fetch --}}
    <div class="col-lg-6">
        <div class="card shadow-sm h-100">
            <div class="card-header fw-semibold"><i class="bi bi-link-45deg me-2"></i>Fetch from a URL</div>
            <div class="card-body">
                <form action="{{ route('admin.phones.firmware.store-url') }}" method="POST">
                    @csrf
                    <div class="mb-2">
                        <input type="url" name="source_url" class="form-control"
                               placeholder="https://firmware.grandstream.com/…/grp260x_1.0.13.59.zip" required
                               value="{{ old('source_url') }}">
                        <div class="form-text">The NOC downloads it server-side — no browser upload, no size limit to fight.</div>
                    </div>
                    <div class="row g-2 mb-2">
                        <div class="col-6">
                            <input type="text" name="version" class="form-control" placeholder="Version (optional)">
                        </div>
                        <div class="col-6">
                            <input type="text" name="covers" class="form-control" placeholder="Covers (optional)">
                        </div>
                    </div>
                    <div class="mb-2">
                        <input type="text" name="notes" class="form-control" placeholder="Notes (optional)">
                    </div>
                    <button type="submit" class="btn btn-outline-primary">
                        <i class="bi bi-download me-1"></i>Queue fetch
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
@endcan

{{-- Library --}}
<div class="card shadow-sm">
    <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
        <span><i class="bi bi-collection me-2"></i>Library</span>
        <span class="text-muted small">Published images sit at <code class="text-muted">{{ $serveRoot }}/</code></span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Model</th>
                    <th>Version</th>
                    <th>Filename phones request</th>
                    <th>Covers</th>
                    <th>Size</th>
                    <th>State</th>
                    <th>Added</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
            @forelse($firmwares as $fw)
                <tr>
                    <td class="fw-semibold">{{ $fw->model }}</td>
                    <td>{{ $fw->version }}</td>
                    <td><code>{{ $fw->filename }}</code></td>
                    <td class="small text-muted">{{ $fw->covers ?: $fw->model }}</td>
                    <td class="small">{{ $fw->humanSize() }}</td>
                    <td>
                        @if($fw->is_active)
                            <span class="badge bg-success">Publishing</span>
                        @elseif($fw->status === \App\Models\PhoneFirmware::STATUS_STORED)
                            <span class="badge bg-secondary">In library</span>
                        @elseif($fw->status === \App\Models\PhoneFirmware::STATUS_FAILED)
                            <span class="badge bg-danger" title="{{ $fw->error }}">Failed</span>
                        @else
                            <span class="badge bg-info text-dark">{{ ucfirst($fw->status) }}</span>
                        @endif
                    </td>
                    <td class="small text-muted">
                        {{ $fw->created_at?->diffForHumans() }}
                        @if($fw->uploader)<br><span class="text-muted">{{ $fw->uploader->name }}</span>@endif
                    </td>
                    <td class="text-end">
                        @can('manage-phone-firmware')
                            @if($fw->status === \App\Models\PhoneFirmware::STATUS_STORED && ! $fw->is_active)
                                <form action="{{ route('admin.phones.firmware.publish', $fw) }}" method="POST" class="d-inline">
                                    @csrf
                                    <button class="btn btn-sm btn-success"
                                            onclick="return confirm('Publish {{ $fw->model }} {{ $fw->version }}? Phones will start upgrading to it.')">
                                        <i class="bi bi-broadcast"></i> Publish
                                    </button>
                                </form>
                            @elseif($fw->is_active)
                                <form action="{{ route('admin.phones.firmware.unpublish', $fw) }}" method="POST" class="d-inline">
                                    @csrf
                                    <button class="btn btn-sm btn-outline-secondary"
                                            onclick="return confirm('Stop serving {{ $fw->filename }}? Phones keep whatever they already flashed.')">
                                        <i class="bi bi-pause"></i> Stop
                                    </button>
                                </form>
                            @endif
                            <form action="{{ route('admin.phones.firmware.destroy', $fw) }}" method="POST" class="d-inline">
                                @csrf @method('DELETE')
                                <button class="btn btn-sm btn-outline-danger"
                                        onclick="return confirm('Delete {{ $fw->model }} {{ $fw->version }} from the library?')">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>
                        @endcan
                    </td>
                </tr>
                @if($fw->error)
                <tr class="table-danger">
                    <td colspan="8" class="small">{{ $fw->error }}</td>
                </tr>
                @endif
            @empty
                <tr>
                    <td colspan="8" class="text-center text-muted py-4">
                        Nothing published yet. Add a Grandstream package above, then hit <strong>Publish</strong>.
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

<p class="text-muted small mt-3 mb-0">
    <i class="bi bi-info-circle me-1"></i>
    Phones compare the version baked into the image against their own and only flash on a difference, so publishing is
    safe to repeat. The filename is the vendor's and must never be renamed — a phone asks for that exact name.
</p>

@endsection
