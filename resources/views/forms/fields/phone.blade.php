<label class="form-label fw-semibold">{{ $label }}@if($required)<span class="text-danger ms-1">*</span>@endif</label>
<input type="tel" name="{{ $name }}" class="form-control @error($name) is-invalid @enderror"
       value="{{ old($name) }}"
       placeholder="{{ $field['placeholder'] ?? '+971 …' }}"
       {{ $required ? 'required' : '' }}>
@if($helpText)<div class="form-text">{{ $helpText }}</div>@endif
@error($name)<div class="invalid-feedback">{{ $message }}</div>@enderror
