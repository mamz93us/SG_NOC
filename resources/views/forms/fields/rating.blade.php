@php
    $min = (int)($field['min'] ?? 1);
    $max = (int)($field['max'] ?? 5);
    $old = (int)old($name, 0);
@endphp
<label class="form-label fw-semibold d-block">{{ $label }}@if($required)<span class="text-danger ms-1">*</span>@endif</label>
<div class="rating-stars" role="group">
    @for($i = $max; $i >= $min; $i--)
    <input type="radio" name="{{ $name }}" id="{{ $name }}_{{ $i }}"
           value="{{ $i }}" {{ $old === $i ? 'checked' : '' }}
           {{ $required && $i === $max ? 'required' : '' }}
           class="visually-hidden">
    <label for="{{ $name }}_{{ $i }}" title="{{ $i }}"><i class="bi bi-star-fill"></i></label>
    @endfor
</div>
@if($helpText)<div class="form-text">{{ $helpText }}</div>@endif
@error($name)<div class="text-danger small mt-1">{{ $message }}</div>@enderror
