@extends('layouts.admin')
@section('content')

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <div class="mb-1">
            <a href="{{ route('admin.candidates.index') }}" class="text-decoration-none text-muted small">
                <i class="bi bi-arrow-left me-1"></i>All candidates
            </a>
        </div>
        <h4 class="mb-0 fw-bold">
            <i class="bi bi-person-badge me-2 text-primary"></i>{{ $profile['name'] ?? 'Candidate' }}
        </h4>
        @if($profile && $profile['email'])
        <small class="text-muted">
            <a href="mailto:{{ $profile['email'] }}" class="text-decoration-none text-muted">{{ $profile['email'] }}</a>
        </small>
        @endif
    </div>
    @if($teamtailorUrl)
    <a href="{{ $teamtailorUrl }}" target="_blank" rel="noopener" class="btn btn-outline-primary btn-sm">
        <i class="bi bi-box-arrow-up-right me-1"></i>View in Teamtailor
    </a>
    @endif
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
        <strong>Could not load this candidate.</strong>
        <div class="small">{{ $error }}</div>
    </div>
</div>
@endif

@if($profile)
<div class="row g-3">
    {{-- Contact / details --}}
    <div class="col-lg-4">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body">
                <h6 class="text-muted text-uppercase small mb-3">Contact</h6>

                <div class="mb-2">
                    <div class="text-muted small">Email</div>
                    @if($profile['email'])
                        <a href="mailto:{{ $profile['email'] }}" class="text-decoration-none">{{ $profile['email'] }}</a>
                    @else
                        <span class="text-muted">—</span>
                    @endif
                </div>

                <div class="mb-2">
                    <div class="text-muted small">Phone</div>
                    {{ $profile['phone'] ?: '—' }}
                </div>

                <div class="mb-3">
                    <div class="text-muted small">Applied / created</div>
                    {{ $profile['created_at'] ? \Illuminate\Support\Carbon::parse($profile['created_at'])->format('d M Y') : '—' }}
                </div>

                <div class="mb-3 d-flex flex-wrap gap-2">
                    @if($profile['connected'])
                        <span class="badge bg-success-subtle text-success border border-success-subtle">Connected</span>
                    @else
                        <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle">Lead</span>
                    @endif
                    @if($profile['sourced'])
                        <span class="badge bg-info-subtle text-info border border-info-subtle">Sourced</span>
                    @endif
                </div>

                @if(!empty($profile['tags']))
                <div class="mb-3">
                    <div class="text-muted small mb-1">Tags</div>
                    @foreach($profile['tags'] as $tag)
                        <span class="badge bg-light text-dark border">{{ $tag }}</span>
                    @endforeach
                </div>
                @endif

                <div class="d-flex flex-wrap gap-2">
                    @if($profile['linkedin'])
                    <a href="{{ $profile['linkedin'] }}" target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary">
                        <i class="bi bi-linkedin me-1"></i>LinkedIn
                    </a>
                    @endif
                    @if($profile['resume'])
                    <a href="{{ $profile['resume'] }}" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary">
                        <i class="bi bi-file-earmark-text me-1"></i>Résumé / CV
                    </a>
                    @endif
                    @unless($profile['linkedin'] || $profile['resume'])
                    <span class="text-muted small">No résumé or LinkedIn on file.</span>
                    @endunless
                </div>
            </div>
        </div>
    </div>

    {{-- Applications --}}
    <div class="col-lg-8">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-white border-0 pt-3">
                <h6 class="text-muted text-uppercase small mb-0">
                    Applications ({{ count($applications) }})
                </h6>
            </div>
            <div class="card-body p-0">
                @if(empty($applications))
                <div class="text-center py-5 text-muted">
                    <i class="bi bi-inbox display-6 d-block mb-2"></i>
                    No applications found for this candidate.
                </div>
                @else
                <div class="list-group list-group-flush">
                    @foreach($applications as $app)
                    <div class="list-group-item d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <div>
                            <div class="fw-semibold">
                                @if($app['job_id'])
                                    <a href="{{ route('admin.jobs.show', ['job' => $app['job_id'], 'title' => $app['job_title']]) }}" class="text-decoration-none">
                                        {{ $app['job_title'] ?: 'View job' }}
                                    </a>
                                @else
                                    {{ $app['job_title'] ?: 'Application' }}
                                @endif
                            </div>
                            <div class="text-muted small">
                                Applied {{ $app['applied_at'] ? \Illuminate\Support\Carbon::parse($app['applied_at'])->format('d M Y') : '—' }}
                            </div>
                        </div>
                        @if($app['rejected'])
                            <span class="badge bg-danger-subtle text-danger border border-danger-subtle">Rejected</span>
                        @else
                            <span class="badge bg-success-subtle text-success border border-success-subtle">Active</span>
                        @endif
                    </div>
                    @endforeach
                </div>
                @endif
            </div>
        </div>
    </div>
</div>
@endif
@endsection
