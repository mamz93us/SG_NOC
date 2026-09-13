{{-- Whole company, or the ticked branches. $prefix keeps element ids unique per form. --}}
<div class="mt-3" data-owner-access>
    <div class="small fw-semibold mb-1">Can see the attendance of</div>
    @foreach (\App\Models\Attendance\AttendanceOwner::SCOPES as $value => $label)
        <div class="form-check form-check-inline">
            <input class="form-check-input" type="radio" name="scope" value="{{ $value }}"
                   id="{{ $prefix }}-scope-{{ $value }}" @checked($scope === $value)>
            <label class="form-check-label small" for="{{ $prefix }}-scope-{{ $value }}">{{ $label }}</label>
        </div>
    @endforeach
    <div class="border rounded p-2 mt-2" data-owner-branches @if ($scope !== \App\Models\Attendance\AttendanceOwner::SCOPE_BRANCHES) hidden @endif>
        <div class="row row-cols-2 row-cols-md-4 g-1">
            @foreach ($branches as $branch)
                <div class="col">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="branch_ids[]" value="{{ $branch->id }}"
                               id="{{ $prefix }}-branch-{{ $branch->id }}"
                               @checked(in_array((int) $branch->id, array_map('intval', (array) $selected), true))>
                        <label class="form-check-label small" for="{{ $prefix }}-branch-{{ $branch->id }}">{{ $branch->name }}</label>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</div>
