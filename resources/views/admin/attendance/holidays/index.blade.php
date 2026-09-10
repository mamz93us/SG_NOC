@extends('layouts.admin')

@section('title', 'Attendance — Holidays')

@section('content')
@include('admin.attendance._tabs')

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-calendar-heart me-2 text-primary"></i>Holidays {{ $year }}</h4>
        <small class="text-muted">
            No absence is recorded on a holiday, and punches on one count as overtime. A holiday can apply to every branch
            or to one — Egypt and KSA keep different calendars. Adding or removing one recalculates those days.
        </small>
    </div>
    <div class="btn-group btn-group-sm">
        <a href="{{ route('admin.attendance.holidays.index', ['year' => $year - 1]) }}" class="btn btn-outline-secondary">
            <i class="bi bi-chevron-left"></i> {{ $year - 1 }}
        </a>
        <a href="{{ route('admin.attendance.holidays.index', ['year' => $year + 1]) }}" class="btn btn-outline-secondary">
            {{ $year + 1 }} <i class="bi bi-chevron-right"></i>
        </a>
    </div>
</div>

@can('manage-attendance')
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-body py-3">
            <form method="POST" action="{{ route('admin.attendance.holidays.store') }}" class="row g-2 align-items-end">
                @csrf
                <div class="col-6 col-md-2">
                    <label class="form-label small mb-1">From</label>
                    <input type="date" name="holiday_date" class="form-control form-control-sm" required value="{{ old('holiday_date') }}">
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label small mb-1">To <span class="text-muted">(several days)</span></label>
                    <input type="date" name="holiday_to" class="form-control form-control-sm" value="{{ old('holiday_to') }}">
                </div>
                <div class="col-md-4">
                    <label class="form-label small mb-1">Name</label>
                    <input name="name" class="form-control form-control-sm" required maxlength="150" value="{{ old('name') }}"
                           placeholder="e.g. Eid al-Adha">
                </div>
                <div class="col-md-3">
                    <label class="form-label small mb-1">Applies to</label>
                    <select name="branch_id" class="form-select form-select-sm">
                        <option value="">All branches</option>
                        @foreach ($branches as $branch)
                            <option value="{{ $branch->id }}" @selected((string) old('branch_id') === (string) $branch->id)>{{ $branch->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-1">
                    <button class="btn btn-sm btn-primary w-100">Add</button>
                </div>
            </form>
        </div>
    </div>
@endcan

<div class="card shadow-sm border-0">
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th style="width:170px">Date</th>
                    <th>Holiday</th>
                    <th>Applies to</th>
                    @can('manage-attendance')
                        <th style="width:60px"></th>
                    @endcan
                </tr>
            </thead>
            <tbody>
                @forelse ($holidays as $holiday)
                    <tr>
                        <td class="text-nowrap">{{ $holiday->holiday_date->format('D d M Y') }}</td>
                        <td class="fw-semibold">{{ $holiday->name }}</td>
                        <td class="small">{{ $holiday->branch?->name ?? 'All branches' }}</td>
                        @can('manage-attendance')
                            <td class="text-end">
                                <form method="POST" action="{{ route('admin.attendance.holidays.destroy', $holiday) }}"
                                      onsubmit="return confirm('Remove this holiday? Those days are recalculated.')">
                                    @csrf
                                    @method('DELETE')
                                    <button class="btn btn-sm btn-outline-danger" title="Remove"><i class="bi bi-x-lg"></i></button>
                                </form>
                            </td>
                        @endcan
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="text-center text-muted py-4">No holidays in {{ $year }}.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
