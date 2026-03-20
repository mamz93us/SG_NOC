@extends('layouts.admin')
@section('content')

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-person-check me-2 text-primary"></i>My Tasks</h4>
        <small class="text-muted">Tasks assigned to you, grouped by status</small>
    </div>
    <div class="d-flex gap-2">
        <a href="{{ route('admin.tasks.index') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-table me-1"></i>All Tasks</a>
        <a href="{{ route('admin.tasks.kanban') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-kanban me-1"></i>Kanban</a>
    </div>
</div>

@if(session('success'))
<div class="alert alert-success alert-dismissible fade show py-2" role="alert">
    {{ session('success') }}<button type="button" class="btn-close btn-sm" data-bs-dismiss="alert"></button>
</div>
@endif

@php
    $statusMeta = [
        'in_progress' => ['label' => 'In Progress', 'color' => 'primary',   'icon' => 'bi-play-circle'],
        'todo'        => ['label' => 'Todo',        'color' => 'secondary', 'icon' => 'bi-circle'],
        'blocked'     => ['label' => 'Blocked',     'color' => 'danger',    'icon' => 'bi-exclamation-octagon'],
        'on_hold'     => ['label' => 'On Hold',     'color' => 'warning',   'icon' => 'bi-pause-circle'],
        'done'        => ['label' => 'Done',        'color' => 'success',   'icon' => 'bi-check-circle'],
    ];
    $pColors = ['urgent'=>'danger','high'=>'warning','medium'=>'primary','low'=>'secondary'];
@endphp

@if($grouped->isEmpty())
<div class="text-center py-5 text-muted">
    <i class="bi bi-emoji-smile display-4 d-block mb-2"></i>
    No tasks assigned to you.
</div>
@else
    @foreach($statusMeta as $status => $meta)
        @if(isset($grouped[$status]) && $grouped[$status]->count())
        <h6 class="fw-bold mt-4 mb-2">
            <i class="bi {{ $meta['icon'] }} me-1 text-{{ $meta['color'] }}"></i>
            {{ $meta['label'] }}
            <span class="badge bg-{{ $meta['color'] }}">{{ $grouped[$status]->count() }}</span>
        </h6>
        <div class="card shadow-sm mb-3">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 small">
                        <thead class="table-light">
                            <tr>
                                <th>Title</th>
                                <th>Priority</th>
                                <th>Due Date</th>
                                <th>Branch</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($grouped[$status] as $t)
                            <tr>
                                <td class="fw-semibold">
                                    <a href="{{ route('admin.tasks.show', $t) }}" class="text-decoration-none">{{ $t->title }}</a>
                                </td>
                                <td><span class="badge bg-{{ $pColors[$t->priority] ?? 'secondary' }}">{{ ucfirst($t->priority) }}</span></td>
                                <td>
                                    @if($t->due_date)
                                        <span class="{{ $t->due_date->isPast() && $t->status !== 'done' ? 'text-danger fw-bold' : '' }}">{{ $t->due_date->format('Y-m-d') }}</span>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td>{{ $t->branch?->name ?? '—' }}</td>
                                <td>
                                    <a href="{{ route('admin.tasks.show', $t) }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye"></i></a>
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        @endif
    @endforeach
@endif

@endsection
