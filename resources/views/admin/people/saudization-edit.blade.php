@extends('layouts.admin')

@section('title', 'Saudization targets')

@section('content')
@php
    $value = fn (string $field, $group) => old("groups.{$group->id}.{$field}", match ($field) {
        'next_from', 'future_from' => $group->{$field}?->format('Y-m'),
        'required_percent', 'next_percent', 'future_percent' => $group->{$field} === null ? null : rtrim(rtrim(number_format($group->{$field}, 2, '.', ''), '0'), '.'),
        default => $group->{$field},
    });
@endphp

<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-pencil me-2 text-primary"></i>Saudization targets</h4>
        <small class="text-muted">
            The percentage each professional group must reach, the percentages announced for later, and the Oracle job
            category whose people the group counts.
        </small>
    </div>
    <a href="{{ route('admin.people.saudization') }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Back</a>
</div>

@if ($errors->any())
    <div class="alert alert-danger small">
        <ul class="mb-0 ps-3">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<form method="POST" action="{{ route('admin.people.saudization.update') }}">
    @csrf
    @method('PUT')

    <div class="card shadow-sm border-0 mb-3">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="min-width:230px">Professional group (Arabic)</th>
                        <th style="min-width:190px">English name</th>
                        <th style="min-width:200px">Counts job category</th>
                        <th style="width:100px">Required now %</th>
                        <th style="width:240px">Upcoming % · from</th>
                        <th style="width:240px">Future % · from</th>
                        <th style="width:70px" class="text-center">Remove</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($groups as $group)
                        @php $name = "groups[{$group->id}]"; @endphp
                        <tr>
                            <td><input type="text" name="{{ $name }}[name_ar]" value="{{ $value('name_ar', $group) }}" class="form-control form-control-sm" dir="rtl" lang="ar" maxlength="255" required></td>
                            <td><input type="text" name="{{ $name }}[name_en]" value="{{ $value('name_en', $group) }}" class="form-control form-control-sm" maxlength="255"></td>
                            <td>
                                @php $chosen = $value('job_category', $group); @endphp
                                <select name="{{ $name }}[job_category]" class="form-select form-select-sm">
                                    <option value="">— none —</option>
                                    @foreach (collect($categories)->push($chosen)->filter()->unique()->sort() as $category)
                                        <option value="{{ $category }}" @selected($chosen === $category)>{{ $category }}</option>
                                    @endforeach
                                </select>
                            </td>
                            <td><input type="number" name="{{ $name }}[required_percent]" value="{{ $value('required_percent', $group) }}" class="form-control form-control-sm" min="0" max="100" step="0.01" required></td>
                            @foreach (['next', 'future'] as $step)
                                <td>
                                    <div class="input-group input-group-sm">
                                        <input type="number" name="{{ $name }}[{{ $step }}_percent]" value="{{ $value($step.'_percent', $group) }}" class="form-control" min="0" max="100" step="0.01" placeholder="%" style="max-width:80px">
                                        <input type="month" name="{{ $name }}[{{ $step }}_from]" value="{{ $value($step.'_from', $group) }}" class="form-control">
                                    </div>
                                </td>
                            @endforeach
                            <td class="text-center"><input type="checkbox" name="delete[]" value="{{ $group->id }}" class="form-check-input" @checked(in_array($group->id, (array) old('delete', [])))></td>
                        </tr>
                    @endforeach

                    {{-- ── A new group ──────────────────────────────── --}}
                    <tr class="table-light">
                        <td><input type="text" name="new[name_ar]" value="{{ old('new.name_ar') }}" class="form-control form-control-sm" dir="rtl" lang="ar" maxlength="255" placeholder="Add a group…"></td>
                        <td><input type="text" name="new[name_en]" value="{{ old('new.name_en') }}" class="form-control form-control-sm" maxlength="255"></td>
                        <td>
                            <select name="new[job_category]" class="form-select form-select-sm">
                                <option value="">— none —</option>
                                @foreach ($categories as $category)
                                    <option value="{{ $category }}" @selected(old('new.job_category') === $category)>{{ $category }}</option>
                                @endforeach
                            </select>
                        </td>
                        <td><input type="number" name="new[required_percent]" value="{{ old('new.required_percent') }}" class="form-control form-control-sm" min="0" max="100" step="0.01"></td>
                        @foreach (['next', 'future'] as $step)
                            <td>
                                <div class="input-group input-group-sm">
                                    <input type="number" name="new[{{ $step }}_percent]" value="{{ old('new.'.$step.'_percent') }}" class="form-control" min="0" max="100" step="0.01" placeholder="%" style="max-width:80px">
                                    <input type="month" name="new[{{ $step }}_from]" value="{{ old('new.'.$step.'_from') }}" class="form-control">
                                </div>
                            </td>
                        @endforeach
                        <td></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="d-flex justify-content-between align-items-center">
        <small class="text-muted">
            Each job category can be counted by one group. A percentage does not change by itself when its month arrives:
            make it the current one here when it applies.
        </small>
        <button class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Save targets</button>
    </div>
</form>
@endsection
