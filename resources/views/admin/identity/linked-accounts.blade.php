@extends('layouts.admin')

@section('content')

<div class="d-flex align-items-center mb-4">
    <div>
        <h1 class="h3 mb-0 fw-bold">Linked Accounts</h1>
        <small class="text-muted">Dual-account users — a SamirGroup mailbox that inherits its HR data from the person's SSS record</small>
    </div>
    <div class="ms-auto d-flex gap-2">
        <a href="{{ route('admin.identity.contact-sync') }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left-right me-1"></i>Bulk Azure Contact Sync
        </a>
    </div>
</div>

@if(session('success'))
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        {{ session('success') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
@endif
@if(session('error'))
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        {{ session('error') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
@endif

<div class="alert alert-light border small">
    <i class="bi bi-info-circle me-1 text-primary"></i>
    Link a <strong>SamirGroup</strong> sign-in account to the person's <strong>SSS</strong> employee record. The SamirGroup account then
    shows the SSS master's <strong>job title, department, extension, and mobile</strong>, with branch fixed to the one you pick (JED).
    You only maintain the data on the SSS record. After linking, run a
    <a href="{{ route('admin.identity.contact-sync') }}">Bulk Azure Contact Sync</a> to push it to Azure (so New Outlook / OWA / mobile match too).
</div>

{{-- ── Add link ── --}}
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-transparent py-3 fw-semibold">
        <i class="bi bi-link-45deg me-1 text-success"></i>Link an account
    </div>
    <div class="card-body">
        <form method="POST" action="{{ route('admin.identity.linked-accounts.store') }}">
            @csrf
            <div class="row g-3 align-items-end">
                <div class="col-md-4">
                    <label class="form-label small fw-semibold mb-1">SamirGroup account (secondary)</label>
                    <input type="email" name="secondary_email" class="form-control form-control-sm" required
                           value="{{ old('secondary_email') }}" placeholder="name@samirgroup.com" autocomplete="off">
                    <div class="form-text">The account whose signature/data should follow the SSS record.</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-semibold mb-1">SSS employee (primary — source of data)</label>
                    <input type="email" name="primary_email" class="form-control form-control-sm" required
                           value="{{ old('primary_email') }}" placeholder="name@sssegypt.com" autocomplete="off">
                    <div class="form-text">The HR record you keep updated.</div>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold mb-1">Branch</label>
                    <select name="branch_id" class="form-select form-select-sm" required>
                        @foreach($branches as $b)
                            <option value="{{ $b->id }}" @selected(old('branch_id', $defaultBranchId) == $b->id)>{{ $b->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary btn-sm w-100">
                        <i class="bi bi-plus-circle me-1"></i>Link
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

{{-- ── Existing links ── --}}
<div class="card border-0 shadow-sm">
    <div class="card-header bg-transparent py-3 fw-semibold d-flex align-items-center">
        <span><i class="bi bi-people me-1 text-muted"></i>Linked accounts</span>
        <span class="badge bg-secondary-subtle text-secondary-emphasis border ms-2">{{ $links->count() }}</span>
    </div>
    <div class="card-body p-0">
        @if($links->isEmpty())
            <div class="text-muted text-center py-5">
                <i class="bi bi-link-45deg d-block mb-2" style="font-size:1.5rem;"></i>
                No linked accounts yet. Add one above.
            </div>
        @else
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" style="font-size:13px;">
                <thead class="text-muted">
                    <tr>
                        <th class="ps-3">SamirGroup account</th>
                        <th>Inherits from (SSS)</th>
                        <th>Branch</th>
                        <th>Job title</th>
                        <th>Department</th>
                        <th>Ext.</th>
                        <th>Mobile</th>
                        <th class="text-end pe-3"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($links as $l)
                        @php $p = $l->linkedPrimary; @endphp
                        <tr>
                            <td class="ps-3">
                                <div class="fw-semibold">{{ $l->name }}</div>
                                <div class="text-muted font-monospace">{{ $l->email }}</div>
                            </td>
                            <td>
                                @if($p)
                                    <div>{{ $p->name }}</div>
                                    <div class="text-muted font-monospace">{{ $p->email }}</div>
                                @else
                                    <span class="badge bg-danger-subtle text-danger-emphasis border">primary missing</span>
                                @endif
                            </td>
                            <td>{{ $l->branch?->name ?? '—' }}</td>
                            <td>{{ $p?->job_title ?: '—' }}</td>
                            <td>{{ $p?->department?->name ?: $p?->oracle_department ?: '—' }}</td>
                            <td class="font-monospace">{{ $p?->extension_number ?: '—' }}</td>
                            <td class="font-monospace">{{ $p?->mobile_phone ?: '—' }}</td>
                            <td class="text-end pe-3">
                                <form method="POST" action="{{ route('admin.identity.linked-accounts.destroy', $l) }}"
                                      onsubmit="return confirm('Unlink {{ $l->email }}? It will stop inheriting HR data.');" class="d-inline">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="btn btn-outline-danger btn-sm" title="Unlink">
                                        <i class="bi bi-x-lg"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>
</div>

@endsection
