@extends('layouts.admin')

@section('content')

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <a href="{{ route('admin.offboarding.index') }}" class="text-muted text-decoration-none">
            <i class="bi bi-arrow-left me-1"></i>All offboardings
        </a>
        <h1 class="h3 mb-0 mt-1">
            Offboarding #{{ $ow->id }} — {{ $ow->employee?->name ?? 'Unknown employee' }}
            <span class="badge {{ $ow->statusBadgeClass() }} ms-2 align-middle">{{ str_replace('_', ' ', $ow->status) }}</span>
        </h1>
    </div>
    <div class="btn-group">
        @if($ow->token && $ow->token->isValid())
            <form method="POST" action="{{ route('admin.offboarding.resend', $ow) }}" class="d-inline">
                @csrf
                <button class="btn btn-outline-secondary btn-sm" type="submit">
                    <i class="bi bi-envelope me-1"></i>Resend Manager Email
                </button>
            </form>
        @endif

        @can('manage-offboarding')
            @if(! in_array($ow->status, ['completed', 'cancelled']))
                <button type="button" class="btn btn-outline-danger btn-sm" data-bs-toggle="modal" data-bs-target="#cancelModal">
                    <i class="bi bi-x-circle me-1"></i>Cancel
                </button>
            @endif
            @if(! $ow->azure_deleted_at && $ow->employee?->azure_id)
                <button type="button" class="btn btn-danger btn-sm" data-bs-toggle="modal" data-bs-target="#forceDeleteModal">
                    <i class="bi bi-trash me-1"></i>Force Delete Azure User
                </button>
            @endif
        @endcan
    </div>
</div>

@if(session('success'))<div class="alert alert-success py-2">{{ session('success') }}</div>@endif
@if(session('error'))<div class="alert alert-danger py-2">{{ session('error') }}</div>@endif

<div class="row g-3">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header fw-semibold"><i class="bi bi-person me-1"></i>Employee</div>
            <div class="card-body">
                @if($ow->employee)
                    <p class="mb-1"><strong>{{ $ow->employee->name }}</strong></p>
                    <p class="mb-1 text-muted font-monospace small">{{ $ow->employee->email }}</p>
                    <p class="mb-1 small">Status: <span class="badge {{ $ow->employee->statusBadgeClass() }}">{{ $ow->employee->status }}</span></p>
                    @if($ow->employee->extension_number)
                        <p class="mb-0 small">Extension: <code>{{ $ow->employee->extension_number }}</code></p>
                    @endif
                @else
                    <p class="text-muted mb-0">(no local employee record)</p>
                @endif
            </div>
        </div>
    </div>

    <div class="col-md-6">
        <div class="card">
            <div class="card-header fw-semibold"><i class="bi bi-calendar-event me-1"></i>Lifecycle</div>
            <div class="card-body small">
                <p class="mb-1"><strong>Last working day:</strong> {{ $ow->expected_last_day?->format('Y-m-d') }}</p>
                <p class="mb-1"><strong>Azure disabled at:</strong>
                    {{ $ow->azure_disabled_at?->format('Y-m-d H:i') ?? '—' }}
                </p>
                @if($ow->manager_grace_until)
                    <p class="mb-1"><strong>Manager grace until:</strong> {{ $ow->manager_grace_until?->format('Y-m-d') }}</p>
                @endif
                <p class="mb-1"><strong>Delete after:</strong> {{ $ow->delete_after?->format('Y-m-d') ?? 'pending' }}</p>
                <p class="mb-0"><strong>Azure deleted at:</strong>
                    {{ $ow->azure_deleted_at?->format('Y-m-d H:i') ?? '—' }}
                </p>
            </div>
        </div>
    </div>

    <div class="col-12">
        <div class="card">
            <div class="card-header fw-semibold"><i class="bi bi-clipboard-check me-1"></i>Manager Decisions</div>
            <div class="card-body">
                @if(! $ow->email_action)
                    <p class="text-muted mb-0">Manager has not yet responded to the form.</p>
                @else
                <div class="row g-3">
                    <div class="col-md-4">
                        <div class="text-muted small">Email handling</div>
                        <div><strong>{{ ucfirst($ow->email_action) }}</strong></div>
                        @if($ow->email_action === 'forward')
                            <div class="small mt-1">Forward to:
                                @foreach(($ow->forward_emails ?? []) as $e)
                                    <code class="me-1">{{ $e }}</code>
                                @endforeach
                            </div>
                            <div class="small text-muted">Until {{ $ow->forward_until?->format('Y-m-d') }}</div>
                        @endif
                    </div>
                    <div class="col-md-4">
                        <div class="text-muted small">Laptop data</div>
                        <div><strong>{{ ucfirst($ow->laptop_action ?? '—') }}</strong></div>
                    </div>
                    <div class="col-md-4">
                        <div class="text-muted small">Assets</div>
                        <div>
                            <strong>{{ $ow->asset_action === 'transfer' ? 'Transfer' : 'Return to IT' }}</strong>
                        </div>
                        @if($ow->asset_action === 'transfer' && $ow->assetTarget)
                            <div class="small">→ {{ $ow->assetTarget->name }} <span class="text-muted font-monospace">({{ $ow->assetTarget->email }})</span></div>
                        @endif
                    </div>
                </div>
                @endif
            </div>
        </div>
    </div>

    <div class="col-12">
        <div class="card">
            <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
                <span><i class="bi bi-archive me-1"></i>Backups</span>
                <small class="text-muted">{{ $ow->backups->count() }} total</small>
            </div>
            <div class="table-responsive">
                <table class="table mb-0 align-middle small">
                    <thead class="table-light">
                        <tr>
                            <th>Type</th>
                            <th>Source</th>
                            <th>Status</th>
                            <th>Size</th>
                            <th>SHA-256</th>
                            <th>Download Link</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($ow->backups as $b)
                        <tr id="upload-{{ $b->id }}">
                            <td><strong>{{ $b->typeLabel() }}</strong></td>
                            <td>{{ $b->source }}</td>
                            <td>
                                <span class="badge
                                    @if($b->status === 'completed') bg-success
                                    @elseif($b->status === 'failed') bg-danger
                                    @elseif($b->status === 'manual_upload_required') bg-warning text-dark
                                    @else bg-secondary
                                    @endif">{{ $b->status }}</span>
                            </td>
                            <td>{{ $b->humanSize() }}</td>
                            <td><code class="small">{{ $b->file_sha256 ? substr($b->file_sha256, 0, 12) : '—' }}</code></td>
                            <td>
                                @if($b->isDownloadable())
                                    <a href="{{ url('/offboarding/download/' . $b->download_token) }}" class="btn btn-sm btn-outline-success">
                                        <i class="bi bi-download me-1"></i>Download
                                    </a>
                                    <div class="text-muted small">Expires {{ $b->download_expires_at?->format('M d') }}</div>
                                @elseif($b->status === 'manual_upload_required')
                                    @include('admin.offboarding._upload_form', ['backup' => $b])
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td>
                                @if($b->error_message)
                                    <span class="text-danger small" title="{{ $b->error_message }}">
                                        <i class="bi bi-exclamation-triangle"></i>
                                    </span>
                                @endif
                            </td>
                        </tr>
                        @empty
                        <tr><td colspan="7" class="text-center text-muted py-3">No backups yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

{{-- Modals --}}
<div class="modal fade" id="cancelModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" action="{{ route('admin.offboarding.cancel', $ow) }}" class="modal-content">
            @csrf
            <div class="modal-header">
                <h5 class="modal-title">Cancel Offboarding</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted">Actions already executed (Azure group adds, license unassigns, UCM deletes, group removals, etc.) will <strong>NOT</strong> be undone. The workflow simply stops here.</p>
                <label class="form-label">Reason</label>
                <textarea name="reason" rows="3" class="form-control" required maxlength="500"></textarea>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                <button type="submit" class="btn btn-danger">Cancel Offboarding</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="forceDeleteModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" action="{{ route('admin.offboarding.force-delete', $ow) }}" class="modal-content">
            @csrf
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title">Force Delete Azure User</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>This will <strong>immediately delete the Azure user</strong>, skipping the {{ $ow->forward_until ? 'forward-until' : 'retention' }} window.</p>
                <p class="text-muted small">The local Employee record is preserved.</p>
                <label class="form-label">Type <code>CONFIRM</code> to proceed</label>
                <input type="text" name="confirm" class="form-control" required pattern="CONFIRM" placeholder="CONFIRM">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-danger">Delete Azure User</button>
            </div>
        </form>
    </div>
</div>

@endsection
