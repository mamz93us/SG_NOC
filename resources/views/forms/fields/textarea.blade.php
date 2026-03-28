<label class="form-label fw-semibold">{{ $label }}@if($required)<span class="text-danger ms-1">*</span>@endif</label>
<textarea name="{{ $name }}" class="form-control @error($name) is-invalid @enderror"
          rows="3"
          placeholder="{{ $field['placeholder'] ?? '' }}"
          {{ $required ? 'required' : '' }}>{{ old($name) }}</textarea>
@if($helpText)<div class="form-text">{{ $helpText }}</div>@endif
@error($name)<div class="invalid-feedback">{{ $message }}</div>@enderror
