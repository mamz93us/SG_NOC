<label class="form-label fw-semibold">{{ $label }}@if($required)<span class="text-danger ms-1">*</span>@endif</label>
<select name="{{ $name }}" class="form-select @error($name) is-invalid @enderror" {{ $required ? 'required' : '' }}>
    <option value="">— Select —</option>
    @foreach($field['options'] ?? [] as $opt)
    <option value="{{ $opt }}" {{ old($name) === $opt ? 'selected' : '' }}>{{ $opt }}</option>
    @endforeach
</select>
@if($helpText)<div class="form-text">{{ $helpText }}</div>@endif
@error($name)<div class="invalid-feedback">{{ $message }}</div>@enderror
