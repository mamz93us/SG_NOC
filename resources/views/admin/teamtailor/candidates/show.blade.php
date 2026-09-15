@extends('layouts.admin')
@section('content')
@php
    $tz = config('app.timezone');
    $day = fn ($date) => $date ? $date->setTimezone($tz)->format('d M Y') : '—';
    $moment = fn ($date) => $date ? $date->setTimezone($tz)->format('d M Y, H:i') : '—';
@endphp

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
@include('admin.teamtailor.candidates._salary_distance')

<div class="row g-3">
    {{-- ─── Left: details, CV and attachments, pitch, Teamtailor's CV summary ─── --}}
    <div class="col-lg-4">
        <div class="card shadow-sm border-0 mb-3">
            <div class="card-body">
                <h6 class="text-muted text-uppercase small mb-3">Details</h6>

                <dl class="row small mb-2">
                    <dt class="col-5 text-muted fw-normal">Email</dt>
                    <dd class="col-7 text-break">
                        @if($profile['email'])<a href="mailto:{{ $profile['email'] }}" class="text-decoration-none">{{ $profile['email'] }}</a>@else — @endif
                    </dd>
                    <dt class="col-5 text-muted fw-normal">Phone</dt>
                    <dd class="col-7">{{ $profile['phone'] ?? '—' }}</dd>
                    <dt class="col-5 text-muted fw-normal">Location</dt>
                    <dd class="col-7">{{ $profile['location'] ?? '—' }}</dd>
                    <dt class="col-5 text-muted fw-normal">Source</dt>
                    <dd class="col-7">{{ $profile['referring_site'] ?? '—' }}</dd>
                    <dt class="col-5 text-muted fw-normal">In Teamtailor since</dt>
                    <dd class="col-7">{{ $day($profile['created_at']) }}</dd>
                    <dt class="col-5 text-muted fw-normal">Last updated</dt>
                    <dd class="col-7">{{ $day($profile['updated_at']) }}</dd>
                    @if($profile['consent_future_jobs_at'])
                    <dt class="col-5 text-muted fw-normal">Future jobs consent</dt>
                    <dd class="col-7">{{ $day($profile['consent_future_jobs_at']) }}</dd>
                    @endif
                </dl>

                <div class="mb-2 d-flex flex-wrap gap-2">
                    @if($profile['connected'])
                        <span class="badge bg-success-subtle text-success border border-success-subtle">Connected</span>
                    @else
                        <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle">Lead</span>
                    @endif
                    @if($profile['sourced'])
                        <span class="badge bg-info-subtle text-info border border-info-subtle">Sourced</span>
                    @endif
                    @if($profile['internal'])
                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle">Internal</span>
                    @endif
                    @if($profile['unsubscribed'])
                        <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle">Unsubscribed</span>
                    @endif
                </div>

                @if(!empty($profile['tags']))
                <div>
                    @foreach($profile['tags'] as $tag)
                        <span class="badge bg-light text-dark border">{{ $tag }}</span>
                    @endforeach
                </div>
                @endif
            </div>
        </div>

        <div class="card shadow-sm border-0 mb-3">
            <div class="card-body">
                <h6 class="text-muted text-uppercase small mb-3">CV &amp; attachments</h6>
                <div class="d-flex flex-wrap gap-2 mb-2">
                    @if($profile['resume'])
                    <a href="{{ $profile['resume'] }}" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary">
                        <i class="bi bi-file-earmark-text me-1"></i>CV
                    </a>
                    @endif
                    @if($profile['original_resume'] && $profile['original_resume'] !== $profile['resume'])
                    <a href="{{ $profile['original_resume'] }}" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary">
                        <i class="bi bi-file-earmark me-1"></i>CV as uploaded
                    </a>
                    @endif
                    @if($profile['linkedin'])
                    <a href="{{ $profile['linkedin'] }}" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-outline-primary">
                        <i class="bi bi-linkedin me-1"></i>LinkedIn
                    </a>
                    @endif
                </div>
                @forelse($uploads as $upload)
                    <div class="small d-flex justify-content-between gap-2 border-top pt-2 mt-2">
                        <span class="text-break">
                            <i class="bi bi-paperclip me-1"></i>
                            @if($upload['url'])
                                <a href="{{ $upload['url'] }}" target="_blank" rel="noopener" class="text-decoration-none">{{ $upload['name'] }}</a>
                            @else
                                {{ $upload['name'] }}
                            @endif
                            @if($upload['internal'])<span class="badge bg-light text-muted border ms-1">internal</span>@endif
                        </span>
                        <span class="text-muted text-nowrap">{{ $day($upload['created_at']) }}</span>
                    </div>
                @empty
                    @unless($profile['resume'] || $profile['linkedin'])
                        <span class="text-muted small">No CV, LinkedIn or attachments on file.</span>
                    @endunless
                @endforelse
            </div>
        </div>

        @if($profile['pitch'])
        <div class="card shadow-sm border-0 mb-3">
            <div class="card-body">
                <h6 class="text-muted text-uppercase small mb-2">Pitch</h6>
                <div class="small" style="white-space:pre-wrap">{{ $profile['pitch'] }}</div>
            </div>
        </div>
        @endif

        @if($profile['resume_summary'])
        <div class="card shadow-sm border-0 mb-3">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <h6 class="text-muted text-uppercase small mb-0">CV summary (Teamtailor AI)</h6>
                    <a class="small" data-bs-toggle="collapse" href="#ttSummary" role="button">Show</a>
                </div>
                <div class="collapse mt-2" id="ttSummary">
                    <div class="small" style="white-space:pre-wrap">{{ $profile['resume_summary'] }}</div>
                </div>
            </div>
        </div>
        @endif
    </div>

    {{-- ─── Right: applications with questions and answers, then the activity log ─── --}}
    <div class="col-lg-8">
        <div class="card shadow-sm border-0 mb-3">
            <div class="card-header bg-white border-0 pt-3">
                <h6 class="text-muted text-uppercase small mb-0">Applications ({{ count($applications) }})</h6>
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
                    @php $screening = $app['job_id'] ? $screenings->get($app['job_id']) : null; @endphp
                    <div class="list-group-item py-3">
                        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
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
                                    Applied {{ $day($app['applied_at']) }}
                                    @if($app['referring_site']) · via {{ $app['referring_site'] }} @endif
                                    @if($app['sourced']) · sourced @endif
                                    @if($app['changed_stage_at'] && ! $app['rejected']) · stage since {{ $day($app['changed_stage_at']) }} @endif
                                </div>
                                @php
                                    $appExpected = \App\Services\Recruitment\SalaryAnswers::label($app['salary']['expected'] ?? null);
                                    $appCurrent = \App\Services\Recruitment\SalaryAnswers::label($app['salary']['current'] ?? null);
                                    $appKm = $screening?->distanceKm();
                                @endphp
                                @if($appExpected || $appCurrent || $appKm !== null)
                                <div class="small mt-1 d-flex flex-wrap column-gap-3">
                                    @if($appExpected)<span>Expects <strong>{{ $appExpected }}</strong></span>@endif
                                    @if($appCurrent)<span>Now <strong>{{ $appCurrent }}</strong></span>@endif
                                    @if($appKm !== null)<span title="Estimated by Recruitment AI">~{{ number_format($appKm) }} km to {{ $screening->distanceOffice() ?? 'the office' }}</span>@endif
                                </div>
                                @endif
                            </div>
                            <div class="d-flex flex-wrap gap-2 align-items-center">
                                @if($screening)
                                    @if($screening->status === 'screened')
                                        <a href="{{ route('admin.recruitment-ai.show', $app['job_id']) }}" class="text-decoration-none small" title="Recruitment AI">
                                            AI @include('admin.recruitment-ai._score', ['score' => $screening->score])
                                            <span class="text-muted">{{ $screening->fitLabel() }}</span>
                                        </a>
                                    @else
                                        <a href="{{ route('admin.recruitment-ai.show', $app['job_id']) }}" class="badge bg-light text-muted border text-decoration-none">
                                            AI: {{ ['pending' => 'waiting', 'no_cv' => 'no CV', 'failed' => 'could not read'][$screening->status] ?? $screening->status }}
                                        </a>
                                    @endif
                                @endif
                                @if($app['rejected'])
                                    <span class="badge bg-danger-subtle text-danger border border-danger-subtle">Rejected</span>
                                @elseif($app['stage'])
                                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle">{{ $app['stage'] }}</span>
                                @else
                                    <span class="badge bg-success-subtle text-success border border-success-subtle">Active</span>
                                @endif
                            </div>
                        </div>

                        @if($app['rejected'])
                        <div class="small text-danger mt-2">
                            Rejected {{ $day($app['rejected_at']) }}@if($app['stage']) at {{ $app['stage'] }}@endif
                            @if($app['reject_reason'])
                                — {{ $app['reject_reason'] }}
                                @if($app['rejected_by_company'] === true)
                                    <span class="text-muted">(by the company)</span>
                                @elseif($app['rejected_by_company'] === false)
                                    <span class="text-muted">(by the candidate)</span>
                                @endif
                            @endif
                        </div>
                        @endif

                        @if($app['cover_letter'])
                        <div class="mt-2">
                            <a class="small" data-bs-toggle="collapse" href="#ttCover{{ $loop->index }}" role="button">Cover letter</a>
                            <div class="collapse mt-2" id="ttCover{{ $loop->index }}">
                                <div class="small border rounded p-2 bg-body-tertiary" style="white-space:pre-wrap">{{ $app['cover_letter'] }}</div>
                            </div>
                        </div>
                        @endif

                        <div class="mt-3">
                            <div class="small fw-semibold mb-1">Questions and answers ({{ count($app['answers']) }})</div>
                            @forelse($app['answers'] as $qa)
                                <div class="small mb-2">
                                    <div class="text-muted">{{ $qa['question'] ?? 'Question' }}</div>
                                    <div style="white-space:pre-wrap">{{ $qa['answer'] }}</div>
                                </div>
                            @empty
                                <div class="small text-muted">No answers to this job's questions.</div>
                            @endforelse
                        </div>
                    </div>
                    @endforeach
                </div>
                @endif
            </div>
        </div>

        @if(!empty($otherAnswers))
        <div class="card shadow-sm border-0 mb-3">
            <div class="card-body">
                <h6 class="text-muted text-uppercase small mb-1">Other answers ({{ count($otherAnswers) }})</h6>
                <div class="small text-muted mb-2">Answers Teamtailor does not tie to one of the applications above.</div>
                @foreach($otherAnswers as $qa)
                    <div class="small mb-2">
                        <div class="text-muted">{{ $qa['question'] ?? 'Question' }}</div>
                        <div style="white-space:pre-wrap">{{ $qa['answer'] }}</div>
                    </div>
                @endforeach
            </div>
        </div>
        @endif

        <div class="card shadow-sm border-0">
            <div class="card-header bg-white border-0 pt-3 d-flex justify-content-between align-items-center">
                <h6 class="text-muted text-uppercase small mb-0">
                    Activity in Teamtailor
                    @if($activitiesTotal !== null)
                        ({{ count($activities) }}{{ $activitiesTotal > count($activities) ? ' of '.$activitiesTotal : '' }})
                    @endif
                </h6>
                @if(! $allActivity && $activitiesTotal !== null && $activitiesTotal > count($activities))
                    <a href="{{ route('admin.candidates.show', ['candidate' => $candidateId, 'all_activity' => 1]) }}" class="small">Show all activity</a>
                @endif
            </div>
            <div class="card-body pt-2">
                @if($activityError)
                    <div class="alert alert-warning small mb-2">Could not load the activity log: {{ $activityError }}</div>
                @endif
                @forelse($activities as $activity)
                    <div class="d-flex gap-3 py-2 {{ $loop->first ? '' : 'border-top' }}">
                        <div class="text-muted small text-nowrap" style="min-width:120px">{{ $moment($activity['at']) }}</div>
                        <div class="flex-grow-1 small">
                            <div>
                                <span class="fw-semibold">{{ $activity['label'] }}</span>
                                @if($activity['automatic'])
                                    <span class="badge bg-light text-muted border ms-1">automatic</span>
                                @endif
                            </div>
                            @if($activity['job'] || $activity['user'])
                                <div class="text-muted">
                                    {{ collect([$activity['job'], $activity['user'] ? 'by '.$activity['user'] : null])->filter()->implode(' · ') }}
                                </div>
                            @endif
                            @foreach($activity['details'] as $detail)
                                <div class="text-muted text-break">{{ $detail }}</div>
                            @endforeach
                            @if($activity['body'] && $activity['code'] === 'note')
                                <div class="border rounded p-2 mt-1 bg-body-tertiary" style="white-space:pre-wrap">{{ $activity['body'] }}</div>
                            @elseif($activity['body'])
                                <a class="small" data-bs-toggle="collapse" href="#ttAct{{ $loop->index }}" role="button">Show message</a>
                                <div class="collapse mt-1" id="ttAct{{ $loop->index }}">
                                    <div class="border rounded p-2 bg-body-tertiary" style="white-space:pre-wrap">{{ $activity['body'] }}</div>
                                </div>
                            @endif
                        </div>
                    </div>
                @empty
                    @unless($activityError)
                        <div class="text-muted small">No activity recorded.</div>
                    @endunless
                @endforelse
            </div>
        </div>
    </div>
</div>
@endif
@endsection
