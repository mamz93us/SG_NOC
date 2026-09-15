@extends('layouts.admin')

@section('title', 'Recruitment AI')

@section('content')
<div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-person-check me-2 text-primary"></i>Recruitment AI</h4>
        <small class="text-muted">
            The AI reads each applicant's CV from Teamtailor and scores it against the job ad and your must-haves.
            Switch it on per job. It ranks and explains; people decide. Nothing is changed in Teamtailor.
        </small>
    </div>
    @can('view-candidates')
        <a href="{{ route('admin.jobs.index') }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-briefcase me-1"></i>Teamtailor jobs
        </a>
    @endcan
</div>

@unless ($configured)
    <div class="alert alert-warning d-flex align-items-start gap-2">
        <i class="bi bi-exclamation-triangle-fill mt-1"></i>
        <div>
            <strong>Teamtailor is not configured.</strong>
            Set an Admin-scoped API token under <a href="{{ route('admin.settings.index') }}#teamtailor">Settings → Teamtailor</a>.
        </div>
    </div>
@endunless

@if ($error)
    <div class="alert alert-danger d-flex align-items-start gap-2">
        <i class="bi bi-x-octagon-fill mt-1"></i>
        <div><strong>Could not load the jobs from Teamtailor.</strong><div class="small">{{ $error }}</div></div>
    </div>
@endif

<div class="card shadow-sm border-0">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th class="ps-3">Job</th>
                    <th>Teamtailor</th>
                    <th>AI screening</th>
                    <th class="text-end">Applicants</th>
                    <th class="text-end">Screened</th>
                    <th class="text-end">Best score</th>
                    <th class="pe-3"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($jobs as $row)
                    @php $job = $local->get($row['id']); @endphp
                    <tr>
                        <td class="ps-3">
                            <a href="{{ route('admin.recruitment-ai.show', $row['id']) }}" class="fw-semibold text-decoration-none">{{ $row['title'] }}</a>
                            <div class="small text-muted">Job {{ $row['id'] }}</div>
                        </td>
                        <td><span class="badge bg-light text-body border">{{ $row['status'] ?? '—' }}</span></td>
                        <td>
                            @if ($job?->screening_enabled)
                                <span class="badge bg-success-subtle text-success border border-success-subtle">On</span>
                                @if ($job->pending_count > 0)
                                    <span class="small text-muted ms-1">{{ $job->pending_count }} waiting</span>
                                @endif
                            @elseif ($job)
                                <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle">Off</span>
                            @else
                                <span class="small text-muted">Not set up</span>
                            @endif
                        </td>
                        <td class="text-end">{{ $job?->applicant_count ?: '—' }}</td>
                        <td class="text-end">{{ $job ? $job->screened_count : '—' }}</td>
                        <td class="text-end">
                            @if ($job && $job->best_score !== null)
                                @include('admin.recruitment-ai._score', ['score' => $job->best_score])
                            @else
                                —
                            @endif
                        </td>
                        <td class="pe-3 text-end">
                            <a href="{{ route('admin.recruitment-ai.show', $row['id']) }}" class="btn btn-sm btn-outline-primary">
                                {{ $job ? 'Open' : 'Set up' }}
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="text-center text-muted py-4">No jobs to show.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
