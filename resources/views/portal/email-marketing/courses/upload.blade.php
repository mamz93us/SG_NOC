@extends('layouts.portal')

@section('title', 'Upload certificates — '.$course->name)

@section('content')
<div class="container-fluid py-4">
    <h3 class="mb-3"><i class="bi bi-envelope-paper me-2"></i>Email Marketing</h3>
    @include('portal.email-marketing._nav')

    <div class="mb-3">
        <a href="{{ route('portal.marketing.courses.show', $course) }}" class="text-decoration-none">
            <i class="bi bi-arrow-left"></i> Back to {{ $course->name }}
        </a>
    </div>

    @if ($errors->any())
        <div class="alert alert-danger"><ul class="mb-0">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
    @endif
    @if (session('status'))<div class="alert alert-info">{{ session('status') }}</div>@endif

    <div class="card shadow-sm mb-4">
        <div class="card-header"><strong>Upload certificates</strong> — for course <em>{{ $course->name }}</em></div>
        <form method="POST" action="{{ route('portal.marketing.courses.upload.store', $course) }}" enctype="multipart/form-data">
            @csrf
            <div class="card-body">
                <p class="text-muted small">
                    Filenames must be the recipient's email plus a <code>.pdf</code>, <code>.jpg</code>, <code>.jpeg</code>
                    or <code>.png</code> extension &mdash; e.g. <code>ahmed&#64;samirgroup.com.pdf</code>.
                    Files whose email doesn't match any active employee are kept as <strong>orphans</strong>
                    and can be linked manually from the course page.
                    Re-uploading the same email replaces the file but keeps the existing link.
                </p>
                <input type="file" name="files[]" multiple required class="form-control"
                       accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png">
                <small class="text-muted">Max 500 files per upload, 10 MB per file.</small>
            </div>
            <div class="card-footer d-flex justify-content-end">
                <button class="btn btn-primary"><i class="bi bi-upload me-1"></i>Upload</button>
            </div>
        </form>
    </div>

    @if ($report)
        <div class="card shadow-sm">
            <div class="card-header">
                <strong>Upload report</strong>
                <span class="badge bg-success ms-2">{{ $report['imported'] }} imported</span>
                <span class="badge bg-info ms-1">{{ $report['replaced'] }} replaced</span>
                <span class="badge bg-warning text-dark ms-1">{{ $report['orphaned'] }} orphaned</span>
                <span class="badge bg-danger ms-1">{{ $report['rejected'] }} rejected</span>
            </div>
            @if ($report['orphaned'] > 0)
                <div class="alert alert-warning rounded-0 mb-0 py-2 px-3 small">
                    <i class="bi bi-info-circle me-1"></i>
                    Orphans below have no matching active employee. Type a name or email to
                    pick the right person, then click <strong>Assign</strong>. You can also
                    do this later from the course detail page.
                </div>
            @endif
            <div class="table-responsive">
                <table class="table table-sm mb-0 align-middle">
                    <thead class="table-light">
                        <tr><th>File</th><th>Status</th><th>Detail</th><th>Assign to</th></tr>
                    </thead>
                    <tbody>
                    @foreach ($report['items'] as $row)
                        <tr>
                            <td><code>{{ $row['filename'] }}</code></td>
                            <td>
                                @switch ($row['status'])
                                    @case ('imported')  <span class="badge bg-success">Imported</span>  @break
                                    @case ('replaced')  <span class="badge bg-info">Replaced</span>     @break
                                    @case ('orphaned')  <span class="badge bg-warning text-dark">Orphaned</span> @break
                                    @case ('rejected')  <span class="badge bg-danger">Rejected</span>   @break
                                    @default            <span class="badge bg-secondary">{{ $row['status'] }}</span>
                                @endswitch
                            </td>
                            <td><small>{{ $row['message'] ?? '' }}</small></td>
                            <td style="min-width: 320px;">
                                @if ($row['status'] === 'orphaned' && ! empty($row['certificate_id']))
                                    <form method="POST" action="{{ route('portal.marketing.courses.certificates.relink', [$course, $row['certificate_id']]) }}"
                                          class="d-flex gap-1 align-items-start employee-picker-form">
                                        @csrf
                                        <div class="position-relative flex-grow-1">
                                            <input type="text" class="form-control form-control-sm employee-picker-input"
                                                   placeholder="Type name or email…" autocomplete="off">
                                            <input type="hidden" name="employee_id" class="employee-picker-id">
                                            <div class="list-group position-absolute w-100 employee-picker-results"
                                                 style="z-index: 10; max-height: 240px; overflow-y: auto; display: none;"></div>
                                        </div>
                                        <button type="submit" class="btn btn-sm btn-outline-success employee-picker-submit" disabled>
                                            <i class="bi bi-link me-1"></i>Assign
                                        </button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>

<script>
(function () {
    // Live employee picker for each orphan row. Hits the existing
    // employees.search endpoint and renders a click-to-pick dropdown.
    const SEARCH_URL = @json(route('portal.marketing.courses.employees.search'));

    function debounce(fn, ms) {
        let t; return (...args) => { clearTimeout(t); t = setTimeout(() => fn(...args), ms); };
    }

    document.querySelectorAll('.employee-picker-form').forEach(function (form) {
        const input   = form.querySelector('.employee-picker-input');
        const idField = form.querySelector('.employee-picker-id');
        const results = form.querySelector('.employee-picker-results');
        const submit  = form.querySelector('.employee-picker-submit');

        const search = debounce(function (q) {
            if (q.length < 2) {
                results.style.display = 'none';
                return;
            }
            fetch(SEARCH_URL + '?q=' + encodeURIComponent(q), { headers: { 'Accept': 'application/json' } })
                .then(r => r.json())
                .then(rows => {
                    if (! rows.length) {
                        results.innerHTML = '<div class="list-group-item text-muted small">No matches.</div>';
                        results.style.display = 'block';
                        return;
                    }
                    results.innerHTML = rows.map(r =>
                        '<button type="button" class="list-group-item list-group-item-action small employee-picker-option"'
                        + ' data-id="' + r.id + '" data-label="' + r.name + ' &lt;' + r.email + '&gt;">'
                        + '<strong>' + r.name + '</strong> <span class="text-muted">' + r.email + '</span>'
                        + '</button>'
                    ).join('');
                    results.style.display = 'block';
                })
                .catch(() => { results.style.display = 'none'; });
        }, 250);

        input.addEventListener('input', e => search(e.target.value.trim()));
        input.addEventListener('focus', e => { if (e.target.value.trim().length >= 2) search(e.target.value.trim()); });

        // Click a suggestion → fill input + hidden id + enable submit
        results.addEventListener('click', function (e) {
            const btn = e.target.closest('.employee-picker-option');
            if (! btn) return;
            input.value   = btn.getAttribute('data-label').replace('&lt;', '<').replace('&gt;', '>');
            idField.value = btn.getAttribute('data-id');
            submit.disabled = false;
            results.style.display = 'none';
        });

        // Hide dropdown when clicking outside this form
        document.addEventListener('click', function (e) {
            if (! form.contains(e.target)) results.style.display = 'none';
        });

        // Clearing the input invalidates the picked id
        input.addEventListener('input', function () {
            if (idField.value && ! input.value.includes('<')) {
                idField.value = '';
                submit.disabled = true;
            }
        });
    });
})();
</script>
@endsection
