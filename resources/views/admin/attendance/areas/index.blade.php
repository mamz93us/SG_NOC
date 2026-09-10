@extends('layouts.admin')

@section('title', 'Attendance — Areas & Terminals')

@section('content')
@include('admin.attendance._tabs')

<div class="mb-3">
    <h4 class="mb-0 fw-bold"><i class="bi bi-geo-alt me-2 text-primary"></i>Areas &amp; Terminals</h4>
    <small class="text-muted">
        Each BioTime area is mapped once to a NOC branch and the time zone its terminals keep. The branch is shown on every
        punch from that area, and settles a BioTime code whose Oracle number belongs to two people.
        New areas are guessed from the branch keywords used for Azure; check them here.
    </small>
</div>

<div class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-transparent fw-semibold">Areas</div>
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Area</th>
                    <th>Last punch</th>
                    <th style="min-width:460px">Branch &amp; time zone</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($areas as $area)
                    <tr>
                        <td>
                            <div class="fw-semibold">{{ $area->area_alias }}</div>
                            <div class="small text-muted">{{ $area->source?->name }}</div>
                        </td>
                        <td class="small text-nowrap">{{ $area->last_punch_at?->format('d M Y H:i') ?? '—' }}</td>
                        <td>
                            @can('manage-attendance')
                                <form method="POST" action="{{ route('admin.attendance.areas.update', $area) }}" class="d-flex gap-2">
                                    @csrf
                                    @method('PUT')
                                    <select name="branch_id" class="form-select form-select-sm {{ $area->branch_id ? '' : 'border-warning' }}">
                                        <option value="">— no branch —</option>
                                        @foreach ($branches as $branch)
                                            <option value="{{ $branch->id }}" @selected($area->branch_id === $branch->id)>{{ $branch->name }}</option>
                                        @endforeach
                                    </select>
                                    <select name="timezone" class="form-select form-select-sm" style="max-width:190px">
                                        <option value="">Server default ({{ config('app.timezone') }})</option>
                                        @foreach ($timezones as $tz)
                                            <option value="{{ $tz }}" @selected($area->timezone === $tz)>{{ $tz }}</option>
                                        @endforeach
                                    </select>
                                    <button class="btn btn-sm btn-outline-primary">Save</button>
                                </form>
                            @else
                                {{ $area->branch?->name ?? '—' }}
                                <span class="small text-muted">· {{ $area->timezone ?: config('app.timezone') }}</span>
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="3" class="text-center text-muted py-4">No areas yet — they appear after the first sync.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="card shadow-sm border-0">
    <div class="card-header bg-transparent fw-semibold">Terminals</div>
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Terminal</th>
                    <th>Serial</th>
                    <th>Area</th>
                    <th>Last punch</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($terminals as $terminal)
                    <tr>
                        <td>
                            <div class="fw-semibold">{{ $terminal->terminal_alias ?: '—' }}</div>
                            <div class="small text-muted">{{ $terminal->source?->name }}</div>
                        </td>
                        <td class="small font-monospace">{{ $terminal->terminal_sn }}</td>
                        <td class="small">{{ $terminal->area_alias ?: '—' }}</td>
                        <td class="small text-nowrap">
                            @if ($terminal->last_punch_at)
                                {{ $terminal->last_punch_at->format('d M Y H:i') }}
                            @else
                                —
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="text-center text-muted py-4">No terminals yet — they appear after the first sync.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
