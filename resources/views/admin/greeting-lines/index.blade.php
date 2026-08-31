@extends('layouts.admin')

@section('title', 'Greeting Lines')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-chat-heart-fill me-2 text-primary"></i>Greeting Lines</h4>
        <small class="text-muted">
            The friendly line under &ldquo;Good morning, {name}&rdquo; on the employee home portal
        </small>
    </div>
</div>

@if (session('success'))
    <div class="alert alert-success d-flex gap-2 align-items-start">
        <i class="bi bi-check-circle-fill fs-5"></i>
        <div>{{ session('success') }}</div>
    </div>
@endif

<div class="alert alert-info d-flex gap-2">
    <i class="bi bi-info-circle fs-5"></i>
    <div class="small">
        One line is picked per person per hour and stays put on a refresh, so nobody sees the
        text flicker. Leave <strong>Time</strong> or <strong>Day</strong> blank for &ldquo;any&rdquo;.
        <strong>If this list is empty the portal uses a built-in set</strong> &mdash; the greeting is
        never blank.
    </div>
</div>

<div class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-white fw-semibold">
        <i class="bi bi-plus-lg me-1 text-primary"></i>Add a line
    </div>
    <div class="card-body">
        <form method="POST" action="{{ route('admin.greeting-lines.store') }}" class="row g-3 align-items-end">
            @csrf
            <div class="col-md-4">
                <label class="form-label fw-semibold">Text <span class="text-danger">*</span></label>
                <input type="text" name="text" maxlength="200" required
                       class="form-control @error('text') is-invalid @enderror"
                       value="{{ old('text') }}" placeholder="Hope your day starts smoothly.">
                @error('text') <span class="invalid-feedback">{{ $message }}</span> @enderror
            </div>
            <div class="col-md-3">
                <label class="form-label fw-semibold">Text (Arabic)</label>
                <input type="text" name="text_ar" maxlength="200" dir="rtl" class="form-control"
                       value="{{ old('text_ar') }}">
            </div>
            <div class="col-md-2">
                <label class="form-label fw-semibold">Time</label>
                <select name="time_of_day" class="form-select">
                    <option value="">Any</option>
                    @foreach($times as $time)
                        <option value="{{ $time }}" @selected(old('time_of_day') === $time)>{{ ucfirst($time) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label fw-semibold">Day</label>
                <select name="day_of_week" class="form-select">
                    <option value="">Any</option>
                    @foreach($days as $num => $label)
                        <option value="{{ $num }}" @selected((string) old('day_of_week') === (string) $num)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-1">
                <button type="submit" class="btn btn-primary w-100" aria-label="Add line"><i class="bi bi-plus-lg"></i></button>
            </div>
        </form>
    </div>
</div>

{{--
    The row forms live OUTSIDE the table and the inputs reference them with the
    HTML5 `form` attribute. A <form> wrapping a run of <td>s is invalid markup:
    the parser hoists it out of the <tbody> and every field in the row silently
    stops submitting.
--}}
@foreach($lines as $line)
    <form id="line-{{ $line->id }}" method="POST" action="{{ route('admin.greeting-lines.update', $line) }}">
        @csrf
        @method('PUT')
    </form>
    <form id="line-del-{{ $line->id }}" method="POST" action="{{ route('admin.greeting-lines.destroy', $line) }}"
          onsubmit="return confirm('Delete this greeting line?');">
        @csrf
        @method('DELETE')
    </form>
@endforeach

<div class="card shadow-sm border-0">
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th style="min-width:260px">Text</th>
                    <th style="min-width:200px">Arabic</th>
                    <th style="width:130px">Time</th>
                    <th style="width:140px">Day</th>
                    <th style="width:90px">Order</th>
                    <th style="width:90px" class="text-center">Active</th>
                    <th style="width:120px"></th>
                </tr>
            </thead>
            <tbody>
                @forelse($lines as $line)
                    <tr>
                        <td>
                            <input type="text" form="line-{{ $line->id }}" name="text" maxlength="200" required
                                   class="form-control form-control-sm" value="{{ $line->text }}">
                        </td>
                        <td>
                            <input type="text" form="line-{{ $line->id }}" name="text_ar" maxlength="200" dir="rtl"
                                   class="form-control form-control-sm" value="{{ $line->text_ar }}">
                        </td>
                        <td>
                            <select form="line-{{ $line->id }}" name="time_of_day" class="form-select form-select-sm">
                                <option value="">Any</option>
                                @foreach($times as $time)
                                    <option value="{{ $time }}" @selected($line->time_of_day === $time)>{{ ucfirst($time) }}</option>
                                @endforeach
                            </select>
                        </td>
                        <td>
                            <select form="line-{{ $line->id }}" name="day_of_week" class="form-select form-select-sm">
                                <option value="">Any</option>
                                @foreach($days as $num => $label)
                                    <option value="{{ $num }}" @selected($line->day_of_week === $num)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </td>
                        <td>
                            <input type="number" form="line-{{ $line->id }}" name="sort_order" min="0" max="9999"
                                   class="form-control form-control-sm" value="{{ $line->sort_order }}">
                        </td>
                        <td class="text-center">
                            <div class="form-check form-switch d-inline-block">
                                <input class="form-check-input" type="checkbox" form="line-{{ $line->id }}"
                                       name="is_active" value="1" {{ $line->is_active ? 'checked' : '' }}
                                       aria-label="Active">
                            </div>
                        </td>
                        <td class="text-end">
                            <button form="line-{{ $line->id }}" class="btn btn-sm btn-outline-primary" aria-label="Save">
                                <i class="bi bi-save"></i>
                            </button>
                            <button form="line-del-{{ $line->id }}" class="btn btn-sm btn-outline-danger" aria-label="Delete">
                                <i class="bi bi-trash"></i>
                            </button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="text-center text-muted py-5">
                            <i class="bi bi-chat-heart fs-3 d-block mb-2"></i>
                            No custom lines &mdash; the portal is using its built-in greetings.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
