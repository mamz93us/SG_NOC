@extends('layouts.admin')

@section('content')

<div class="d-flex align-items-center mb-4">
    <div>
        <h1 class="h3 mb-0 fw-bold">Email Signatures</h1>
        <small class="text-muted">Manage branded HTML signature templates per domain and email type</small>
    </div>
    <div class="ms-auto d-flex gap-2">
        @can('manage-signatures')
        <a href="{{ route('admin.signatures.create') }}" class="btn btn-primary btn-sm">
            <i class="bi bi-plus-circle me-1"></i>New Template
        </a>
        @endcan
    </div>
</div>

@if(session('success'))
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        {{ session('success') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
@endif

{{-- ── Variable reference card ── --}}
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-transparent border-0 pb-0 d-flex align-items-center justify-content-between"
         role="button" data-bs-toggle="collapse" data-bs-target="#varRef" aria-expanded="false">
        <span class="fw-semibold"><i class="bi bi-code-slash me-2 text-muted"></i>Available Template Variables</span>
        <i class="bi bi-chevron-down text-muted"></i>
    </div>
    <div class="collapse" id="varRef">
        <div class="card-body pt-2">
            <div class="row g-3">
                <div class="col-md-4">
                    <p class="text-muted small fw-semibold mb-1">Employee (Azure / SG_NOC)</p>
                    <table class="table table-sm table-borderless mb-0" style="font-size:12px;">
                        <tr><td class="font-monospace text-danger pe-2">&#123;&#123;name&#125;&#125;</td><td class="text-muted">Full display name</td></tr>
                        <tr><td class="font-monospace text-danger pe-2">&#123;&#123;first_name&#125;&#125;</td><td class="text-muted">First name only</td></tr>
                        <tr><td class="font-monospace text-danger pe-2">&#123;&#123;job_title&#125;&#125;</td><td class="text-muted">Job title</td></tr>
                        <tr><td class="font-monospace text-danger pe-2">&#123;&#123;department&#125;&#125;</td><td class="text-muted">Department</td></tr>
                        <tr><td class="font-monospace text-danger pe-2">&#123;&#123;company&#125;&#125;</td><td class="text-muted">Company name</td></tr>
                        <tr><td class="font-monospace text-danger pe-2">&#123;&#123;email&#125;&#125;</td><td class="text-muted">Work email / UPN</td></tr>
                        <tr><td class="font-monospace text-danger pe-2">&#123;&#123;phone&#125;&#125;</td><td class="text-muted">Office phone</td></tr>
                        <tr><td class="font-monospace text-danger pe-2">&#123;&#123;mobile&#125;&#125;</td><td class="text-muted">Mobile phone</td></tr>
                        <tr><td class="font-monospace text-danger pe-2">&#123;&#123;extension&#125;&#125;</td><td class="text-muted">PBX extension</td></tr>
                    </table>
                </div>
                <div class="col-md-4">
                    <p class="text-muted small fw-semibold mb-1">Branch</p>
                    <table class="table table-sm table-borderless mb-0" style="font-size:12px;">
                        <tr><td class="font-monospace text-danger pe-2">&#123;&#123;branch_name&#125;&#125;</td><td class="text-muted">Office name</td></tr>
                        <tr><td class="font-monospace text-danger pe-2">&#123;&#123;branch_city&#125;&#125;</td><td class="text-muted">City</td></tr>
                        <tr><td class="font-monospace text-danger pe-2">&#123;&#123;branch_address&#125;&#125;</td><td class="text-muted">Street address</td></tr>
                        <tr><td class="font-monospace text-danger pe-2">&#123;&#123;branch_phone&#125;&#125;</td><td class="text-muted">Office phone</td></tr>
                    </table>
                    <p class="text-muted small fw-semibold mb-1 mt-3">Template</p>
                    <table class="table table-sm table-borderless mb-0" style="font-size:12px;">
                        <tr><td class="font-monospace text-danger pe-2">&#123;&#123;logo_url&#125;&#125;</td><td class="text-muted">Logo from template setting</td></tr>
                        <tr><td class="font-monospace text-danger pe-2">&#123;&#123;primary_color&#125;&#125;</td><td class="text-muted">Brand colour (#d81f2a)</td></tr>
                        <tr><td class="font-monospace text-danger pe-2">&#123;&#123;year&#125;&#125;</td><td class="text-muted">Current year</td></tr>
                    </table>
                </div>
                <div class="col-md-4">
                    <p class="text-muted small fw-semibold mb-1">Conditional blocks</p>
                    <pre class="bg-light rounded p-2 mb-0" style="font-size:11px;">&#123;&#123;#if mobile&#125;&#125;
  &lt;span&gt;&#123;&#123;mobile&#125;&#125;&lt;/span&gt;
&#123;&#123;/if&#125;&#125;</pre>
                    <p class="text-muted small mt-2 mb-0">The block is removed entirely when the variable is empty, avoiding blank lines in the rendered output.</p>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- ── Template table ── --}}
<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        @if($templates->isEmpty())
            <div class="text-center py-5 text-muted">
                <i class="bi bi-envelope-open fs-1 d-block mb-2"></i>
                No signature templates yet.
                @can('manage-signatures')
                    <a href="{{ route('admin.signatures.create') }}">Create the first one.</a>
                @endcan
            </div>
        @else
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="width:32px;">#</th>
                        <th>Name</th>
                        <th>Domain</th>
                        <th>Type</th>
                        <th>Colour</th>
                        <th>Active</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                @foreach($templates as $tpl)
                    <tr>
                        <td class="text-muted small">{{ $tpl->sort_order }}</td>
                        <td class="fw-semibold">{{ $tpl->name }}</td>
                        <td>
                            @if($tpl->domain)
                                <span class="badge bg-light text-dark border font-monospace">{{ $tpl->domain }}</span>
                            @else
                                <span class="text-muted small">All domains</span>
                            @endif
                        </td>
                        <td>
                            <span class="badge {{ $tpl->typeBadgeClass() }}">{{ $tpl->typeLabel() }}</span>
                        </td>
                        <td>
                            <span class="d-inline-flex align-items-center gap-2">
                                <span style="display:inline-block;width:16px;height:16px;border-radius:3px;background:{{ $tpl->primary_color }};border:1px solid rgba(0,0,0,.15);"></span>
                                <code class="small">{{ $tpl->primary_color }}</code>
                            </span>
                        </td>
                        <td>
                            @can('manage-signatures')
                            <form method="POST" action="{{ route('admin.signatures.update', $tpl) }}" class="d-inline">
                                @csrf @method('PUT')
                                <input type="hidden" name="name" value="{{ $tpl->name }}">
                                <input type="hidden" name="domain" value="{{ $tpl->domain }}">
                                <input type="hidden" name="type" value="{{ $tpl->type }}">
                                <input type="hidden" name="html_body" value="{{ $tpl->html_body }}">
                                <input type="hidden" name="plain_text_body" value="{{ $tpl->plain_text_body }}">
                                <input type="hidden" name="logo_url" value="{{ $tpl->logo_url }}">
                                <input type="hidden" name="primary_color" value="{{ $tpl->primary_color }}">
                                <input type="hidden" name="sort_order" value="{{ $tpl->sort_order }}">
                                <input type="hidden" name="is_active" value="{{ $tpl->is_active ? 0 : 1 }}">
                                <button type="submit" class="btn btn-sm p-0 border-0 bg-transparent"
                                        title="{{ $tpl->is_active ? 'Deactivate' : 'Activate' }}">
                                    <i class="bi bi-toggle-{{ $tpl->is_active ? 'on text-success' : 'off text-secondary' }} fs-5"></i>
                                </button>
                            </form>
                            @else
                                <i class="bi bi-toggle-{{ $tpl->is_active ? 'on text-success' : 'off text-secondary' }} fs-5"></i>
                            @endcan
                        </td>
                        <td class="text-end">
                            <div class="d-flex gap-1 justify-content-end">
                                {{-- Quick preview button --}}
                                <button type="button" class="btn btn-sm btn-outline-secondary"
                                        title="Preview with sample data"
                                        onclick="quickPreview({{ $tpl->id }})">
                                    <i class="bi bi-eye"></i>
                                </button>
                                @can('manage-signatures')
                                <a href="{{ route('admin.signatures.edit', $tpl) }}"
                                   class="btn btn-sm btn-outline-primary" title="Edit">
                                    <i class="bi bi-pencil"></i>
                                </a>
                                <form method="POST" action="{{ route('admin.signatures.duplicate', $tpl) }}" class="d-inline">
                                    @csrf
                                    <button type="submit" class="btn btn-sm btn-outline-secondary" title="Duplicate">
                                        <i class="bi bi-copy"></i>
                                    </button>
                                </form>
                                <form method="POST" action="{{ route('admin.signatures.destroy', $tpl) }}" class="d-inline"
                                      onsubmit="return confirm('Delete {{ addslashes($tpl->name) }}?')">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                                @endcan
                            </div>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>
</div>

{{-- ── Quick preview modal ── --}}
<div class="modal fade" id="previewModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Signature Preview</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3 d-flex gap-2 align-items-center">
                    <input type="text" id="previewUpn" class="form-control form-control-sm"
                           placeholder="UPN / email to preview with (leave blank for sample data)"
                           style="max-width:320px;">
                    <button class="btn btn-sm btn-outline-secondary" onclick="reloadPreview()">
                        <i class="bi bi-arrow-clockwise me-1"></i>Refresh
                    </button>
                </div>
                <div class="p-3 border rounded bg-white" style="min-height:140px;" id="previewContainer">
                    <span class="text-muted small">Loading…</span>
                </div>
            </div>
        </div>
    </div>
</div>

@endsection

@push('scripts')
<script>
let _currentPreviewId = null;

function quickPreview(id) {
    _currentPreviewId = id;
    document.getElementById('previewContainer').innerHTML = '<span class="text-muted small">Loading…</span>';
    const modal = new bootstrap.Modal(document.getElementById('previewModal'));
    modal.show();
    loadPreview(id, '');
}

function reloadPreview() {
    if (_currentPreviewId) loadPreview(_currentPreviewId, document.getElementById('previewUpn').value);
}

function loadPreview(id, upn) {
    // Fetch the saved template via an inline preview endpoint
    fetch('{{ route('admin.signatures.preview-saved') }}', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
        },
        body: JSON.stringify({ id, upn }),
    })
    .then(r => r.json())
    .then(d => {
        document.getElementById('previewContainer').innerHTML = d.html || '<span class="text-muted">No output</span>';
    })
    .catch(() => {
        document.getElementById('previewContainer').innerHTML = '<span class="text-danger">Preview failed.</span>';
    });
}
</script>
@endpush
