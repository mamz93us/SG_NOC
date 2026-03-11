@extends('layouts.admin')
@section('content')

<div class="d-flex align-items-center gap-2 mb-3">
    <h4 class="mb-0 fw-bold"><i class="bi bi-collection me-2 text-primary"></i>Device Models</h4>
    <button class="btn btn-sm btn-primary ms-auto" data-bs-toggle="modal" data-bs-target="#dmAddModal">
        <i class="bi bi-plus-lg me-1"></i>Add Model
    </button>
</div>

{{-- Filters --}}
<form method="GET" class="row g-2 mb-3">
    <div class="col-md-4">
        <input type="text" name="search" class="form-control form-control-sm"
               placeholder="Search name / manufacturer…" value="{{ request('search') }}">
    </div>
    <div class="col-md-3">
        <select name="type" class="form-select form-select-sm">
            <option value="">— All Types —</option>
            @foreach($types as $t)
            <option value="{{ $t }}" {{ request('type') === $t ? 'selected' : '' }}>{{ ucfirst($t) }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-auto">
        <button class="btn btn-sm btn-outline-primary" type="submit">Filter</button>
        <a href="{{ route('admin.devices.models.index') }}" class="btn btn-sm btn-outline-secondary">Reset</a>
    </div>
</form>

@if(session('success'))
<div class="alert alert-success py-2">{{ session('success') }}</div>
@endif
@if(session('error'))
<div class="alert alert-danger py-2">{{ session('error') }}</div>
@endif
@if(session('info'))
<div class="alert alert-info py-2">{{ session('info') }}</div>
@endif

<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Name</th>
                    <th>Manufacturer</th>
                    <th>Type</th>
                    <th>Latest Firmware</th>
                    <th class="text-center">Devices</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
            @forelse($models as $m)
            <tr id="dm-row-{{ $m->id }}">
                <td class="fw-semibold">{{ $m->name }}</td>
                <td>{{ $m->manufacturer ?? '—' }}</td>
                <td>
                    @if($m->device_type)
                    <span class="badge bg-secondary">{{ ucfirst($m->device_type) }}</span>
                    @else
                    <span class="text-muted small">—</span>
                    @endif
                </td>
                <td class="font-monospace small">{{ $m->latest_firmware ?? '—' }}</td>
                <td class="text-center">
                    <span class="badge bg-primary rounded-pill">{{ $m->devices_count }}</span>
                </td>
                <td class="text-end">
                    <button class="btn btn-sm btn-outline-secondary"
                            onclick="dmEditOpen({{ $m->id }}, {{ json_encode($m->name) }}, {{ json_encode($m->manufacturer ?? '') }}, {{ json_encode($m->device_type ?? '') }}, {{ json_encode($m->latest_firmware ?? '') }}, {{ json_encode($m->release_notes ?? '') }})"
                            title="Edit">
                        <i class="bi bi-pencil"></i>
                    </button>
                    @if($m->devices_count === 0)
                    <button class="btn btn-sm btn-outline-danger"
                            onclick="dmDelete({{ $m->id }}, {{ json_encode($m->displayName()) }})"
                            title="Delete">
                        <i class="bi bi-trash"></i>
                    </button>
                    @else
                    <button class="btn btn-sm btn-outline-danger" disabled title="In use by {{ $m->devices_count }} device(s)">
                        <i class="bi bi-trash"></i>
                    </button>
                    @endif
                </td>
            </tr>
            @empty
            <tr>
                <td colspan="6" class="text-center text-muted py-4">No device models found.</td>
            </tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

{{ $models->links() }}

{{-- ── Add Modal ──────────────────────────────────────────────────────────── --}}
<div class="modal fade" id="dmAddModal" tabindex="-1">
    <div class="modal-dialog">
        <form id="dmAddForm" method="POST" action="{{ route('admin.devices.models.store') }}">
            @csrf
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title fw-semibold"><i class="bi bi-plus-circle me-1"></i>Add Device Model</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="dmAddError" class="alert alert-danger d-none py-2"></div>
                    @include('admin.devices.models._form', ['prefix' => 'add'])
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create</button>
                </div>
            </div>
        </form>
    </div>
</div>

{{-- ── Edit Modal ─────────────────────────────────────────────────────────── --}}
<div class="modal fade" id="dmEditModal" tabindex="-1">
    <div class="modal-dialog">
        <form id="dmEditForm" method="POST">
            @csrf @method('PUT')
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title fw-semibold"><i class="bi bi-pencil me-1"></i>Edit Device Model</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="dmEditError" class="alert alert-danger d-none py-2"></div>
                    @include('admin.devices.models._form', ['prefix' => 'edit'])
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </div>
        </form>
    </div>
</div>

{{-- ── Delete confirm ─────────────────────────────────────────────────────── --}}
<div class="modal fade" id="dmDeleteModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title fw-semibold text-danger"><i class="bi bi-trash me-1"></i>Delete Model</h6>
                <button type="button" class="btn-close btn-sm" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="mb-0">Delete <strong id="dmDeleteName"></strong>?<br>
                <span class="text-muted small">This cannot be undone.</span></p>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger btn-sm" id="dmDeleteConfirm">Delete</button>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
const dmCsrf     = document.querySelector('meta[name="csrf-token"]')?.content || '';
const dmBaseUrl  = '{{ url("admin/devices/models") }}';

// ── Add (AJAX POST) ───────────────────────────────────────────────────────────
document.getElementById('dmAddForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const errEl = document.getElementById('dmAddError');
    errEl.classList.add('d-none');

    const body = Object.fromEntries(new FormData(this));
    const res = await fetch(this.action, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': dmCsrf, 'Accept': 'application/json' },
        body: JSON.stringify(body),
    });
    const data = await res.json();
    if (!res.ok) {
        const msgs = Object.values(data.errors || {}).flat();
        errEl.textContent = msgs[0] || data.message || 'Error creating model.';
        errEl.classList.remove('d-none');
        return;
    }
    bootstrap.Modal.getInstance(document.getElementById('dmAddModal')).hide();
    location.reload();
});

// ── Edit ──────────────────────────────────────────────────────────────────────
function dmEditOpen(id, name, manufacturer, device_type, firmware, notes) {
    const form = document.getElementById('dmEditForm');
    form.action = `${dmBaseUrl}/${id}`;
    form.querySelector('[name="edit_name"]').value         = name;
    form.querySelector('[name="edit_manufacturer"]').value = manufacturer;
    form.querySelector('[name="edit_device_type"]').value  = device_type;
    form.querySelector('[name="edit_latest_firmware"]').value = firmware;
    form.querySelector('[name="edit_release_notes"]').value   = notes;
    document.getElementById('dmEditError').classList.add('d-none');
    new bootstrap.Modal(document.getElementById('dmEditModal')).show();
}

document.getElementById('dmEditForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const errEl = document.getElementById('dmEditError');
    errEl.classList.add('d-none');

    // Remap field names (strip prefix)
    const fd = new FormData(this);
    const body = {};
    fd.forEach((v, k) => { body[k.replace('edit_', '')] = v; });
    delete body['_token']; delete body['_method'];

    const res = await fetch(this.action, {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': dmCsrf, 'Accept': 'application/json' },
        body: JSON.stringify(body),
    });
    const data = await res.json();
    if (!res.ok) {
        const msgs = Object.values(data.errors || {}).flat();
        errEl.textContent = msgs[0] || data.message || 'Error updating model.';
        errEl.classList.remove('d-none');
        return;
    }
    bootstrap.Modal.getInstance(document.getElementById('dmEditModal')).hide();
    location.reload();
});

// ── Delete ────────────────────────────────────────────────────────────────────
let dmDeleteId = null;

function dmDelete(id, name) {
    dmDeleteId = id;
    document.getElementById('dmDeleteName').textContent = name;
    new bootstrap.Modal(document.getElementById('dmDeleteModal')).show();
}

document.getElementById('dmDeleteConfirm').addEventListener('click', async function() {
    if (!dmDeleteId) return;
    const res = await fetch(`${dmBaseUrl}/${dmDeleteId}`, {
        method: 'DELETE',
        headers: { 'X-CSRF-TOKEN': dmCsrf, 'Accept': 'application/json' },
    });
    if (res.ok) {
        bootstrap.Modal.getInstance(document.getElementById('dmDeleteModal')).hide();
        location.reload();
    } else {
        const data = await res.json();
        alert(data.error || 'Could not delete model.');
    }
});
</script>
@endpush

@endsection
