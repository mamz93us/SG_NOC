<label class="form-label fw-semibold">{{ $label }}@if($required)<span class="text-danger ms-1">*</span>@endif</label>
<input type="file" name="{{ $name }}" class="form-control @error($name) is-invalid @enderror"
       {{ $required ? 'required' : '' }}>
@if($helpText)<div class="form-text">{{ $helpText }}</div>@endif
@error($name)<div class="invalid-feedback">{{ $message }}</div>@enderror
