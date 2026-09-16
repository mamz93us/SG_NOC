@extends('layouts.admin')
@section('title', 'Linked Accounts')

@section('content')
@php
    $canViewEmployees = auth()->user()?->can('view-employees');
    $canManage = auth()->user()?->can('manage-identity');
    $person = function (array|object $e) use ($canViewEmployees) {
        $e = (array) $e;
        $name = e($e['name'] ?? '—');

        return $canViewEmployees ? '<a href="'.route('admin.employees.show', $e['id']).'">'.$name.'</a>' : $name;
    };
    $signIn = function (?string $azureId) use ($identity) {
        if (! $azureId) {
            return '<span class="badge text-bg-light border">No Microsoft account</span>';
        }
        $user = $identity[$azureId] ?? null;
        if (! $user) {
            return '<span class="badge text-bg-light border">Microsoft account</span>';
        }

        return ($user->account_enabled ? '<span class="badge text-bg-success">Sign-in enabled</span>' : '<span class="badge text-bg-danger">Sign-in disabled</span>')
            .($user->licenses_count ? ' <span class="badge text-bg-light border">'.$user->licenses_count.' licence'.($user->licenses_count === 1 ? '' : 's').'</span>' : '');
    };
@endphp

<div class="d-flex align-items-center mb-4">
    <div>
        <h1 class="h3 mb-0 fw-bold">Linked Accounts</h1>
        <small class="text-muted">One person, several accounts: link them so the NOC treats them as one profile</small>
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
    A linked account belongs to the person's <strong>main record</strong>, the one HR keeps up to date (usually the one with the Oracle HR number).
    From then on the People profile, attendance, leave, the Oracle feed and the assistant treat them as one person.
    The linked account shows the main record's <strong>job title, department, extension and mobile</strong> in its signature, keeping its own
    name, email and branch. Linking fills any of those the main record is missing from the linked account and never overwrites one.
    After linking, run a <a href="{{ route('admin.identity.contact-sync') }}">Bulk Azure Contact Sync</a> to push the data to Azure.
</div>

<form method="GET" action="{{ route('admin.identity.linked-accounts') }}" class="row g-2 align-items-center mb-3">
    <div class="col-md-4">
        <input type="search" name="q" value="{{ $search }}" class="form-control form-control-sm" placeholder="Search a name or email">
    </div>
    <div class="col-auto">
        <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-search me-1"></i>Search</button>
    </div>
    @if($search !== '')
        <div class="col-auto"><a href="{{ route('admin.identity.linked-accounts') }}" class="btn btn-sm btn-link">Clear</a></div>
    @endif
</form>

{{-- ── Suggested links ── --}}
<div class="card border-0 shadow-sm mb-4">
    <form method="POST" action="{{ route('admin.identity.linked-accounts.link') }}">
        @csrf
        <div class="card-header bg-transparent py-3 fw-semibold d-flex align-items-center">
            <span><i class="bi bi-magic me-1 text-warning"></i>Suggested links</span>
            <span class="badge bg-warning-subtle text-warning-emphasis border ms-2">{{ count($suggestions) }}</span>
            <span class="text-muted fw-normal small ms-2">People the NOC holds more than once and nobody has linked yet</span>
            @if($canManage && count($suggestions))
                <button type="submit" class="btn btn-sm btn-success ms-auto"
                        onclick="return confirm('Link every ticked person to their main record?')">
                    <i class="bi bi-link-45deg me-1"></i>Link checked
                </button>
            @endif
        </div>
        <div class="card-body p-0">
            @if(! count($suggestions))
                <div class="text-muted text-center py-5">
                    <i class="bi bi-check2-circle d-block mb-2" style="font-size:1.5rem;"></i>
                    No unlinked accounts found{{ $search !== '' ? ' for this search' : '' }}.
                </div>
            @else
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" style="font-size:13px;">
                    <thead class="text-muted">
                        <tr>
                            @if($canManage)
                                <th class="ps-3" style="width:32px">
                                    <input type="checkbox" class="form-check-input" title="Tick all"
                                           onclick="document.querySelectorAll('.link-pick').forEach(c => c.checked = this.checked)">
                                </th>
                            @endif
                            <th class="{{ $canManage ? '' : 'ps-3' }}">Main record</th>
                            <th>Accounts to link to it</th>
                            <th>Why</th>
                            @if($canManage)<th class="text-end pe-3"></th>@endif
                        </tr>
                    </thead>
                    <tbody>
                    @foreach($suggestions as $i => $s)
                        @php $p = $s['primary']; @endphp
                        <tr>
                            @if($canManage)
                                <td class="ps-3">
                                    <input type="checkbox" class="form-check-input link-pick" name="links[{{ $i }}][selected]" value="1">
                                    <input type="hidden" name="links[{{ $i }}][primary_id]" value="{{ $p['id'] }}">
                                    @foreach($s['secondaries'] as $sec)
                                        <input type="hidden" name="links[{{ $i }}][secondary_ids][]" value="{{ $sec['id'] }}">
                                    @endforeach
                                </td>
                            @endif
                            <td class="{{ $canManage ? '' : 'ps-3' }}">
                                <div class="fw-semibold">{!! $person($p) !!}</div>
                                <div class="text-muted font-monospace">{{ $p['email'] ?: 'no email' }}</div>
                                <div class="small">
                                    @if($p['oracle_emp_no'])<span class="badge text-bg-primary">Oracle {{ $p['oracle_emp_no'] }}</span>@endif
                                    @if(($p['employee_type'] ?? 'standard') !== 'standard')<span class="badge text-bg-secondary">{{ ucfirst($p['employee_type']) }}</span>@endif
                                    {!! $signIn($p['azure_id']) !!}
                                    @if($canSeeAttendance && $p['last_punch'])<span class="text-muted ms-1">punched {{ \Illuminate\Support\Carbon::parse($p['last_punch'])->format('d M Y') }}</span>@endif
                                </div>
                                <div class="text-muted small">{{ collect([$p['job_title'], $p['department'], $p['branch']])->filter()->implode(' · ') }}</div>
                            </td>
                            <td>
                                @foreach($s['secondaries'] as $sec)
                                    <div class="{{ $loop->last ? '' : 'mb-2' }}">
                                        <div>{!! $person($sec) !!} <span class="text-muted font-monospace">{{ $sec['email'] ?: 'no email' }}</span></div>
                                        <div class="small">
                                            @if($sec['oracle_emp_no'])<span class="badge text-bg-primary">Oracle {{ $sec['oracle_emp_no'] }}</span>@endif
                                            @if(($sec['employee_type'] ?? 'standard') !== 'standard')<span class="badge text-bg-secondary">{{ ucfirst($sec['employee_type']) }}</span>@endif
                                            {!! $signIn($sec['azure_id']) !!}
                                            @if($canSeeAttendance && $sec['last_punch'])<span class="text-muted ms-1">punched {{ \Illuminate\Support\Carbon::parse($sec['last_punch'])->format('d M Y') }}</span>@endif
                                            @if($sec['job_title'] || $sec['branch'])<span class="text-muted ms-1">{{ collect([$sec['job_title'], $sec['branch']])->filter()->implode(' · ') }}</span>@endif
                                        </div>
                                    </div>
                                @endforeach
                            </td>
                            <td>
                                @foreach($s['reasons'] as $reason)
                                    <span class="badge bg-light text-dark border d-inline-block mb-1">{{ $reason }}</span>
                                @endforeach
                            </td>
                            @if($canManage)
                                <td class="text-end pe-3">
                                    <button type="submit" name="only" value="{{ $i }}" class="btn btn-outline-success btn-sm text-nowrap"
                                            onclick="return confirm('Link {{ count($s['secondaries']) }} account(s) to {{ addslashes($p['name']) }}?')">
                                        <i class="bi bi-link-45deg me-1"></i>Link
                                    </button>
                                </td>
                            @endif
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            @endif
        </div>
    </form>
</div>

{{-- ── Linked people ── --}}
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-transparent py-3 fw-semibold d-flex align-items-center">
        <span><i class="bi bi-people me-1 text-muted"></i>Linked people</span>
        <span class="badge bg-secondary-subtle text-secondary-emphasis border ms-2">{{ $linkedPeople->count() }} {{ \Illuminate\Support\Str::plural('person', $linkedPeople->count()) }} · {{ $linkCount }} linked {{ \Illuminate\Support\Str::plural('account', $linkCount) }}</span>
    </div>
    <div class="card-body p-0">
        @if($linkedPeople->isEmpty())
            <div class="text-muted text-center py-5">
                <i class="bi bi-link-45deg d-block mb-2" style="font-size:1.5rem;"></i>
                No linked accounts{{ $search !== '' ? ' match this search' : ' yet' }}.
            </div>
        @else
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" style="font-size:13px;">
                <thead class="text-muted">
                    <tr>
                        <th class="ps-3">Main record</th>
                        <th>Job title · department</th>
                        <th>Linked accounts</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($linkedPeople as $linked)
                        @php $p = $linked['primary']; @endphp
                        <tr>
                            <td class="ps-3">
                                @if($p)
                                    <div class="fw-semibold">{!! $person(['id' => $p->id, 'name' => $p->name]) !!}</div>
                                    <div class="text-muted font-monospace">{{ $p->email ?: 'no email' }}</div>
                                    @if($p->oracle_emp_no)<span class="badge text-bg-primary">Oracle {{ $p->oracle_emp_no }}</span>@endif
                                @else
                                    <span class="badge bg-danger-subtle text-danger-emphasis border">main record missing</span>
                                @endif
                            </td>
                            <td>
                                {{ $p?->job_title ?: '—' }}
                                <div class="text-muted">{{ $p?->department?->name ?: $p?->oracle_department ?: '—' }}</div>
                            </td>
                            <td>
                                @foreach($linked['accounts'] as $l)
                                    <div class="d-flex align-items-center gap-2 {{ $loop->last ? '' : 'mb-1' }}">
                                        <span class="font-monospace">{{ $l->email ?: $l->name }}</span>
                                        <span class="text-muted">{{ $l->branch?->name }}</span>
                                        @if($l->identityUser && ! $l->identityUser->account_enabled)
                                            <span class="badge text-bg-danger">Sign-in disabled</span>
                                        @endif
                                        @if($canManage)
                                            <form method="POST" action="{{ route('admin.identity.linked-accounts.destroy', $l) }}"
                                                  onsubmit="return confirm('Unlink {{ addslashes($l->email ?: $l->name) }}? It will stop inheriting HR data.');" class="d-inline ms-auto">
                                                @csrf @method('DELETE')
                                                <button type="submit" class="btn btn-outline-danger btn-sm py-0" title="Unlink">
                                                    <i class="bi bi-x-lg"></i>
                                                </button>
                                            </form>
                                        @endif
                                    </div>
                                @endforeach
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>
</div>

{{-- ── Manual link ── --}}
@if($canManage)
<div class="card border-0 shadow-sm">
    <div class="card-header bg-transparent py-3 fw-semibold">
        <i class="bi bi-link-45deg me-1 text-success"></i>Link an account by hand
    </div>
    <div class="card-body">
        <form method="POST" action="{{ route('admin.identity.linked-accounts.store') }}">
            @csrf
            <div class="row g-3 align-items-end">
                <div class="col-md-4">
                    <label class="form-label small fw-semibold mb-1">Account to link (secondary)</label>
                    <input type="email" name="secondary_email" class="form-control form-control-sm" required
                           value="{{ old('secondary_email') }}" placeholder="name@samirgroup.com" autocomplete="off">
                    <div class="form-text">The sign-in account whose signature and data should follow the main record.</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-semibold mb-1">Main record (the HR record)</label>
                    <input type="email" name="primary_email" class="form-control form-control-sm" required
                           value="{{ old('primary_email') }}" placeholder="name@sssegypt.com" autocomplete="off">
                    <div class="form-text">The record HR keeps up to date.</div>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold mb-1">Branch of the linked account</label>
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
@endif

@endsection
