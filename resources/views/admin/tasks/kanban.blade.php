@extends('layouts.admin')
@section('content')

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-kanban me-2 text-primary"></i>Task Board</h4>
        <small class="text-muted">Drag and drop to update status</small>
    </div>
    <div class="d-flex gap-2">
        <a href="{{ route('admin.tasks.index') }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-table me-1"></i>List View
        </a>
        <a href="{{ route('admin.tasks.create') }}" class="btn btn-primary btn-sm">
            <i class="bi bi-plus-lg me-1"></i>New Task
        </a>
    </div>
</div>

@if(session('success'))
<div class="alert alert-success alert-dismissible fade show py-2" role="alert">
    {{ session('success') }}<button type="button" class="btn-close btn-sm" data-bs-dismiss="alert"></button>
</div>
@endif

<div x-data="kanbanBoard()" class="row g-3" style="min-height: 70vh;">
    @php
        $colMeta = [
            'todo'        => ['label' => 'Todo',        'bg' => 'secondary'],
            'in_progress' => ['label' => 'In Progress', 'bg' => 'primary'],
            'blocked'     => ['label' => 'Blocked',     'bg' => 'danger'],
            'done'        => ['label' => 'Done',        'bg' => 'success'],
        ];
    @endphp

    @foreach($statuses as $status)
    <div class="col-md-3">
        <div class="card shadow-sm h-100">
            <div class="card-header bg-{{ $colMeta[$status]['bg'] }} bg-opacity-10 py-2 d-flex justify-content-between align-items-center">
                <span class="fw-semibold small">
                    <span class="badge bg-{{ $colMeta[$status]['bg'] }} me-1">{{ $columns[$status]->count() }}</span>
                    {{ $colMeta[$status]['label'] }}
                </span>
            </div>
            <div class="card-body p-2 kanban-column"
                 data-status="{{ $status }}"
                 @dragover.prevent
                 @drop="onDrop($event, '{{ $status }}')"
                 style="min-height: 200px; overflow-y: auto; max-height: 70vh;">

                @foreach($columns[$status] as $task)
                @php
                    $pColors = ['urgent'=>'danger','high'=>'warning','medium'=>'primary','low'=>'secondary'];
                @endphp
                <div class="card mb-2 border-start border-3 border-{{ $pColors[$task->priority] ?? 'secondary' }} kanban-card"
                     draggable="true"
                     @dragstart="onDragStart($event, {{ $task->id }})"
                     @dragend="onDragEnd($event)"
                     style="cursor: grab;">
                    <div class="card-body p-2">
                        <a href="{{ route('admin.tasks.show', $task) }}" class="fw-semibold text-decoration-none small d-block mb-1">
                            {{ $task->title }}
                        </a>
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="badge bg-{{ $pColors[$task->priority] ?? 'secondary' }}" style="font-size:0.65rem;">{{ ucfirst($task->priority) }}</span>
                            @if($task->due_date)
                            <span class="text-muted" style="font-size:0.7rem;">
                                <i class="bi bi-calendar-event me-1"></i>{{ $task->due_date->format('M d') }}
                            </span>
                            @endif
                        </div>
                        @if($task->assignedTo)
                        <div class="text-muted mt-1" style="font-size:0.7rem;">
                            <i class="bi bi-person me-1"></i>{{ $task->assignedTo->name }}
                        </div>
                        @endif
                    </div>
                </div>
                @endforeach

            </div>
        </div>
    </div>
    @endforeach
</div>

@push('scripts')
<script>
function kanbanBoard() {
    return {
        draggedTaskId: null,

        onDragStart(event, taskId) {
            this.draggedTaskId = taskId;
            event.target.classList.add('opacity-50');
            event.dataTransfer.effectAllowed = 'move';
        },

        onDragEnd(event) {
            event.target.classList.remove('opacity-50');
        },

        async onDrop(event, newStatus) {
            event.preventDefault();
            if (!this.draggedTaskId) return;

            const taskId = this.draggedTaskId;
            this.draggedTaskId = null;

            try {
                const resp = await fetch(`/admin/tasks/${taskId}/update-status`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ status: newStatus }),
                });

                if (resp.ok) {
                    window.location.reload();
                } else {
                    alert('Failed to update task status.');
                }
            } catch (err) {
                alert('Network error. Please try again.');
            }
        }
    };
}
</script>
@endpush

@endsection
