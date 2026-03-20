@extends('layouts.admin')
@section('content')

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold">
            {{ $task->title }}
            @php
                $sColors = ['todo'=>'secondary','in_progress'=>'primary','blocked'=>'danger','on_hold'=>'warning','done'=>'success'];
                $pColors = ['urgent'=>'danger','high'=>'warning','medium'=>'primary','low'=>'secondary'];
            @endphp
            <span class="badge bg-{{ $sColors[$task->status] ?? 'secondary' }} ms-2">{{ str_replace('_', ' ', ucfirst($task->status)) }}</span>
            <span class="badge bg-{{ $pColors[$task->priority] ?? 'secondary' }} ms-1">{{ ucfirst($task->priority) }}</span>
            <span class="badge bg-info text-dark ms-1">{{ ucfirst($task->type) }}</span>
        </h4>
        <small class="text-muted">Created {{ $task->created_at->diffForHumans() }} by {{ $task->createdBy?->name ?? 'Unknown' }}</small>
    </div>
    <div class="d-flex gap-2">
        <a href="{{ route('admin.tasks.edit', $task) }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-pencil me-1"></i>Edit</a>
        <a href="{{ route('admin.tasks.index') }}" class="btn btn-outline-dark btn-sm"><i class="bi bi-arrow-left me-1"></i>Back</a>
    </div>
</div>

@if(session('success'))
<div class="alert alert-success alert-dismissible fade show py-2" role="alert">
    {{ session('success') }}<button type="button" class="btn-close btn-sm" data-bs-dismiss="alert"></button>
</div>
@endif

<div class="row g-4">
    {{-- Left column --}}
    <div class="col-lg-8">
        {{-- Description --}}
        <div class="card shadow-sm mb-3">
            <div class="card-header py-2 fw-semibold"><i class="bi bi-text-paragraph me-1"></i>Description</div>
            <div class="card-body">
                @if($task->description)
                    <p class="mb-0" style="white-space: pre-wrap;">{{ $task->description }}</p>
                @else
                    <p class="text-muted mb-0">No description provided.</p>
                @endif
            </div>
        </div>

        {{-- Details --}}
        <div class="card shadow-sm mb-3">
            <div class="card-header py-2 fw-semibold"><i class="bi bi-info-circle me-1"></i>Details</div>
            <div class="card-body">
                <table class="table table-sm mb-0">
                    <tr><th class="text-muted" style="width:150px">Type</th><td>{{ ucfirst($task->type) }}</td></tr>
                    <tr><th class="text-muted">Branch</th><td>{{ $task->branch?->name ?? '—' }}</td></tr>
                    <tr><th class="text-muted">Assigned To</th><td>{{ $task->assignedTo?->name ?? 'Unassigned' }}</td></tr>
                    <tr><th class="text-muted">Created By</th><td>{{ $task->createdBy?->name ?? '—' }}</td></tr>
                    <tr>
                        <th class="text-muted">Due Date</th>
                        <td>
                            @if($task->due_date)
                                <span class="{{ $task->due_date->isPast() && $task->status !== 'done' ? 'text-danger fw-bold' : '' }}">
                                    {{ $task->due_date->format('Y-m-d') }}
                                    @if($task->due_date->isPast() && $task->status !== 'done')
                                        <span class="badge bg-danger ms-1">Overdue</span>
                                    @endif
                                </span>
                            @else
                                —
                            @endif
                        </td>
                    </tr>
                    @if($task->completed_at)
                    <tr><th class="text-muted">Completed At</th><td>{{ $task->completed_at->format('Y-m-d H:i') }}</td></tr>
                    @endif
                </table>
            </div>
        </div>

        {{-- Comments --}}
        <div class="card shadow-sm">
            <div class="card-header py-2 fw-semibold"><i class="bi bi-chat-dots me-1"></i>Comments ({{ $task->comments->count() }})</div>
            <div class="card-body">
                @forelse($task->comments as $comment)
                <div class="d-flex mb-3">
                    <div class="avatar-circle d-flex align-items-center justify-content-center me-2 flex-shrink-0" style="width:32px;height:32px;border-radius:50%;background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;font-size:0.75rem;font-weight:600;">
                        {{ strtoupper(substr($comment->user?->name ?? '?', 0, 1)) }}
                    </div>
                    <div class="flex-grow-1">
                        <div class="small">
                            <strong>{{ $comment->user?->name ?? 'Unknown' }}</strong>
                            <span class="text-muted ms-1">{{ $comment->created_at->diffForHumans() }}</span>
                        </div>
                        <p class="mb-0 small" style="white-space: pre-wrap;">{{ $comment->body }}</p>
                    </div>
                </div>
                @empty
                <p class="text-muted small mb-0">No comments yet.</p>
                @endforelse

                <hr>
                <form method="POST" action="{{ route('admin.tasks.comment', $task) }}">
                    @csrf
                    <div class="mb-2">
                        <textarea name="body" class="form-control form-control-sm" rows="3" placeholder="Write a comment..." required maxlength="5000">{{ old('body') }}</textarea>
                        @error('body')<div class="text-danger small">{{ $message }}</div>@enderror
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-send me-1"></i>Post Comment</button>
                </form>
            </div>
        </div>
    </div>

    {{-- Right column --}}
    <div class="col-lg-4">
        {{-- Time Tracking --}}
        <div class="card shadow-sm mb-3">
            <div class="card-header py-2 fw-semibold"><i class="bi bi-clock me-1"></i>Time Tracking</div>
            <div class="card-body">
                <div class="d-flex justify-content-between mb-2 small">
                    <span>Estimated</span>
                    <strong>{{ $task->estimated_hours ? $task->estimated_hours . 'h' : '—' }}</strong>
                </div>
                <div class="d-flex justify-content-between mb-2 small">
                    <span>Logged</span>
                    <strong>{{ $task->logged_hours }}h</strong>
                </div>
                @if($task->estimated_hours && $task->estimated_hours > 0)
                @php
                    $pct = min(100, round(($task->logged_hours / $task->estimated_hours) * 100));
                    $barColor = $pct > 100 ? 'bg-danger' : ($pct > 75 ? 'bg-warning' : 'bg-primary');
                @endphp
                <div class="progress mb-2" style="height: 8px;">
                    <div class="progress-bar {{ $barColor }}" style="width: {{ $pct }}%"></div>
                </div>
                <div class="text-end text-muted small">{{ $pct }}% of estimate</div>
                @endif

                <hr>
                <form method="POST" action="{{ route('admin.tasks.log-time', $task) }}">
                    @csrf
                    <div class="input-group input-group-sm">
                        <input type="number" name="hours" class="form-control" placeholder="Hours" step="0.5" min="0.5" max="24" required>
                        <button type="submit" class="btn btn-outline-primary"><i class="bi bi-plus-lg"></i> Log</button>
                    </div>
                    @error('hours')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                </form>
            </div>
        </div>

        {{-- Quick Status --}}
        <div class="card shadow-sm">
            <div class="card-header py-2 fw-semibold"><i class="bi bi-arrow-repeat me-1"></i>Quick Status</div>
            <div class="card-body">
                <div class="d-grid gap-1">
                    @foreach(['todo'=>'secondary','in_progress'=>'primary','blocked'=>'danger','on_hold'=>'warning','done'=>'success'] as $st => $color)
                    @if($task->status !== $st)
                    <form method="POST" action="{{ route('admin.tasks.update-status', $task) }}" class="d-inline">
                        @csrf
                        <input type="hidden" name="status" value="{{ $st }}">
                        <button type="submit" class="btn btn-outline-{{ $color }} btn-sm w-100 text-start">
                            <i class="bi bi-arrow-right me-1"></i>{{ str_replace('_', ' ', ucfirst($st)) }}
                        </button>
                    </form>
                    @endif
                    @endforeach
                </div>
            </div>
        </div>
    </div>
</div>

@endsection
