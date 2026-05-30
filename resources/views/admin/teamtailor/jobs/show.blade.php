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

@if($filtersIgnored)
<div class="alert alert-info d-flex align-items-start gap-2">
    <i class="bi bi-info-circle-fill mt-1"></i>
    <div>
        <strong>Search filters were ignored.</strong>
        <div class="small">Teamtailor rejected the email/date filters for this job's applicants, so the full list is shown. Use the on-page filters below to narrow the current page.</div>
    </div>
</div>
@endif

{{-- Server-side search & sort: hits Teamtailor and reloads the whole result set. --}}
@if($configured && !$error)
<form method="GET" class="card shadow-sm border-0 mb-3">
    <input type="hidden" name="title" value="{{ $jobTitle }}">
    <div class="card-body">
        <div class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label small text-muted mb-1">Email search</label>
                <input type="text" name="q" class="form-control form-control-sm"
                       placeholder="name@example.com" value="{{ request('q') }}" autocomplete="off">
            </div>
            <div class="col-md-3">
                <label class="form-label small text-muted mb-1">Applied from</label>
                <input type="date" name="applied_from" class="form-control form-control-sm" value="{{ request('applied_from') }}">
            </div>
            <div class="col-md-3">
                <label class="form-label small text-muted mb-1">Applied to</label>
                <input type="date" name="applied_to" class="form-control form-control-sm" value="{{ request('applied_to') }}">
            </div>
            <div class="col-md-2">
                <label class="form-label small text-muted mb-1">Sort</label>
                <select name="sort" class="form-select form-select-sm">
                    <option value="newest" {{ request('sort') !== 'oldest' ? 'selected' : '' }}>Newest first</option>
                    <option value="oldest" {{ request('sort') === 'oldest' ? 'selected' : '' }}>Oldest first</option>
                </select>
            </div>
        </div>
        <div class="d-flex gap-2 mt-3">
            <button type="submit" class="btn btn-primary btn-sm">
                <i class="bi bi-search me-1"></i>Search
            </button>
            @if(request()->anyFilled(['q','applied_from','applied_to']) || request('sort') === 'oldest')
            <a href="{{ route('admin.jobs.show', ['job' => $jobId, 'title' => $jobTitle]) }}" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-x-lg me-1"></i>Clear
            </a>
            @endif
        </div>
    </div>
</form>
@endif

<div class="card shadow-sm border-0">
    <div class="card-body p-0">
        @if($applications->isEmpty())
        <div class="text-center py-5 text-muted">
            <i class="bi bi-people display-4 d-block mb-2"></i>
            No applicants to show.
        </div>
        @else
        {{-- On-page filters: instant, but only narrow the rows on THIS page. --}}
        <div class="px-3 pt-3 pb-2 border-bottom d-flex flex-wrap gap-2 align-items-end">
            <div>
                <label class="form-label small text-muted mb-1">Find on this page</label>
                <input type="text" id="ttFind" class="form-control form-control-sm" style="min-width:220px"
                       placeholder="Name or email…" autocomplete="off">
            </div>
            <div>
                <label class="form-label small text-muted mb-1">Status</label>
                <select id="ttStatus" class="form-select form-select-sm" style="min-width:170px">
                    <option value="">All statuses</option>
                    <option value="Active">Active</option>
                    @foreach($stages as $st)
                    <option value="{{ $st }}">{{ $st }}</option>
                    @endforeach
                    <option value="Rejected">Rejected</option>
                </select>
            </div>
            <div class="ms-auto align-self-center">
                <small class="text-muted"><span id="ttShown">{{ $applications->count() }}</span> of {{ $applications->count() }} shown on this page</small>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 small" id="ttApplicants">
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
                    @php $statusLabel = $a['rejected'] ? 'Rejected' : ($a['stage'] ?: 'Active'); @endphp
                    <tr class="{{ $a['rejected'] ? 'opacity-75' : '' }}"
                        data-status="{{ $statusLabel }}"
                        data-search="{{ \Illuminate\Support\Str::lower(($a['name'] ?? '').' '.($a['email'] ?? '')) }}">
                        <td class="ps-3">
                            <div class="fw-semibold">
                                @if($a['candidate_id'])
                                    <a href="{{ route('admin.candidates.show', $a['candidate_id']) }}" class="text-decoration-none">{{ $a['name'] }}</a>
                                @else
                                    {{ $a['name'] }}
                                @endif
                            </div>
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
                            @elseif($a['stage'])
                                <span class="badge bg-primary-subtle text-primary border border-primary-subtle">{{ $a['stage'] }}</span>
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
                            @if(! $a['rejected'] && $a['application_id'])
                            <form method="POST"
                                  action="{{ route('admin.jobs.applications.reject', ['job' => $jobId, 'application' => $a['application_id']]) }}"
                                  onsubmit="return confirm('Reject this application in Teamtailor? The candidate will be moved to Rejected. No email is sent, and you can restore it in Teamtailor.');"
                                  class="d-inline">
                                @csrf
                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Reject (no email sent)">
                                    <i class="bi bi-x-circle me-1"></i>Reject
                                </button>
                            </form>
                            @else
                                <span class="text-muted small">—</span>
                            @endif
                        </td>
                        @endcan
                    </tr>
                    @endforeach
                    <tr id="ttNoMatch" class="d-none">
                        <td colspan="6" class="text-center py-4 text-muted">No applicants on this page match the on-page filters.</td>
                    </tr>
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

@push('scripts')
<script>
(function () {
    var table = document.getElementById('ttApplicants');
    if (!table) return;
    var find = document.getElementById('ttFind');
    var status = document.getElementById('ttStatus');
    var shown = document.getElementById('ttShown');
    var noMatch = document.getElementById('ttNoMatch');
    var rows = Array.prototype.slice.call(table.querySelectorAll('tbody tr')).filter(function (r) {
        return r.id !== 'ttNoMatch';
    });

    function apply() {
        var q = (find.value || '').trim().toLowerCase();
        var st = status.value || '';
        var visible = 0;
        rows.forEach(function (row) {
            var matchText = !q || (row.getAttribute('data-search') || '').indexOf(q) !== -1;
            var matchStatus = !st || (row.getAttribute('data-status') || '') === st;
            var show = matchText && matchStatus;
            row.style.display = show ? '' : 'none';
            if (show) { visible++; }
        });
        if (shown) { shown.textContent = visible; }
        if (noMatch) { noMatch.classList.toggle('d-none', visible !== 0); }
    }

    find.addEventListener('input', apply);
    status.addEventListener('change', apply);
})();
</script>
@endpush
