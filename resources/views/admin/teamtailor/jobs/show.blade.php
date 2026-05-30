@extends('layouts.admin')
@section('content')

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <div class="mb-1">
            <a href="{{ route('admin.jobs.index') }}" class="text-decoration-none text-muted small">
                <i class="bi bi-arrow-left me-1"></i>All jobs
            </a>
        </div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-people me-2 text-primary"></i>{{ $jobTitle ?: 'Applicants' }}</h4>
        <small class="text-muted">
            @if($configured && !$error)
                {{ number_format($total) }} applicant{{ $total === 1 ? '' : 's' }}
            @else
                Teamtailor recruitment
            @endif
        </small>
    </div>
    <a href="{{ route('admin.candidates.index') }}" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-person-rolodex me-1"></i>All candidates
    </a>
</div>

@unless($configured)
<div class="alert alert-warning d-flex align-items-start gap-2">
    <i class="bi bi-exclamation-triangle-fill mt-1"></i>
    <div>
        <strong>Teamtailor is not configured.</strong>
        Set an Admin-scoped API token under <a href="{{ route('admin.settings.index') }}#teamtailor">Settings → Teamtailor</a>.
    </div>
</div>
@endunless

@if($error)
<div class="alert alert-danger d-flex align-items-start gap-2">
    <i class="bi bi-x-octagon-fill mt-1"></i>
    <div>
        <strong>Could not load applicants.</strong>
        <div class="small">{{ $error }}</div>
    </div>
</div>
@endif

<div class="card shadow-sm border-0">
    <div class="card-body p-0">
        @if($applications->isEmpty())
        <div class="text-center py-5 text-muted">
            <i class="bi bi-people display-4 d-block mb-2"></i>
            No applicants to show.
        </div>
        @else
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 small">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">Applicant</th>
                        <th>Phone</th>
                        <th>Status</th>
                        <th>Applied</th>
                        <th>Links</th>
                        @can('reject-candidates')
                        <th class="pe-3 text-end">Action</th>
                        @endcan
                    </tr>
                </thead>
                <tbody>
                    @foreach($applications as $a)
                    <tr class="{{ $a['rejected'] ? 'opacity-75' : '' }}">
                        <td class="ps-3">
                            <div class="fw-semibold">{{ $a['name'] }}</div>
                            @if($a['email'])
                            <div class="text-muted" style="font-size:.75rem">
                                <a href="mailto:{{ $a['email'] }}" class="text-decoration-none text-muted">{{ $a['email'] }}</a>
                            </div>
                            @endif
                        </td>
                        <td>{{ $a['phone'] ?? '—' }}</td>
                        <td>
                            @if($a['rejected'])
                                <span class="badge bg-danger-subtle text-danger border border-danger-subtle">Rejected</span>
                            @else
                                <span class="badge bg-success-subtle text-success border border-success-subtle">Active</span>
                            @endif
                        </td>
                        <td>{{ $a['applied_at'] ? \Illuminate\Support\Carbon::parse($a['applied_at'])->format('d M Y') : '—' }}</td>
                        <td>
                            @if($a['linkedin'])
                                <a href="{{ $a['linkedin'] }}" target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary" title="LinkedIn">
                                    <i class="bi bi-linkedin"></i>
                                </a>
                            @endif
                            @if($a['resume'])
                                <a href="{{ $a['resume'] }}" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary" title="Resume">
                                    <i class="bi bi-file-earmark-text"></i>
                                </a>
                            @endif
                            @unless($a['linkedin'] || $a['resume'])
                                <span class="text-muted">—</span>
                            @endunless
                        </td>
                        @can('reject-candidates')
                        <td class="pe-3 text-end">
                            @unless($a['rejected'])
                            <form method="POST"
                                  action="{{ route('admin.jobs.applications.reject', ['job' => $jobId, 'application' => $a['id']]) }}"
                                  onsubmit="return confirm('Reject this application in Teamtailor? The candidate will be moved to Rejected. No email is sent, and you can restore it in Teamtailor.');"
                                  class="d-inline">
                                @csrf
                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Reject (no email sent)">
                                    <i class="bi bi-x-circle me-1"></i>Reject
                                </button>
                            </form>
                            @else
                                <span class="text-muted small">—</span>
                            @endunless
                        </td>
                        @endcan
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="px-3 py-2 border-top d-flex justify-content-between align-items-center flex-wrap gap-2">
            <small class="text-muted">Showing {{ $paginator->firstItem() }}&ndash;{{ $paginator->lastItem() }} of {{ number_format($paginator->total()) }}</small>
            {{ $paginator->withQueryString()->links() }}
        </div>
        @endif
    </div>
</div>
@endsection
