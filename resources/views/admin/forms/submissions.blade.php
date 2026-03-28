@extends('layouts.admin')
@section('content')

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-inbox me-2 text-primary"></i>Submissions: {{ $form->name }}</h4>
        <small class="text-muted">{{ $submissions->total() }} total response(s)</small>
    </div>
    <div class="d-flex gap-2">
        <a href="{{ route('admin.forms.export', $form) }}" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-download me-1"></i>Export CSV
        </a>
        <a href="{{ route('admin.forms.edit', $form) }}" class="btn btn-sm btn-outline-primary">
            <i class="bi bi-pencil me-1"></i>Edit Form
        </a>
        <a href="{{ route('admin.forms.index') }}" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>Back
        </a>
    </div>
</div>

@if(session('success'))
<div class="alert alert-success alert-dismissible fade show py-2"><i class="bi bi-check-circle me-1"></i>{{ session('success') }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
@endif

{{-- Filter --}}
<form method="GET" class="row g-2 mb-3 align-items-end">
    <div class="col-auto">
        <select name="status" class="form-select form-select-sm">
            <option value="">All Statuses</option>
            @foreach(['new','reviewed','actioned','closed'] as $s)
            <option value="{{ $s }}" {{ request('status') === $s ? 'selected' : '' }}>{{ ucfirst($s) }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-auto">
        <button type="submit" class="btn btn-sm btn-secondary">Filter</button>
        <a href="{{ route('admin.forms.submissions', $form) }}" class="btn btn-sm btn-outline-secondary">Clear</a>
    </div>
</form>

<div class="card shadow-sm">
    <div class="card-body p-0">
        @if($submissions->isEmpty())
        <div class="text-center py-5 text-muted">
            <i class="bi bi-inbox display-4 d-block mb-2"></i>No submissions yet.
        </div>
        @else
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 small">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>Submitted By</th>
                        <th>Email</th>
                        @foreach(collect($form->schema)->where('type','!=','section')->take(3) as $field)
                        <th>{{ $field['label'] }}</th>
                        @endforeach
                        <th>Status</th>
                        <th>Submitted</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($submissions as $s)
                    <tr>
                        <td class="text-muted">{{ $s->id }}</td>
                        <td>
                            @if($s->submittedBy)
                                {{ $s->submittedBy->name }}
                            @else
                                <em class="text-muted">Anonymous</em>
                            @endif
                        </td>
                        <td class="text-muted">{{ $s->submitter_email ?? '—' }}</td>
                        @foreach(collect($form->schema)->where('type','!=','section')->take(3) as $field)
                        <td>
                            @php $val = $s->data[$field['name']] ?? null; @endphp
                            @if(is_array($val)){{ implode(', ', $val) }}
                            @elseif(strlen((string)$val) > 60){{ substr($val, 0, 57) }}…
                            @else{{ $val ?? '—' }}
                            @endif
                        </td>
                        @endforeach
                        <td><span class="badge {{ $s->statusBadgeClass() }}">{{ ucfirst($s->status) }}</span></td>
                        <td class="text-muted">{{ $s->created_at?->diffForHumans() }}</td>
                        <td>
                            <a href="{{ route('admin.forms.submission.show', [$form, $s]) }}" class="btn btn-sm btn-outline-primary">
                                <i class="bi bi-eye"></i>
                            </a>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="p-3">{{ $submissions->links() }}</div>
        @endif
    </div>
</div>

@endsection
