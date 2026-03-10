@extends('layouts.admin')
@section('content')

<div class="mb-4">
    <h4 class="mb-0 fw-bold">
        <i class="bi bi-journal-text me-2 text-primary"></i>{{ isset($incident) ? 'Edit' : 'New' }} Incident
    </h4>
    <small class="text-muted">
        <a href="{{ route('admin.noc.incidents.index') }}" class="text-decoration-none">Incidents</a> / {{ isset($incident) ? 'Edit #' . $incident->id : 'Create' }}
    </small>
</div>

<div class="card shadow-sm">
    <div class="card-body">
        <form method="POST" action="{{ isset($incident) ? route('admin.noc.incidents.update', $incident) : route('admin.noc.incidents.store') }}">
            @csrf
            @if(isset($incident)) @method('PUT') @endif

            @if(isset($event))
            <input type="hidden" name="noc_event_id" value="{{ $event->id }}">
            <div class="alert alert-info small mb-3">
                <i class="bi bi-link-45deg me-1"></i>Creating incident from alert: <strong>{{ $event->title }}</strong>
            </div>
            @endif

            <div class="row g-3">
                <div class="col-md-8">
                    <label class="form-label fw-semibold">Title <span class="text-danger">*</span></label>
                    <input type="text" name="title" class="form-control" required
                           value="{{ old('title', $incident->title ?? ($event->title ?? '')) }}"
                           placeholder="e.g. VPN Tunnel Down - RYD Branch">
                    @error('title') <small class="text-danger">{{ $message }}</small> @enderror
                </div>

                <div class="col-md-4">
                    <label class="form-label fw-semibold">Severity <span class="text-danger">*</span></label>
                    <select name="severity" class="form-select" required>
                        @foreach(\App\Models\Incident::severities() as $k => $v)
                        <option value="{{ $k }}" {{ old('severity', $incident->severity ?? ($event->severity ?? 'medium')) == $k ? 'selected' : '' }}>{{ $v }}</option>
                        @endforeach
                    </select>
                </div>

                @if(isset($incident))
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Status</label>
                    <select name="status" class="form-select">
                        @foreach(\App\Models\Incident::statuses() as $k => $v)
                        <option value="{{ $k }}" {{ old('status', $incident->status) == $k ? 'selected' : '' }}>{{ $v }}</option>
                        @endforeach
                    </select>
                </div>
                @endif

                <div class="col-md-4">
                    <label class="form-label fw-semibold">Branch</label>
                    <select name="branch_id" class="form-select">
                        <option value="">None</option>
                        @foreach($branches as $b)
                        <option value="{{ $b->id }}" {{ old('branch_id', $incident->branch_id ?? '') == $b->id ? 'selected' : '' }}>{{ $b->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-md-4">
                    <label class="form-label fw-semibold">Assign To</label>
                    <select name="assigned_to" class="form-select">
                        <option value="">Unassigned</option>
                        @foreach($users as $u)
                        <option value="{{ $u->id }}" {{ old('assigned_to', $incident->assigned_to ?? '') == $u->id ? 'selected' : '' }}>{{ $u->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-12">
                    <label class="form-label fw-semibold">Description</label>
                    <textarea name="description" class="form-control" rows="4" placeholder="Describe the incident...">{{ old('description', $incident->description ?? ($event->message ?? '')) }}</textarea>
                </div>

                @if(isset($incident))
                <div class="col-12">
                    <label class="form-label fw-semibold">Resolution Notes</label>
                    <textarea name="resolution_notes" class="form-control" rows="2">{{ old('resolution_notes', $incident->resolution_notes ?? '') }}</textarea>
                </div>
                @endif
            </div>

            <div class="mt-4">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check-lg me-1"></i>{{ isset($incident) ? 'Update' : 'Create Incident' }}
                </button>
                <a href="{{ route('admin.noc.incidents.index') }}" class="btn btn-secondary ms-2">Cancel</a>
            </div>
        </form>
    </div>
</div>

@endsection
