{{--
    The two Oracle leaver lists, and they are deliberately different in kind.

    "Inactive in Oracle" is a statement Oracle made, so it is actionable.
    "Not listed by Oracle" is an absence, which for this feed is meaningless on
    its own — the Employee Portal serves the SamirGroup Saudi book, so every SSS
    Egypt employee is permanently missing from it. It is reported, grouped by
    branch and labelled, and it has no buttons at all. Adding some would be the
    single most destructive change anyone could make to this page: terminating
    cascades into disabling the person's Microsoft account.
--}}

@if($leavers->isNotEmpty() || $ignoredLeavers->isNotEmpty())
<div class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-transparent d-flex justify-content-between align-items-center">
        <strong><i class="bi bi-person-dash me-1"></i>Inactive in Oracle, still employed here</strong>
        <span class="badge bg-warning-subtle text-warning-emphasis border">{{ $leavers->count() }}</span>
    </div>
    <div class="card-body pb-0">
        <p class="small text-muted mb-0">
            Oracle says these people's assignment has ended. Nothing has been changed here. Recording somebody
            as terminated disables their Microsoft account and flags every asset they hold for return, so it
            takes a decision — and Oracle's HR record often moves weeks either side of the day IT access
            should actually stop.
        </p>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 small">
            <thead class="table-light">
                <tr>
                    <th>Employee</th><th>Oracle No</th><th>Branch</th>
                    <th>Job</th><th>Category</th><th>Type</th><th class="text-end"></th>
                </tr>
            </thead>
            <tbody>
            @forelse($leavers as $employee)
                <tr>
                    <td>
                        <a href="{{ route('admin.employees.show', $employee) }}" class="fw-semibold text-decoration-none">
                            {{ $employee->name }}
                        </a>
                        @if($employee->status !== 'active')
                            <span class="badge bg-secondary-subtle text-secondary-emphasis border ms-1">{{ $employee->status }}</span>
                        @endif
                    </td>
                    <td class="font-monospace">{{ $employee->oracle_emp_no }}</td>
                    <td>{{ $employee->branch?->name ?? '—' }}</td>
                    <td>{{ $employee->job_title ?? '—' }}</td>
                    <td>{{ $employee->oracle_employee_category ?? '—' }}</td>
                    <td class="text-muted">{{ $employee->oracle_person_type ?? '—' }}</td>
                    <td class="text-end">
                        @can('manage-identity')
                        <div class="d-inline-flex gap-1">
                            <form method="POST" action="{{ route('admin.identity.hr-import.leaver.terminate', $employee) }}"
                                  onsubmit="return confirm('Record {{ addslashes($employee->name) }} as terminated? This disables their Microsoft account and flags their assets for return.');">
                                @csrf
                                <button class="btn btn-outline-danger btn-sm">Terminate</button>
                            </form>
                            <form method="POST" action="{{ route('admin.identity.hr-import.leaver.ignore', $employee) }}">
                                @csrf
                                <button class="btn btn-outline-secondary btn-sm"
                                        title="Oracle is wrong about this one — do not list it again.">Ignore</button>
                            </form>
                        </div>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="text-center text-muted py-3">Nobody — every inactive person has been dealt with.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    @if($ignoredLeavers->isNotEmpty())
    <div class="card-footer bg-transparent small text-muted">
        <i class="bi bi-eye-slash me-1"></i>Ignored:
        @foreach($ignoredLeavers as $ignored)
            <span class="me-2">{{ $ignored->name }} <span class="font-monospace">({{ $ignored->oracle_emp_no }})</span></span>
        @endforeach
        <br>These stay hidden unless Oracle marks them active and then inactive again.
    </div>
    @endif
</div>
@endif

@if($absent['groups'] !== [])
<div class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-transparent">
        <strong><i class="bi bi-question-circle me-1"></i>Holds an Oracle number but was not listed</strong>
    </div>
    <div class="card-body pb-2">
        <p class="small text-muted mb-0">
            Compared with the pull of {{ $absent['as_of']?->diffForHumans() ?? 'unknown date' }}.
            <strong>This is a report, not a list of leavers</strong> — it has no actions for that reason. Most of it
            is people this feed simply does not cover.
        </p>
    </div>

    @foreach($absent['groups'] as $group)
    <div class="border-top">
        <div class="px-3 py-2 d-flex align-items-center gap-2 flex-wrap">
            <span class="fw-semibold">{{ $group['branch'] }}</span>
            <span class="badge {{ $group['in_book'] ? 'bg-warning-subtle text-warning-emphasis' : 'bg-light text-secondary' }} border">
                {{ $group['employees']->count() }}
            </span>
            @unless($group['in_book'])
                <span class="badge bg-light text-secondary border fw-normal">not in this book</span>
            @endunless
            <small class="text-muted">{{ $group['note'] }}</small>
        </div>
        <div class="px-3 pb-3 small">
            @foreach($group['employees'] as $employee)
                <a href="{{ route('admin.employees.show', $employee) }}"
                   class="d-inline-block me-3 text-decoration-none">
                    {{ $employee->name }}
                    <span class="font-monospace text-muted">{{ $employee->oracle_emp_no }}</span>
                </a>
            @endforeach
        </div>
    </div>
    @endforeach
</div>
@endif
