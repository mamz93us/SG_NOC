<label class="form-label fw-semibold d-block">{{ $label }}@if($required)<span class="text-danger ms-1">*</span>@endif</label>
@foreach($field['options'] ?? [] as $opt)
<div class="form-check">
    <input class="form-check-input @error($name) is-invalid @enderror" type="radio"
           name="{{ $name }}" id="{{ $name }}_{{ $loop->index }}"
           value="{{ $opt }}" {{ old($name) === $opt ? 'checked' : '' }} {{ $required && $loop->first ? 'required' : '' }}>
    <label class="form-check-label" for="{{ $name }}_{{ $loop->index }}">{{ $opt }}</label>
</div>
@endforeach
@if($helpText)<div class="form-text">{{ $helpText }}</div>@endif
@error($name)<div class="text-danger small mt-1">{{ $message }}</div>@enderror
