@extends('layouts.admin')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <a href="{{ route('admin.network.monitoring.index') }}" class="btn btn-link link-secondary ps-0 mb-2">
            <i class="bi bi-arrow-left me-1"></i> Back to Monitoring
        </a>
        <h2 class="h3 mb-1">Managed MIBs</h2>
        <p class="text-muted small mb-0">Upload and manage Custom SNMP MIBs for specific vendors</p>
    </div>
    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#uploadMibModal">
        <i class="bi bi-upload me-1"></i> Upload MIB
    </button>
</div>

<div class="card shadow-sm border-0">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="bg-light">
                    <tr>
                        <th class="ps-4">MIB Name</th>
                        <th>File Reference</th>
                        <th>Created At</th>
                        <th class="text-end pe-4">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($mibs as $mib)
                    <tr>
                        <td class="ps-4 fw-medium">{{ $mib->name }}</td>
                        <td><code class="text-muted">{{ basename($mib->file_path) }}</code></td>
                        <td>{{ $mib->created_at->format('Y-m-d H:i') }}</td>
                        <td class="text-end pe-4">
                            <a href="{{ route('admin.network.monitoring.mibs.view', $mib) }}" class="btn btn-sm btn-link text-info" title="Preview MIB Content">
                                <i class="bi bi-eye"></i>
                            </a>
                            <button class="btn btn-sm btn-link text-danger" disabled title="Delete Protected"><i class="bi bi-trash"></i></button>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="4" class="text-center py-5 text-muted">
                            <i class="bi bi-file-earmark-code fs-1 d-block mb-3 opacity-25"></i>
                            No custom MIBs have been uploaded yet.
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Upload MIB Modal -->
<div class="modal fade" id="uploadMibModal" tabindex="-1">
    <div class="modal-dialog">
        <form action="{{ route('admin.network.monitoring.mibs.store') }}" method="POST" enctype="multipart/form-data">
            @csrf
            <div class="modal-content border-0 shadow">
                <div class="modal-header bg-primary text-white py-3">
                    <h5 class="modal-title">Upload MIB File</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-bold small">MIB Module Name</label>
                        <input type="text" name="name" class="form-control" placeholder="e.g. CISCO-ENVMON-MIB" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold small">Description (Optional)</label>
                        <textarea name="description" class="form-control" rows="2" placeholder="What is this used for?"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold small">MIB File</label>
                        <input type="file" name="file" class="form-control" accept=".txt,.mib,.my" required>
                        <div class="form-text">Standard text formatted MIB files.</div>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-link link-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary px-4"><i class="bi bi-upload me-1"></i> Upload</button>
                </div>
            </div>
        </form>
    </div>
</div>
@endsection
