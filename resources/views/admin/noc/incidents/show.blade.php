@extends('layouts.admin')
@section('content')

<div class="mb-4">
    <div class="d-flex justify-content-between align-items-start">
        <div>
            <h4 class="mb-1 fw-bold">
                <span class="badge {{ $incident->severityBadgeClass() }} me-2">{{ ucfirst($incident->severity) }}</span>
                Incident #{{ $incident->id }}: {{ $incident->title }}
            </h4>
            <small class="text-muted">
                <a href="{{ route('admin.noc.incidents.index') }}" class="text-decoration-none">Incidents</a> / #{{ $incident->id }}
            </small>
        </div>
        <div class="d-flex gap-2">
            <span class="badge {{ $incident->statusBadgeClass() }} fs-6 px-3 py-2">{{ ucfirst($incident->status) }}</span>
            @can('manage-incidents')
            <a href="{{ route('admin.noc.incidents.edit', $incident) }}" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-pencil me-1"></i>Edit
            </a>
            @endcan
        </div>
    </div>
</div>

<div class="row g-4">
    {{-- Main Content --}}
    <div class="col-lg-8">
        {{-- Description --}}
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-transparent"><strong>Description</strong></div>
            <div class="card-body">
                {!! nl2br(e($incident->description ?: 'No description provided.')) !!}
            </div>
        </div>

        {{-- Resolution --}}
        @if($incident->resolution_notes)
        <div class="card shadow-sm mb-4 border-success">
            <div class="card-header bg-success bg-opacity-10"><strong class="text-success"><i class="bi bi-check-circle me-1"></i>Resolution</strong></div>
            <div class="card-body">
                {!! nl2br(e($incident->resolution_notes)) !!}
                @if($incident->resolved_at)
                <div class="text-muted small mt-2">Resolved {{ $incident->resolved_at->format('M d, Y H:i') }} ({{ $incident->resolved_at->diffForHumans() }})</div>
                @endif
            </div>
        </div>
        @endif

        {{-- Linked Alert --}}
        @if($incident->nocEvent)
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-transparent"><strong><i class="bi bi-link-45deg me-1"></i>Linked Alert</strong></div>
            <div class="card-body">
                <div class="d-flex align-items-center gap-2">
                    <span class="badge {{ $incident->nocEvent->severityBadgeClass() }}">{{ ucfirst($incident->nocEvent->severity) }}</span>
                    <span class="badge bg-dark bg-opacity-10 text-dark border"><i class="{{ $incident->nocEvent->moduleIcon() }} me-1"></i>{{ $incident->nocEvent->moduleLabel() }}</span>
                    <span class="fw-semibold">{{ $incident->nocEvent->title }}</span>
                </div>
                <div class="text-muted small mt-1">{{ $incident->nocEvent->message }}</div>
            </div>
        </div>
        @endif

        {{-- Comments --}}
        <div class="card shadow-sm">
            <div class="card-header bg-transparent d-flex justify-content-between">
                <strong><i class="bi bi-chat-dots me-1"></i>Comments</strong>
                <span class="badge bg-secondary">{{ $incident->comments->count() }}</span>
            </div>
            <div class="card-body">
                @forelse($incident->comments as $comment)
                <div class="d-flex gap-3 mb-3 pb-3 {{ !$loop->last ? 'border-bottom' : '' }}">
                    <div class="avatar-circle flex-shrink-0" style="width:32px;height:32px;font-size:12px;background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;border-radius:50%;display:flex;align-items:center;justify-content:center;">
                        {{ strtoupper(substr($comment->user?->name ?? 'U', 0, 1)) }}
                    </div>
                    <div>
                        <div class="fw-semibold small">{{ $comment->user?->name ?? 'Unknown' }} <span class="text-muted fw-normal">{{ $comment->created_at->diffForHumans() }}</span></div>
                        <div class="small">{{ $comment->body }}</div>
                    </div>
                </div>
                @empty
                <div class="text-muted small text-center py-2">No comments yet.</div>
                @endforelse

                @can('manage-incidents')
                <hr>
                <form method="POST" action="{{ route('admin.noc.incidents.comment', $incident) }}">
                    @csrf
                    <div class="mb-2">
                        <textarea name="body" class="form-control form-control-sm" rows="2" required placeholder="Add a comment..."></textarea>
                    </div>
                    <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-send me-1"></i>Post Comment</button>
                </form>
                @endcan
            </div>
        </div>
    </div>

    {{-- Sidebar --}}
    <div class="col-lg-4">
        <div class="card shadow-sm">
            <div class="card-header bg-transparent"><strong>Details</strong></div>
            <div class="card-body small">
                <div class="mb-3">
                    <div class="text-muted mb-1">Status</div>
                    <span class="badge {{ $incident->statusBadgeClass() }}">{{ ucfirst($incident->status) }}</span>
                </div>
                <div class="mb-3">
                    <div class="text-muted mb-1">Severity</div>
                    <span class="badge {{ $incident->severityBadgeClass() }}">{{ ucfirst($incident->severity) }}</span>
                </div>
                <div class="mb-3">
                    <div class="text-muted mb-1">Branch</div>
                    <div>{{ $incident->branch?->name ?: '—' }}</div>
                </div>
                <div class="mb-3">
                    <div class="text-muted mb-1">Assigned To</div>
                    <div>{{ $incident->assignedTo?->name ?: 'Unassigned' }}</div>
                </div>
                <div class="mb-3">
                    <div class="text-muted mb-1">Created By</div>
                    <div>{{ $incident->createdBy?->name ?? 'Unknown' }}</div>
                </div>
                <div class="mb-3">
                    <div class="text-muted mb-1">Created</div>
                    <div>{{ $incident->created_at->format('M d, Y H:i') }}</div>
                </div>
                <div class="mb-3">
                    <div class="text-muted mb-1">Duration</div>
                    <div>{{ $incident->durationHuman() }}</div>
                </div>

                @can('manage-incidents')
                <hr>
                {{-- Quick status change --}}
                <form method="POST" action="{{ route('admin.noc.incidents.update', $incident) }}">
                    @csrf @method('PUT')
                    <input type="hidden" name="title" value="{{ $incident->title }}">
                    <input type="hidden" name="severity" value="{{ $incident->severity }}">
                    <div class="mb-2">
                        <label class="form-label fw-semibold">Change Status</label>
                        <select name="status" class="form-select form-select-sm">
                            @foreach(\App\Models\Incident::statuses() as $k => $v)
                            <option value="{{ $k }}" {{ $incident->status == $k ? 'selected' : '' }}>{{ $v }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label fw-semibold">Assign To</label>
                        <select name="assigned_to" class="form-select form-select-sm">
                            <option value="">Unassigned</option>
                            @foreach($users as $u)
                            <option value="{{ $u->id }}" {{ $incident->assigned_to == $u->id ? 'selected' : '' }}>{{ $u->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label fw-semibold">Resolution Notes</label>
                        <textarea name="resolution_notes" class="form-control form-control-sm" rows="2">{{ $incident->resolution_notes }}</textarea>
                    </div>
                    <button type="submit" class="btn btn-sm btn-primary w-100"><i class="bi bi-check-lg me-1"></i>Update</button>
                </form>
                @endcan
            </div>
        </div>
    </div>
</div>

@endsection
