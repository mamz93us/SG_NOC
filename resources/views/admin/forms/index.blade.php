@extends('layouts.admin')
@section('content')

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-ui-checks-grid me-2 text-primary"></i>Form Builder</h4>
        <small class="text-muted">Create forms for feedback, surveys, and workflow intake</small>
    </div>
    <a href="{{ route('admin.forms.create') }}" class="btn btn-primary btn-sm">
        <i class="bi bi-plus-lg me-1"></i>New Form
    </a>
</div>

@if(session('success'))
<div class="alert alert-success alert-dismissible fade show py-2"><i class="bi bi-check-circle me-1"></i>{{ session('success') }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
@endif
@if($errors->any())
<div class="alert alert-danger alert-dismissible fade show py-2"><i class="bi bi-exclamation-triangle me-1"></i>{{ $errors->first() }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
@endif

<div class="card shadow-sm">
    <div class="card-body p-0">
        @if($forms->isEmpty())
        <div class="text-center py-5 text-muted">
            <i class="bi bi-ui-checks-grid display-4 d-block mb-2"></i>
            No forms yet. <a href="{{ route('admin.forms.create') }}">Create your first form</a>.
        </div>
        @else
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 small">
                <thead class="table-light">
                    <tr>
                        <th>Name</th>
                        <th>Type</th>
                        <th>Visibility</th>
                        <th>Status</th>
                        <th class="text-center">Fields</th>
                        <th class="text-center">Submissions</th>
                        <th>Created</th>
                        <th>Expires</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($forms as $f)
                    <tr>
                        <td>
                            <div class="fw-semibold">{{ $f->name }}</div>
                            <div class="text-muted small font-monospace">/forms/{{ $f->slug }}</div>
                        </td>
                        <td><span class="badge {{ $f->typeBadgeClass() }}">{{ ucfirst($f->type) }}</span></td>
                        <td><span class="badge {{ $f->visibilityBadgeClass() }}">{{ ucfirst(str_replace('_', ' ', $f->visibility)) }}</span></td>
                        <td>
                            @if($f->isOpen())
                            <span class="badge bg-success">Active</span>
                            @else
                            <span class="badge bg-secondary">Closed</span>
                            @endif
                        </td>
                        <td class="text-center">{{ count($f->schema ?? []) }}</td>
                        <td class="text-center">
                            <a href="{{ route('admin.forms.submissions', $f) }}" class="badge bg-light text-dark border text-decoration-none">
                                {{ $f->submissions_count }}
                            </a>
                        </td>
                        <td class="text-muted">{{ $f->created_at->diffForHumans() }}</td>
                        <td class="text-muted">{{ $f->expires_at ? $f->expires_at->format('d M Y') : '—' }}</td>
                        <td class="text-nowrap">
                            <a href="{{ route('admin.forms.edit', $f) }}" class="btn btn-sm btn-outline-primary" title="Edit"><i class="bi bi-pencil"></i></a>
                            <a href="{{ url('/forms/'.$f->slug) }}" target="_blank" class="btn btn-sm btn-outline-secondary" title="Preview"><i class="bi bi-box-arrow-up-right"></i></a>
                            <a href="{{ route('admin.forms.submissions', $f) }}" class="btn btn-sm btn-outline-info" title="Submissions"><i class="bi bi-inbox"></i></a>
                            <form action="{{ route('admin.forms.destroy', $f) }}" method="POST" class="d-inline"
                                  onsubmit="return confirm('Delete form \'{{ addslashes($f->name) }}\'?');">
                                @csrf @method('DELETE')
                                <button class="btn btn-sm btn-outline-danger" title="Delete"><i class="bi bi-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>
</div>

@endsection
