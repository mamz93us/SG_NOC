@extends('layouts.admin')
@section('content')

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-list-task me-2 text-primary"></i>IT Task Manager</h4>
        <small class="text-muted">Track and manage IT tasks across the organisation</small>
    </div>
    <div class="d-flex gap-2">
        <a href="{{ route('admin.tasks.my-tasks') }}" class="btn btn-outline-info btn-sm">
            <i class="bi bi-person-check me-1"></i>My Tasks
        </a>
        <a href="{{ route('admin.tasks.kanban') }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-kanban me-1"></i>Kanban
        </a>
        <a href="{{ route('admin.tasks.create') }}" class="btn btn-primary btn-sm">
            <i class="bi bi-plus-lg me-1"></i>New Task
        </a>
    </div>
</div>

{{-- Status cards --}}
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card border-start border-4 border-secondary shadow-sm">
            <div class="card-body py-2 px-3">
                <div class="text-muted small">Todo</div>
                <div class="fs-4 fw-bold">{{ $statusCounts['todo'] }}</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-start border-4 border-primary shadow-sm">
            <div class="card-body py-2 px-3">
                <div class="text-muted small">In Progress</div>
                <div class="fs-4 fw-bold text-primary">{{ $statusCounts['in_progress'] }}</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-start border-4 border-danger shadow-sm">
            <div class="card-body py-2 px-3">
                <div class="text-muted small">Blocked</div>
                <div class="fs-4 fw-bold text-danger">{{ $statusCounts['blocked'] }}</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-start border-4 border-success shadow-sm">
            <div class="card-body py-2 px-3">
                <div class="text-muted small">Done</div>
                <div class="fs-4 fw-bold text-success">{{ $statusCounts['done'] }}</div>
            </div>
        </div>
    </div>
</div>

{{-- Filters --}}
<form method="GET" class="row g-2 mb-3 align-items-end">
    <div class="col-auto">
        <select name="status" class="form-select form-select-sm">
            <option value="">All Statuses</option>
            @foreach(['todo'=>'Todo','in_progress'=>'In Progress','blocked'=>'Blocked','on_hold'=>'On Hold','done'=>'Done'] as $val => $label)
            <option value="{{ $val }}" {{ ($filters['status'] ?? '') == $val ? 'selected' : '' }}>{{ $label }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-auto">
        <select name="priority" class="form-select form-select-sm">
            <option value="">All Priorities</option>
            @foreach(['low'=>'Low','medium'=>'Medium','high'=>'High','urgent'=>'Urgent'] as $val => $label)
            <option value="{{ $val }}" {{ ($filters['priority'] ?? '') == $val ? 'selected' : '' }}>{{ $label }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-auto">
        <select name="branch_id" class="form-select form-select-sm">
            <option value="">All Branches</option>
            @foreach($branches as $b)
            <option value="{{ $b->id }}" {{ ($filters['branch_id'] ?? '') == $b->id ? 'selected' : '' }}>{{ $b->name }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-auto">
        <select name="assigned_to" class="form-select form-select-sm">
            <option value="">All Users</option>
            @foreach($users as $u)
            <option value="{{ $u->id }}" {{ ($filters['assigned_to'] ?? '') == $u->id ? 'selected' : '' }}>{{ $u->name }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-auto">
        <input type="text" name="search" class="form-control form-control-sm" placeholder="Search title / description" value="{{ $filters['search'] ?? '' }}">
    </div>
    <div class="col-auto">
        <div class="form-check mt-1">
            <input type="checkbox" name="overdue" value="1" class="form-check-input" id="filterOverdue" {{ !empty($filters['overdue']) ? 'checked' : '' }}>
            <label class="form-check-label small" for="filterOverdue">Overdue only</label>
        </div>
    </div>
    <div class="col-auto">
        <button type="submit" class="btn btn-sm btn-secondary">Filter</button>
        <a href="{{ route('admin.tasks.index') }}" class="btn btn-sm btn-outline-secondary">Clear</a>
    </div>
</form>

@if(session('success'))
<div class="alert alert-success alert-dismissible fade show py-2" role="alert">
    {{ session('success') }}<button type="button" class="btn-close btn-sm" data-bs-dismiss="alert"></button>
</div>
@endif

<div class="card shadow-sm">
    <div class="card-body p-0">
        @if($tasks->isEmpty())
        <div class="text-center py-5 text-muted">
            <i class="bi bi-list-task display-4 d-block mb-2"></i>No tasks found.
        </div>
        @else
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 small">
                <thead class="table-light">
                    <tr>
                        <th>Title</th>
                        <th>Type</th>
                        <th>Priority</th>
                        <th>Status</th>
                        <th>Branch</th>
                        <th>Assigned To</th>
                        <th>Due Date</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($tasks as $t)
                    <tr>
                        <td class="fw-semibold">
                            <a href="{{ route('admin.tasks.show', $t) }}" class="text-decoration-none">{{ $t->title }}</a>
                        </td>
                        <td><span class="badge bg-info text-dark">{{ ucfirst($t->type) }}</span></td>
                        <td>
                            @php
                                $pColors = ['urgent'=>'danger','high'=>'warning','medium'=>'primary','low'=>'secondary'];
                            @endphp
                            <span class="badge bg-{{ $pColors[$t->priority] ?? 'secondary' }}">{{ ucfirst($t->priority) }}</span>
                        </td>
                        <td>
                            @php
                                $sColors = ['todo'=>'secondary','in_progress'=>'primary','blocked'=>'danger','on_hold'=>'warning','done'=>'success'];
                            @endphp
                            <span class="badge bg-{{ $sColors[$t->status] ?? 'secondary' }}">{{ str_replace('_', ' ', ucfirst($t->status)) }}</span>
                        </td>
                        <td>{{ $t->branch?->name ?? '—' }}</td>
                        <td>{{ $t->assignedTo?->name ?? '—' }}</td>
                        <td>
                            @if($t->due_date)
                                <span class="{{ $t->due_date->isPast() && $t->status !== 'done' ? 'text-danger fw-bold' : '' }}">
                                    {{ $t->due_date->format('Y-m-d') }}
                                </span>
                            @else
                                —
                            @endif
                        </td>
                        <td>
                            <div class="d-flex gap-1">
                                <a href="{{ route('admin.tasks.show', $t) }}" class="btn btn-sm btn-outline-primary" title="View"><i class="bi bi-eye"></i></a>
                                <a href="{{ route('admin.tasks.edit', $t) }}" class="btn btn-sm btn-outline-secondary" title="Edit"><i class="bi bi-pencil"></i></a>
                                @if($t->created_by === Auth::id() || Auth::user()->isAdmin())
                                <form method="POST" action="{{ route('admin.tasks.destroy', $t) }}" onsubmit="return confirm('Delete this task?')">
                                    @csrf @method('DELETE')
                                    <button class="btn btn-sm btn-outline-danger" title="Delete"><i class="bi bi-trash"></i></button>
                                </form>
                                @endif
                            </div>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="p-3">{{ $tasks->links() }}</div>
        @endif
    </div>
</div>

@endsection
