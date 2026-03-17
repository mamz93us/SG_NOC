@extends('layouts.admin')
@section('content')

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-person-badge-fill me-2 text-primary"></i>Employees</h4>
        <small class="text-muted">{{ number_format($total) }} total employees</small>
    </div>
    @can('manage-employees')
    <div class="d-flex gap-2 flex-wrap">
        <form method="POST" action="{{ route('admin.employees.auto-link-contacts') }}" class="d-inline">
            @csrf
            <button type="submit" class="btn btn-outline-success btn-sm">
                <i class="bi bi-link-45deg me-1"></i>Auto-Link Contacts
            </button>
        </form>
        <a href="{{ route('admin.employees.sync') }}" class="btn btn-outline-primary btn-sm">
            <i class="bi bi-cloud-arrow-down me-1"></i>Sync from Azure
        </a>
        <a href="{{ route('admin.employees.create') }}" class="btn btn-primary btn-sm">
            <i class="bi bi-person-plus-fill me-1"></i>Add Employee
        </a>
    </div>
    @endcan
</div>


{{-- Search & Filters --}}
<form method="GET" class="mb-3">
    <div class="input-group input-group-lg shadow-sm">
        <span class="input-group-text bg-white border-end-0 text-muted"><i class="bi bi-search"></i></span>
        <input type="text" name="search" class="form-control border-start-0 border-end-0 ps-0"
               placeholder="Search by name, email, or job title&hellip;"
               value="{{ request('search') }}" autocomplete="off">
        <select name="status" class="form-select flex-grow-0" style="max-width:140px" onchange="this.form.submit()">
            <option value="">All Status</option>
            <option value="active" {{ request('status') === 'active' ? 'selected' : '' }}>Active</option>
            <option value="on_leave" {{ request('status') === 'on_leave' ? 'selected' : '' }}>On Leave</option>
            <option value="terminated" {{ request('status') === 'terminated' ? 'selected' : '' }}>Terminated</option>
        </select>
        <select name="branch_id" class="form-select flex-grow-0" style="max-width:160px" onchange="this.form.submit()">
            <option value="">All Branches</option>
            @foreach($branches as $branch)
            <option value="{{ $branch->id }}" {{ request('branch_id') == $branch->id ? 'selected' : '' }}>{{ $branch->name }}</option>
            @endforeach
        </select>
        <select name="has_assets" class="form-select flex-grow-0" style="max-width:140px" onchange="this.form.submit()">
            <option value="">All Assets</option>
            <option value="yes" {{ request('has_assets') === 'yes' ? 'selected' : '' }}>With Assets</option>
            <option value="no" {{ request('has_assets') === 'no' ? 'selected' : '' }}>No Assets</option>
        </select>
        @if(request()->anyFilled(['search','status','branch_id','has_assets']))
        <a href="{{ route('admin.employees.index') }}" class="btn btn-outline-secondary"><i class="bi bi-x-lg"></i></a>
        @endif
    </div>
</form>

<div class="card shadow-sm border-0">
    <div class="card-body p-0">
        @if($employees->isEmpty())
        <div class="text-center py-5 text-muted">
            <i class="bi bi-person-badge-fill display-4 d-block mb-2"></i>No employees found.
        </div>
        @else
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 small">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">
                            <a href="{{ request()->fullUrlWithQuery(['sort' => 'name', 'direction' => request('sort') == 'name' && request('direction') == 'asc' ? 'desc' : 'asc']) }}" class="text-dark text-decoration-none">
                                Employee {!! request('sort') == 'name' ? (request('direction') == 'asc' ? '<i class="bi bi-sort-up"></i>' : '<i class="bi bi-sort-down"></i>') : '<i class="bi bi-arrows-expand small text-muted"></i>' !!}
                            </a>
                        </th>
                        <th>
                            <a href="{{ request()->fullUrlWithQuery(['sort' => 'branch', 'direction' => request('sort') == 'branch' && request('direction') == 'asc' ? 'desc' : 'asc']) }}" class="text-dark text-decoration-none">
                                Branch {!! request('sort') == 'branch' ? (request('direction') == 'asc' ? '<i class="bi bi-sort-up"></i>' : '<i class="bi bi-sort-down"></i>') : '<i class="bi bi-arrows-expand small text-muted"></i>' !!}
                            </a>
                        </th>
                        <th>
                            <a href="{{ request()->fullUrlWithQuery(['sort' => 'department', 'direction' => request('sort') == 'department' && request('direction') == 'asc' ? 'desc' : 'asc']) }}" class="text-dark text-decoration-none">
                                Department {!! request('sort') == 'department' ? (request('direction') == 'asc' ? '<i class="bi bi-sort-up"></i>' : '<i class="bi bi-sort-down"></i>') : '<i class="bi bi-arrows-expand small text-muted"></i>' !!}
                            </a>
                        </th>
                        <th>
                            <a href="{{ request()->fullUrlWithQuery(['sort' => 'job_title', 'direction' => request('sort') == 'job_title' && request('direction') == 'asc' ? 'desc' : 'asc']) }}" class="text-dark text-decoration-none">
                                Job Title {!! request('sort') == 'job_title' ? (request('direction') == 'asc' ? '<i class="bi bi-sort-up"></i>' : '<i class="bi bi-sort-down"></i>') : '<i class="bi bi-arrows-expand small text-muted"></i>' !!}
                            </a>
                        </th>
                        <th>
                            <a href="{{ request()->fullUrlWithQuery(['sort' => 'status', 'direction' => request('sort') == 'status' && request('direction') == 'asc' ? 'desc' : 'asc']) }}" class="text-dark text-decoration-none">
                                Status {!! request('sort') == 'status' ? (request('direction') == 'asc' ? '<i class="bi bi-sort-up"></i>' : '<i class="bi bi-sort-down"></i>') : '<i class="bi bi-arrows-expand small text-muted"></i>' !!}
                            </a>
                        </th>
                        <th>
                            <a href="{{ request()->fullUrlWithQuery(['sort' => 'assets', 'direction' => request('sort') == 'assets' && request('direction') == 'asc' ? 'desc' : 'asc']) }}" class="text-dark text-decoration-none">
                                Assets {!! request('sort') == 'assets' ? (request('direction') == 'asc' ? '<i class="bi bi-sort-up"></i>' : '<i class="bi bi-sort-down"></i>') : '<i class="bi bi-arrows-expand small text-muted"></i>' !!}
                            </a>
                        </th>
                        <th>
                            <a href="{{ request()->fullUrlWithQuery(['sort' => 'hired', 'direction' => request('sort') == 'hired' && request('direction') == 'asc' ? 'desc' : 'asc']) }}" class="text-dark text-decoration-none">
                                Hired {!! request('sort') == 'hired' ? (request('direction') == 'asc' ? '<i class="bi bi-sort-up"></i>' : '<i class="bi bi-sort-down"></i>') : '<i class="bi bi-arrows-expand small text-muted"></i>' !!}
                            </a>
                        </th>
                        <th class="pe-3"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($employees as $emp)
                    <tr>
                        <td class="ps-3">
                            <div class="d-flex align-items-center gap-2">
                                <div class="rounded-circle bg-primary d-flex align-items-center justify-content-center text-white fw-bold"
                                     style="width:32px;height:32px;font-size:.75rem;flex-shrink:0">
                                    {{ $emp->initials() }}
                                </div>
                                <div>
                                    <div class="fw-semibold">
                                        <a href="{{ route('admin.employees.show', $emp->id) }}" class="text-decoration-none text-dark">
                                            {{ $emp->name }}
                                        </a>
                                    </div>
                                    <div class="text-muted" style="font-size:.75rem">{{ $emp->email }}</div>
                                </div>
                            </div>
                        </td>
                        <td>{{ $emp->branch?->name ?? '—' }}</td>
                        <td>{{ $emp->department?->name ?? '—' }}</td>
                        <td>{{ $emp->job_title ?? '—' }}</td>
                        <td><span class="badge {{ $emp->statusBadgeClass() }}">{{ ucfirst(str_replace('_', ' ', $emp->status)) }}</span></td>
                        <td>
                            @if($emp->active_assets_count > 0)
                                <span class="badge bg-info text-dark">{{ $emp->active_assets_count }}</span>
                            @else
                                <span class="text-muted small">0</span>
                            @endif
                        </td>
                        <td>{{ $emp->hired_date?->format('d M Y') ?? '—' }}</td>
                        <td class="pe-3">
                            <a href="{{ route('admin.employees.show', $emp->id) }}" class="btn btn-sm btn-outline-primary">
                                <i class="bi bi-eye"></i>
                            </a>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="px-3 py-2 border-top d-flex justify-content-between align-items-center flex-wrap gap-2">
            <small class="text-muted">Showing {{ $employees->firstItem() }}&ndash;{{ $employees->lastItem() }} of {{ $employees->total() }}</small>
            {{ $employees->withQueryString()->links() }}
        </div>
        @endif
    </div>
</div>
@endsection
