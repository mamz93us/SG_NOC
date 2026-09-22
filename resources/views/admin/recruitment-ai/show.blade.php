@extends('layouts.admin')

@section('title', 'Recruitment AI — '.$title)

@section('content')
@php
    $percent = $progress['total'] > 0 ? (int) round($progress['screened'] / $progress['total'] * 100) : 0;
    $poll = $job->screening_enabled && ($progress['pending'] > 0 || $progress['total'] === 0);
@endphp

<div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
    <div>
        <div class="mb-1">
            <a href="{{ route('admin.recruitment-ai.index') }}" class="text-decoration-none text-muted small">
                <i class="bi bi-arrow-left me-1"></i>Recruitment AI
            </a>
        </div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-stars me-2 text-primary"></i>{{ $title }}</h4>
        <small class="text-muted">Teamtailor job {{ $jobId }}@if ($job->job_status) · @include('admin.teamtailor.jobs._status', ['jobStatus' => $job->job_status])@endif</small>
    </div>
    @can('view-candidates')
        <a href="{{ route('admin.jobs.show', ['job' => $jobId, 'title' => $title]) }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-people me-1"></i>Applicants in Teamtailor
        </a>
    @endcan
</div>

@if ($adError)
    <div class="alert alert-warning small"><strong>Could not read the job ad from Teamtailor.</strong> {{ $adError }}</div>
@endif
@unless ($aiConfigured)
    <div class="alert alert-warning small"><strong>The AI assistant is not configured</strong>, so nothing can be screened. Set it up under Settings → AI Assistant.</div>
@endunless

<div class="row g-3 mb-3">
    <div class="col-lg-7">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body" id="raProgress"
                 data-status-url="{{ route('admin.recruitment-ai.status', $jobId) }}"
                 data-screened="{{ $progress['screened'] }}"
                 data-poll="{{ $poll ? '1' : '0' }}">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">
                    <div>
                        <div class="fw-semibold">
                            AI screening
                            @if ($job->screening_enabled)
                                <span class="badge bg-success-subtle text-success border border-success-subtle ms-1">On</span>
                            @else
                                <span class="badge bg-light text-body border ms-1">Off</span>
                            @endif
                        </div>
                        <small class="text-muted">
                            @if ($job->screening_enabled)
                                New applicants are read automatically; the list is refreshed from Teamtailor every 30 minutes.
                            @elseif ($progress['total'] > 0)
                                Switched off: the results below are kept, and new applicants are not screened.
                            @else
                                Switch it on to read every applicant's CV and rank them for this job.
                            @endif
                        </small>
                    </div>
                    <div class="d-flex flex-wrap gap-2">
                        @if ($job->screening_enabled)
                            <form method="POST" action="{{ route('admin.recruitment-ai.stop', $jobId) }}">
                                @csrf
                                <button class="btn btn-sm btn-outline-secondary"><i class="bi bi-pause-circle me-1"></i>Switch off</button>
                            </form>
                        @else
                            <form method="POST" action="{{ route('admin.recruitment-ai.start', $jobId) }}">
                                @csrf
                                <button class="btn btn-sm btn-primary" @disabled(! $aiConfigured)><i class="bi bi-play-circle me-1"></i>Switch on AI screening</button>
                            </form>
                        @endif
                        @if ($progress['total'] > 0)
                            <form method="POST" action="{{ route('admin.recruitment-ai.rescreen', $jobId) }}"
                                  onsubmit="return confirm('Read and screen every applicant of this job again? Each CV is one AI call.')">
                                @csrf
                                <button class="btn btn-sm btn-outline-primary"><i class="bi bi-arrow-repeat me-1"></i>Screen all again</button>
                            </form>
                            <form method="POST" action="{{ route('admin.recruitment-ai.destroy-data', $jobId) }}"
                                  onsubmit="return confirm('Delete the AI screening of every applicant of this job: the CV text, the evaluations and the Ask conversations? Screening is switched off. Nothing is changed in Teamtailor.')">
                                @csrf
                                @method('DELETE')
                                <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash me-1"></i>Delete AI data</button>
                            </form>
                        @endif
                    </div>
                </div>

                @if ($job->exists && ($progress['total'] > 0 || $job->screening_enabled))
                    <div class="progress mb-2" style="height:8px">
                        <div class="progress-bar" id="raBar" style="width: {{ $percent }}%"></div>
                    </div>
                    <div class="small text-muted">
                        <span id="raScreened">{{ $progress['screened'] }}</span> of <span id="raTotal">{{ $progress['total'] }}</span> screened
                        · <span id="raPending">{{ $progress['pending'] }}</span> waiting
                        @if ($progress['no_cv'] > 0) · {{ $progress['no_cv'] }} without a CV @endif
                        @if ($progress['failed'] > 0) · <span class="text-danger">{{ $progress['failed'] }} could not be read</span> @endif
                        @if ($job->applicants_synced_at) · list read {{ $job->applicants_synced_at->diffForHumans() }} @endif
                        <a href="{{ request()->fullUrl() }}" id="raReload" class="ms-2 d-none">New results: reload</a>
                    </div>
                @endif

                @if ($job->sync_error)
                    <div class="small text-danger mt-2">The last read from Teamtailor failed: {{ $job->sync_error }}</div>
                @endif
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body">
                <form method="POST" action="{{ route('admin.recruitment-ai.criteria', $jobId) }}">
                    @csrf
                    @if ($errors->any())
                        <div class="alert alert-danger small py-2">{{ $errors->first() }}</div>
                    @endif
                    <label class="form-label fw-semibold mb-1" for="raMustHaves">Must-haves</label>
                    <div class="small text-muted mb-2">
                        One per line, e.g. "5+ years in accounting", "SAP FICO", "Saudi driving licence". An applicant whose CV
                        clearly does not meet one scores at most {{ \App\Services\Recruitment\ScreeningResult::CAP_WHEN_MISSING }}.
                    </div>
                    <textarea name="must_haves" id="raMustHaves" rows="4" class="form-control form-control-sm" maxlength="5000"
                              placeholder="Leave empty to judge against the job ad only">{{ old('must_haves', $job->must_haves) }}</textarea>

                    <div class="row g-2 mt-1">
                        <div class="col-sm-5">
                            <label class="form-label small fw-semibold mb-1" for="raOffice">Office</label>
                            <select name="office_branch_id" id="raOffice" class="form-select form-select-sm">
                                <option value="">Not set</option>
                                @foreach ($branches as $branch)
                                    <option value="{{ $branch->id }}" @selected((string) old('office_branch_id', $job->office_branch_id) === (string) $branch->id)>
                                        {{ $branch->name }}{{ $branch->city ? ' — '.$branch->city : '' }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-sm-7">
                            <label class="form-label small fw-semibold mb-1" for="raBudgetMin">Monthly salary budget</label>
                            <div class="input-group input-group-sm">
                                <input type="number" name="salary_budget_min" id="raBudgetMin" class="form-control" min="0" max="10000000" step="1"
                                       placeholder="From" value="{{ old('salary_budget_min', $job->salary_budget_min) }}">
                                <input type="number" name="salary_budget_max" class="form-control" min="0" max="10000000" step="1"
                                       placeholder="To" aria-label="Budget up to" value="{{ old('salary_budget_max', $job->salary_budget_max) }}">
                                <select name="salary_currency" class="form-select" style="max-width:5.5rem" aria-label="Currency">
                                    <option value="">—</option>
                                    @foreach (\App\Models\Recruitment\RecruitmentJob::CURRENCIES as $currency)
                                        <option value="{{ $currency }}" @selected(old('salary_currency', $job->salary_currency) === $currency)>{{ $currency }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="small text-muted mt-1">
                        The AI estimates how far each applicant lives from the office, and an expected salary above the budget lowers
                        the score. Salaries are read from the applicants' answers. Changing any of this screens everyone again.
                    </div>

                    <div class="d-flex justify-content-between align-items-center mt-2">
                        <button class="btn btn-sm btn-primary"><i class="bi bi-check2 me-1"></i>Save</button>
                        @if ($ad && $ad->text !== '')
                            <a class="small" data-bs-toggle="collapse" href="#raAd" role="button">Show the job ad</a>
                        @endif
                    </div>
                </form>
                @if ($ad && $ad->text !== '')
                    <div class="collapse mt-2" id="raAd">
                        <div class="border rounded p-2 small bg-body-tertiary" style="max-height:260px; overflow:auto; white-space:pre-wrap">{{ $ad->text }}</div>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>

{{-- ─── Top 10 ─────────────────────────────────────────────── --}}
<div class="card shadow-sm border-0 mb-3">
    <div class="card-header bg-transparent d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div class="fw-semibold"><i class="bi bi-trophy me-1 text-warning"></i>Top 10</div>
        <div class="small">
            @if ($includeRejected)
                <span class="text-muted me-1">Applications rejected in Teamtailor are included and marked Rejected.</span>
                <a href="{{ route('admin.recruitment-ai.show', ['job' => $jobId, 'hide_rejected' => 1]) }}">Leave them out</a>
            @else
                <span class="text-muted me-1">Rejected applications are left out.</span>
                <a href="{{ route('admin.recruitment-ai.show', $jobId) }}">Include them</a>
            @endif
        </div>
    </div>
    @if ($top->isEmpty())
        <div class="card-body text-center text-muted small py-4">
            No applicant has been screened yet. Results appear here as CVs are read.
        </div>
    @else
        <div class="list-group list-group-flush">
            @foreach ($top as $i => $screening)
                <div class="list-group-item py-3">
                    <div class="d-flex gap-3 align-items-start">
                        <div class="text-center" style="min-width:64px">
                            <div class="text-muted small">#{{ $i + 1 }}</div>
                            @include('admin.recruitment-ai._score', ['score' => $screening->score, 'size' => 'fs-6'])
                            <div class="text-muted mt-1" style="font-size:.7rem">{{ $screening->fitLabel() }}</div>
                        </div>
                        <div class="flex-grow-1">
                            <div class="d-flex flex-wrap align-items-center gap-2">
                                <span class="fw-semibold">{{ $screening->candidate_name ?? 'Candidate '.$screening->teamtailor_candidate_id }}</span>
                                <span class="small text-muted">C{{ $screening->id }}</span>
                                @if ($screening->rejected)
                                    <span class="badge bg-danger-subtle text-danger border border-danger-subtle">Rejected</span>
                                @elseif ($screening->stage)
                                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle">{{ $screening->stage }}</span>
                                @endif
                                @if ($screening->must_haves_total)
                                    <span class="small text-muted">must-haves {{ $screening->must_haves_met }}/{{ $screening->must_haves_total }}</span>
                                    @foreach ($screening->evaluationValue('must_haves', []) as $check)
                                        <i class="bi {{ $check['met'] === 'yes' ? 'bi-check-circle-fill text-success' : ($check['met'] === 'no' ? 'bi-x-circle-fill text-danger' : 'bi-question-circle-fill text-warning') }}"
                                           title="{{ $check['requirement'] }}: {{ $check['met'] }}"></i>
                                    @endforeach
                                @endif
                            </div>
                            <div class="small text-muted">
                                {{ collect([$screening->evaluationValue('current_role'), $screening->candidate_location, $screening->applied_at ? 'applied '.$screening->applied_at->format('d M Y') : null])->filter()->implode(' · ') }}
                            </div>
                            @include('admin.recruitment-ai._facts', ['screening' => $screening])
                            <div class="mt-1">{{ $screening->evaluationValue('summary') }}</div>
                            <div class="mt-2 d-flex flex-wrap gap-3 small">
                                <a data-bs-toggle="collapse" href="#raTop{{ $screening->id }}" role="button">Details</a>
                                @include('admin.recruitment-ai._links', ['screening' => $screening])
                            </div>
                            <div class="collapse mt-2" id="raTop{{ $screening->id }}">
                                @include('admin.recruitment-ai._evaluation', ['screening' => $screening])
                            </div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>

{{-- ─── Ask ─────────────────────────────────────────────────── --}}
<div class="card shadow-sm border-0 mb-3">
    <div class="card-header bg-transparent fw-semibold"><i class="bi bi-chat-dots me-1"></i>Ask about these candidates</div>
    <div class="card-body">
        <div id="raLog" class="mb-2"></div>
        <form id="raAskForm" class="d-flex gap-2 align-items-end" data-url="{{ route('admin.recruitment-ai.ask', $jobId) }}">
            <textarea id="raQuestion" rows="2" class="form-control form-control-sm" maxlength="2000"
                      placeholder="e.g. Compare the top 3 · Who has SAP and Arabic? · Why is C12 ranked below C7?"
                      @disabled($progress['screened'] === 0)></textarea>
            <button id="raAskBtn" class="btn btn-primary btn-sm" @disabled($progress['screened'] === 0)><i class="bi bi-send me-1"></i>Ask</button>
        </form>
        <div class="small text-muted mt-2">
            Answers come only from the screening of this job's applicants. The conversation is saved, and people who may use Recruitment AI can review it.
        </div>
    </div>
</div>

{{-- ─── Everyone ────────────────────────────────────────────── --}}
<div class="card shadow-sm border-0">
    <div class="card-header bg-transparent fw-semibold">All applicants ({{ $all->count() }})</div>
    @if ($all->isEmpty())
        <div class="card-body text-center text-muted small py-4">
            No applicants yet. They appear once screening is switched on and the list has been read from Teamtailor.
        </div>
    @else
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 small">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">Score</th>
                        <th>Applicant</th>
                        <th>Stage</th>
                        <th>Applied</th>
                        <th>Must-haves</th>
                        <th title="Expected monthly salary, from their answers">Expects</th>
                        <th title="Current salary, from their answers">Now</th>
                        <th title="Estimated by the AI from where they live">Distance</th>
                        <th class="pe-3"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($all as $screening)
                        <tr class="{{ $screening->rejected ? 'opacity-75' : '' }}">
                            <td class="ps-3">
                                @if ($screening->status === 'screened')
                                    @include('admin.recruitment-ai._score', ['score' => $screening->score])
                                @elseif ($screening->status === 'pending')
                                    <span class="badge bg-light text-body border">Waiting</span>
                                @elseif ($screening->status === 'no_cv')
                                    <span class="badge bg-light text-muted border">No CV</span>
                                @else
                                    <span class="badge bg-danger-subtle text-danger border border-danger-subtle" title="{{ $screening->error }}">Could not read</span>
                                @endif
                            </td>
                            <td>
                                <div class="fw-semibold">{{ $screening->candidate_name ?? '—' }} <span class="text-muted fw-normal">C{{ $screening->id }}</span></div>
                                <div class="text-muted">{{ $screening->evaluationValue('current_role') }}</div>
                            </td>
                            <td>{{ $screening->rejected ? 'Rejected' : ($screening->stage ?? 'Active') }}</td>
                            <td class="text-nowrap">{{ $screening->applied_at?->format('d M Y') ?? '—' }}</td>
                            <td>{{ $screening->must_haves_total ? $screening->must_haves_met.'/'.$screening->must_haves_total : '—' }}</td>
                            <td class="text-nowrap">
                                {{ $screening->expectedSalaryLabel() ?? '—' }}
                                @if ($screening->aboveBudget($job))
                                    <i class="bi bi-exclamation-triangle-fill text-warning ms-1" title="Above the budget"></i>
                                @endif
                            </td>
                            <td class="text-nowrap">{{ $screening->currentSalaryLabel() ?? '—' }}</td>
                            <td class="text-nowrap" title="{{ $screening->livesIn() }}">
                                {{ $screening->distanceKm() !== null ? '~'.number_format($screening->distanceKm()).' km' : '—' }}
                            </td>
                            <td class="pe-3 text-end text-nowrap">
                                @if ($screening->status === 'screened')
                                    <a data-bs-toggle="collapse" href="#raRow{{ $screening->id }}" role="button">Details</a>
                                @elseif ($screening->status === 'failed' && $screening->error)
                                    <span class="text-danger" title="{{ $screening->error }}"><i class="bi bi-info-circle"></i></span>
                                @endif
                            </td>
                        </tr>
                        @if ($screening->status === 'screened')
                            <tr class="collapse" id="raRow{{ $screening->id }}">
                                <td colspan="9" class="bg-body-tertiary px-3 py-3">
                                    <div class="mb-2">{{ $screening->evaluationValue('summary') }}</div>
                                    @include('admin.recruitment-ai._evaluation', ['screening' => $screening])
                                    <div class="mt-2 d-flex flex-wrap gap-3">@include('admin.recruitment-ai._links', ['screening' => $screening])</div>
                                </td>
                            </tr>
                        @endif
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>

<style>
    #raLog { display: flex; flex-direction: column; max-height: 420px; overflow-y: auto; }
    #raLog .ra-msg { white-space: pre-wrap; padding: .5rem .75rem; border-radius: .5rem; margin-bottom: .5rem; max-width: 90%; }
    #raLog .ra-user { align-self: flex-end; background: var(--bs-primary-bg-subtle); }
    #raLog .ra-bot { align-self: flex-start; background: var(--bs-tertiary-bg); }
    #raLog .ra-error { align-self: flex-start; background: var(--bs-danger-bg-subtle); color: var(--bs-danger-text-emphasis); }
</style>
@endsection

@push('scripts')
<script>
(function () {
    'use strict';

    var csrf = @json(csrf_token());

    // ── Progress: poll while screening is working, offer a reload when results change.
    var box = document.getElementById('raProgress');
    if (box && box.getAttribute('data-poll') === '1') {
        var seen = parseInt(box.getAttribute('data-screened'), 10) || 0;
        var timer = setInterval(function () {
            fetch(box.getAttribute('data-status-url'), { headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.json(); })
                .then(function (p) {
                    var set = function (id, value) { var el = document.getElementById(id); if (el) el.textContent = value; };
                    set('raScreened', p.screened);
                    set('raTotal', p.total);
                    set('raPending', p.pending);
                    var bar = document.getElementById('raBar');
                    if (bar && p.total > 0) bar.style.width = Math.round(p.screened / p.total * 100) + '%';
                    if (p.screened !== seen) document.getElementById('raReload')?.classList.remove('d-none');
                    if (!p.enabled || (p.total > 0 && p.pending === 0)) clearInterval(timer);
                })
                .catch(function () {});
        }, 20000);
    }

    // ── Ask about these candidates.
    var form = document.getElementById('raAskForm');
    if (!form) return;

    var input = document.getElementById('raQuestion');
    var button = document.getElementById('raAskBtn');
    var log = document.getElementById('raLog');
    var conversationId = null;

    function add(kind, text) {
        var div = document.createElement('div');
        div.className = 'ra-msg ra-' + kind;
        div.textContent = text;
        log.appendChild(div);
        log.scrollTop = log.scrollHeight;
        return div;
    }

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        var question = input.value.trim();
        if (!question || button.disabled) return;

        add('user', question);
        input.value = '';
        button.disabled = true;
        var waiting = add('bot', 'Thinking…');
        waiting.classList.add('text-muted');

        fetch(form.getAttribute('data-url'), {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrf, 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({ question: question, conversation_id: conversationId })
        })
            .then(function (r) {
                return r.json().catch(function () { return {}; }).then(function (d) { return { ok: r.ok, data: d || {} }; });
            })
            .then(function (res) {
                waiting.remove();
                button.disabled = false;
                if (!res.ok) { add('error', res.data.message || 'The AI could not answer just now. Try again.'); return; }
                conversationId = res.data.conversation_id;
                add('bot', res.data.reply || '(no answer)');
            })
            .catch(function () {
                waiting.remove();
                button.disabled = false;
                add('error', 'The AI could not answer just now. Try again.');
            });
    });

    input.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); form.requestSubmit(); }
    });
})();
</script>
@endpush
