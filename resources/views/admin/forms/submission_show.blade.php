@extends('layouts.admin')
@section('content')

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-chat-square-text me-2 text-primary"></i>Submission #{{ $submission->id }}</h4>
        <small class="text-muted">{{ $form->name }} — {{ $submission->created_at?->format('d M Y H:i') }}</small>
    </div>
    <a href="{{ route('admin.forms.submissions', $form) }}" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i>Back to Submissions
    </a>
</div>

@if(session('success'))
<div class="alert alert-success alert-dismissible fade show py-2"><i class="bi bi-check-circle me-1"></i>{{ session('success') }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
@endif

<div class="row g-3">
    {{-- Submission Data --}}
    <div class="col-md-7">
        <div class="card shadow-sm">
            <div class="card-header py-2 fw-semibold small"><i class="bi bi-list-ul me-1"></i>Response Data</div>
            <div class="card-body">
                @foreach($form->schema as $field)
                @if(($field['type'] ?? '') === 'section')
                <h6 class="fw-semibold mt-3 mb-2 text-muted border-bottom pb-1">{{ $field['label'] }}</h6>
                @elseif(isset($field['name']))
                <div class="mb-3">
                    <div class="fw-semibold small text-muted mb-1">{{ $field['label'] }}</div>
                    @php $val = $submission->data[$field['name']] ?? null; @endphp
                    @if(is_array($val))
                    <div>{{ implode(', ', $val) }}</div>
                    @elseif($field['type'] === 'rating')
                    <div>
                        @for($i = 1; $i <= ($field['max'] ?? 5); $i++)
                        <i class="bi bi-star{{ $i <= (int)$val ? '-fill text-warning' : '' }}"></i>
                        @endfor
                        <span class="ms-1 text-muted">({{ $val ?? '—' }})</span>
                    </div>
                    @else
                    <div>{{ $val ?? '<em class="text-muted">—</em>' }}</div>
                    @endif
                </div>
                @endif
                @endforeach
            </div>
        </div>
    </div>

    {{-- Review Panel --}}
    <div class="col-md-5">
        <div class="card shadow-sm">
            <div class="card-header py-2 fw-semibold small"><i class="bi bi-pencil-square me-1"></i>Review</div>
            <div class="card-body">
                <div class="mb-3">
                    <div class="small text-muted">Submitted By</div>
                    <div>{{ $submission->submittedBy?->name ?? 'Anonymous' }}</div>
                </div>
                @if($submission->submitter_email)
                <div class="mb-3">
                    <div class="small text-muted">Email</div>
                    <div>{{ $submission->submitter_email }}</div>
                </div>
                @endif
                <div class="mb-3">
                    <div class="small text-muted">IP Address</div>
                    <div class="font-monospace">{{ $submission->ip_address }}</div>
                </div>
                <div class="mb-3">
                    <div class="small text-muted">Status</div>
                    <span class="badge {{ $submission->statusBadgeClass() }}">{{ ucfirst($submission->status) }}</span>
                </div>
                @if($submission->reviewer)
                <div class="mb-3">
                    <div class="small text-muted">Reviewed By</div>
                    <div>{{ $submission->reviewer->name }} — {{ $submission->reviewed_at?->diffForHumans() }}</div>
                </div>
                @endif
                @if($submission->workflowRequest)
                <div class="mb-3">
                    <div class="small text-muted">Linked Workflow</div>
                    <a href="{{ route('admin.workflows.show', $submission->workflowRequest) }}">
                        #{{ $submission->workflowRequest->id }} — {{ $submission->workflowRequest->title }}
                    </a>
                </div>
                @endif

                <hr>
                <form method="POST" action="{{ route('admin.forms.submission.review', [$form, $submission]) }}">
                    @csrf @method('PATCH')
                    <div class="mb-2">
                        <label class="form-label small fw-semibold">Update Status</label>
                        <select name="status" class="form-select form-select-sm">
                            @foreach(['new','reviewed','actioned','closed'] as $s)
                            <option value="{{ $s }}" {{ $submission->status === $s ? 'selected' : '' }}>{{ ucfirst($s) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-semibold">Reviewer Notes</label>
                        <textarea name="reviewer_notes" class="form-control form-control-sm" rows="3">{{ $submission->reviewer_notes }}</textarea>
                    </div>
                    <button type="submit" class="btn btn-sm btn-primary">Save Review</button>
                </form>
            </div>
        </div>
    </div>
</div>

@endsection
