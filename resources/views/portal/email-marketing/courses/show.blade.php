@extends('layouts.portal')

@section('title', $course->name)

@section('content')
<div class="container-fluid py-4">
    <h3 class="mb-3"><i class="bi bi-envelope-paper me-2"></i>Email Marketing</h3>
    @include('portal.email-marketing._nav')

    @if (session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif
    @if (session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif

    <div class="d-flex justify-content-between align-items-start mb-3">
        <div>
            <h4 class="mb-1">{{ $course->name }}</h4>
            <div class="text-muted small">{{ $course->description }}</div>
        </div>
        <div class="text-end">
            @can('manage-courses')
                <a href="{{ route('portal.marketing.courses.upload.form', $course) }}" class="btn btn-outline-primary btn-sm">
                    <i class="bi bi-upload me-1"></i>Upload certificates
                </a>
                <a href="{{ route('portal.marketing.courses.send.form', $course) }}" class="btn btn-success btn-sm"
                   @if ($course->certificates_count === 0) aria-disabled="true" tabindex="-1" style="pointer-events:none;opacity:.5" @endif>
                    <i class="bi bi-send me-1"></i>Send certificates
                </a>
                <a href="{{ route('portal.marketing.courses.edit', $course) }}" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-pencil"></i>
                </a>
            @endcan
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-md-3"><div class="card shadow-sm h-100"><div class="card-body">
            <div class="text-muted small">Total certificates</div>
            <div class="h4 mb-0">{{ $course->certificates_count }}</div>
        </div></div></div>
        <div class="col-md-3"><div class="card shadow-sm h-100"><div class="card-body">
            <div class="text-muted small">Sent</div>
            <div class="h4 mb-0">{{ $course->sent_count }}</div>
        </div></div></div>
        <div class="col-md-3"><div class="card shadow-sm h-100"><div class="card-body">
            <div class="text-muted small">Orphaned</div>
            <div class="h4 mb-0 {{ $course->orphaned_count > 0 ? 'text-warning' : '' }}">{{ $course->orphaned_count }}</div>
        </div></div></div>
        <div class="col-md-3"><div class="card shadow-sm h-100"><div class="card-body">
            <div class="text-muted small">Pending</div>
            <div class="h4 mb-0">{{ $course->certificates_count - $course->sent_count }}</div>
        </div></div></div>
    </div>

    {{-- ── Campaign-style email engagement (across every send for this course) ── --}}
    @if (! empty($totals) && ($campaigns ?? collect())->isNotEmpty())
        <div class="card shadow-sm mb-3">
            <div class="card-header bg-light d-flex justify-content-between align-items-center">
                <strong><i class="bi bi-bar-chart me-1"></i>Email engagement (across all sends)</strong>
                <small class="text-muted">{{ $campaigns->count() }} campaign(s)</small>
            </div>
            <div class="card-body">
                <div class="row g-3 text-center">
                    <div class="col-md-2 col-6">
                        <div class="text-muted small">Sent</div>
                        <div class="h4 text-primary mb-0">{{ number_format($totals['total_sent']) }}</div>
                    </div>
                    <div class="col-md-2 col-6">
                        <div class="text-muted small">Delivered</div>
                        <div class="h4 text-success mb-0">{{ number_format($totals['total_delivered']) }}</div>
                    </div>
                    <div class="col-md-2 col-6">
                        <div class="text-muted small">Unique opens</div>
                        <div class="h4 text-info mb-0">{{ number_format($totals['total_unique_opens']) }}</div>
                        <small class="text-muted">{{ $totals['open_rate'] }}% rate</small>
                    </div>
                    <div class="col-md-2 col-6">
                        <div class="text-muted small">Unique clicks</div>
                        <div class="h4 text-primary mb-0">{{ number_format($totals['total_unique_clicks']) }}</div>
                        <small class="text-muted">{{ $totals['click_rate'] }}% rate</small>
                    </div>
                    <div class="col-md-2 col-6">
                        <div class="text-muted small">Bounces</div>
                        <div class="h4 text-danger mb-0">{{ number_format($totals['total_bounces']) }}</div>
                        <small class="text-muted">{{ $totals['bounce_rate'] }}% rate</small>
                    </div>
                    <div class="col-md-2 col-6">
                        <div class="text-muted small">Complaints</div>
                        <div class="h4 text-warning mb-0">{{ number_format($totals['total_complaints']) }}</div>
                    </div>
                </div>
            </div>
            <div class="table-responsive border-top">
                <table class="table table-sm mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Campaign</th>
                            <th>Status</th>
                            <th class="text-end">Sent</th>
                            <th class="text-end">Delivered</th>
                            <th class="text-end">Open rate</th>
                            <th class="text-end">Click rate</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    @foreach ($campaigns as $c)
                        <tr>
                            <td>
                                <a href="{{ route('portal.marketing.campaigns.show', $c) }}"><small><strong>{{ $c->name }}</strong></small></a>
                                <br><small class="text-muted">{{ $c->sent_at?->format('Y-m-d H:i') ?: ($c->scheduled_at?->format('Y-m-d H:i') ?: '—') }}</small>
                            </td>
                            <td>
                                <span class="badge bg-{{ match($c->status) {
                                    'sent' => 'success', 'sending' => 'primary', 'scheduled' => 'warning',
                                    'paused' => 'secondary', 'failed' => 'danger', default => 'light text-dark',
                                } }} text-capitalize">{{ $c->status }}</span>
                            </td>
                            <td class="text-end">{{ number_format($c->total_sent) }}</td>
                            <td class="text-end">{{ number_format($c->total_delivered) }}</td>
                            <td class="text-end">{{ $c->openRate() }}%</td>
                            <td class="text-end">{{ $c->clickRate() }}%</td>
                            <td class="text-end">
                                <a href="{{ route('portal.marketing.campaigns.analytics', $c) }}" class="btn btn-sm btn-outline-info" title="Open analytics">
                                    <i class="bi bi-bar-chart"></i>
                                </a>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    <div class="card shadow-sm">
        <div class="card-header"><strong>Certificates</strong> <small class="text-muted">orphans shown first</small></div>
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Email (from filename)</th><th>Employee</th><th>Sent</th><th>Viewed</th><th>Link</th><th></th>
                    </tr>
                </thead>
                <tbody>
                @forelse ($certificates as $cert)
                    <tr class="{{ $cert->isOrphaned() ? 'table-warning' : '' }}">
                        <td><code>{{ $cert->email }}</code></td>
                        <td>
                            @if ($cert->employee)
                                @can('view-employees')
                                    <a href="{{ route('admin.employees.show', $cert->employee) }}">{{ $cert->employee->name }}</a>
                                @else
                                    {{ $cert->employee->name }}
                                @endcan
                                <small class="text-muted d-block">{{ $cert->employee->email }}</small>
                            @else
                                @can('manage-courses')
                                    <form method="POST" action="{{ route('portal.marketing.courses.certificates.relink', [$course, $cert]) }}" class="d-flex gap-2">
                                        @csrf
                                        <input type="text" name="employee_search" class="form-control form-control-sm employee-search"
                                               placeholder="Search employees…" autocomplete="off"
                                               data-target="emp-{{ $cert->id }}">
                                        <input type="hidden" name="employee_id" id="emp-{{ $cert->id }}">
                                        <button class="btn btn-sm btn-outline-success" type="submit">Link</button>
                                    </form>
                                @else
                                    <span class="badge bg-warning text-dark">Orphan</span>
                                @endcan
                            @endif
                        </td>
                        <td><small>{{ $cert->sent_at?->diffForHumans() ?: '—' }}</small></td>
                        <td>
                            @if ($cert->viewed_at)
                                <small>{{ $cert->view_count }}× <span class="text-muted">last {{ $cert->viewed_at->diffForHumans() }}</span></small>
                            @else
                                <small class="text-muted">—</small>
                            @endif
                        </td>
                        <td><a href="{{ $cert->publicUrl() }}" target="_blank" rel="noopener" title="Open link"><i class="bi bi-box-arrow-up-right"></i></a></td>
                        <td>
                            @can('manage-courses')
                                <form method="POST" action="{{ route('portal.marketing.courses.certificates.destroy', [$course, $cert]) }}" class="d-inline"
                                      onsubmit="return confirm('Delete this certificate? The recipient link will stop working.')">
                                    @csrf @method('DELETE')
                                    <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                </form>
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-center text-muted py-4">No certificates yet. Click <em>Upload certificates</em> to start.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer">{{ $certificates->links() }}</div>
    </div>
</div>

<script>
// Lightweight employee search for the orphan relink dropdown. Avoids
// shipping a full SPA component just for this — straight fetch + datalist.
document.addEventListener('input', (e) => {
    const el = e.target;
    if (!el.classList.contains('employee-search')) return;

    const q = el.value.trim();
    if (q.length < 2) return;

    const targetId = el.dataset.target;
    fetch('{{ route('portal.marketing.courses.employees.search') }}?q=' + encodeURIComponent(q), {
        headers: { 'Accept': 'application/json' },
    })
        .then(r => r.json())
        .then(rows => {
            // First match wins. Surface the chosen employee in the input
            // and stash the id in the hidden field.
            if (rows.length > 0) {
                el.value = rows[0].name + ' <' + rows[0].email + '>';
                document.getElementById(targetId).value = rows[0].id;
            }
        });
});
</script>
@endsection
