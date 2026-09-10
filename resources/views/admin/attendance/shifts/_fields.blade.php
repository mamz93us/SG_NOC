@php
    $useOld = $useOld ?? false;
    $val = fn (string $key, $default) => $useOld ? old($key, $default) : $default;
    $prefix = 'shift-'.($shift->id ?? 'new');
    $offDays = array_map('intval', (array) $val('off_days', $shift->off_days ?? []));
@endphp
<div class="row g-2 align-items-end">
    <div class="col-md-3">
        <label class="form-label small mb-1">Name</label>
        <input name="name" class="form-control form-control-sm" required maxlength="100"
               value="{{ $val('name', $shift->name) }}" placeholder="e.g. Office 09:00–17:00">
    </div>
    <div class="col-6 col-md-1">
        <label class="form-label small mb-1">Start</label>
        <input type="time" name="start_time" class="form-control form-control-sm" required
               value="{{ $val('start_time', $shift->exists ? $shift->startHm() : '09:00') }}">
    </div>
    <div class="col-6 col-md-1">
        <label class="form-label small mb-1">End</label>
        <input type="time" name="end_time" class="form-control form-control-sm" required
               value="{{ $val('end_time', $shift->exists ? $shift->endHm() : '17:00') }}">
    </div>
    <div class="col-6 col-md-1">
        <label class="form-label small mb-1" title="Minutes after the start before someone counts as late">Grace in</label>
        <input type="number" name="grace_in_minutes" min="0" max="240" class="form-control form-control-sm" required
               value="{{ $val('grace_in_minutes', $shift->grace_in_minutes ?? 15) }}">
    </div>
    <div class="col-6 col-md-1">
        <label class="form-label small mb-1" title="Minutes before the end someone may leave without it counting as early">Grace out</label>
        <input type="number" name="grace_out_minutes" min="0" max="240" class="form-control form-control-sm" required
               value="{{ $val('grace_out_minutes', $shift->grace_out_minutes ?? 0) }}">
    </div>
    <div class="col-6 col-md-1">
        <label class="form-label small mb-1" title="More than this between check-in and check-out is treated as a forgotten check-out">Max hours</label>
        <input type="number" name="max_hours" min="1" max="24" class="form-control form-control-sm"
               value="{{ $val('max_hours', $shift->max_hours) }}">
    </div>
    <div class="col-6 col-md-1">
        <label class="form-label small mb-1" title="Staying past the end counts as overtime only once it reaches this many minutes">OT after</label>
        <input type="number" name="min_overtime_minutes" min="0" max="600" class="form-control form-control-sm" required
               value="{{ $val('min_overtime_minutes', $shift->min_overtime_minutes ?? 30) }}">
    </div>
    <div class="col-md-3">
        <label class="form-label small mb-1 d-block">Days off</label>
        @foreach (\App\Models\Attendance\AttendanceShift::DAYS as $number => $label)
            <div class="form-check form-check-inline me-2">
                <input class="form-check-input" type="checkbox" name="off_days[]" value="{{ $number }}"
                       id="{{ $prefix }}-day-{{ $number }}" @checked(in_array($number, $offDays, true))>
                <label class="form-check-label small" for="{{ $prefix }}-day-{{ $number }}">{{ $label }}</label>
            </div>
        @endforeach
    </div>
    <div class="col-12">
        <div class="form-check form-switch">
            <input type="hidden" name="is_active" value="0">
            <input class="form-check-input" type="checkbox" role="switch" name="is_active" value="1" id="{{ $prefix }}-active"
                   @checked($val('is_active', $shift->exists ? $shift->is_active : true))>
            <label class="form-check-label small" for="{{ $prefix }}-active">Active <span class="text-muted">— a switched-off shift applies to nobody</span></label>
        </div>
    </div>
</div>
