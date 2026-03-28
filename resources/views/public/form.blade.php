<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>{{ $form->name }}</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
body { background:#f4f6f9; min-height:100vh; }
.form-card { max-width:680px; margin:40px auto; background:#fff; border-radius:12px; box-shadow:0 4px 20px rgba(0,0,0,.08); overflow:hidden; }
.form-header { background:linear-gradient(135deg,#0d6efd,#0a58ca); color:#fff; padding:28px 32px; }
.form-body { padding:28px 32px; }
.rating-stars label { font-size:1.8rem; color:#dee2e6; cursor:pointer; }
.rating-stars input:checked ~ label,
.rating-stars input:checked + label,
.rating-stars label:hover,
.rating-stars label:hover ~ label { color:#ffc107; }
.rating-stars { display:flex; flex-direction:row-reverse; justify-content:flex-end; }
[x-cloak] { display:none !important; }
</style>
</head>
@php $settings = \App\Models\Setting::get(); @endphp
<body x-data="dynamicForm()">

<div class="form-card">
    {{-- Header --}}
    <div class="form-header">
        @if($settings->company_logo)
        <div class="mb-3">
            <img src="{{ \Illuminate\Support\Facades\Storage::url($settings->company_logo) }}"
                 alt="{{ $settings->company_name ?? 'Logo' }}"
                 style="height:42px;width:auto;object-fit:contain;filter:brightness(0) invert(1);">
        </div>
        @endif
        <h4 class="mb-1 fw-bold">{{ $form->name }}</h4>
        @if($form->description)
        <p class="mb-0 opacity-75 small">{{ $form->description }}</p>
        @endif
    </div>

    {{-- Body --}}
    <div class="form-body">
        <form method="POST"
              action="{{ route('forms.submit', $form->slug) }}"
              enctype="multipart/form-data"
              x-data="{ submitting: false }"
              @submit="submitting = true"
              novalidate>
            @csrf
            @if($token)
            <input type="hidden" name="_form_token" value="{{ $token->token }}">
            @endif

            @if($errors->any())
            <div class="alert alert-danger py-2 small">
                <ul class="mb-0 ps-3">
                    @foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach
                </ul>
            </div>
            @endif

            <div class="row g-3">
                @php
                    $schema = is_array($form->schema)
                        ? $form->schema
                        : (json_decode($form->schema, true) ?? []);
                @endphp
                @foreach($schema as $field)
                @php
                    $name      = $field['name'] ?? null;
                    $label     = $field['label'] ?? '';
                    $required  = $field['required'] ?? false;
                    $helpText  = $field['help_text'] ?? '';
                    $type      = $field['type'] ?? 'text';
                    $width     = $field['width'] ?? 'full';
                    $colClass  = $width === 'half' ? 'col-md-6' : 'col-12';
                    $condition = $field['conditional'] ?? null;
                    $hasCondition = !empty($condition) && !empty($condition['field']);
                @endphp

                @if($type === 'section')
                <div class="col-12">
                    <h5 class="fw-semibold border-bottom pb-2 mt-2">{{ $label }}</h5>
                </div>
                @elseif($name)
                <div class="{{ $colClass }}"
                     @if($hasCondition)
                     x-show="matchesCondition({{ json_encode($condition) }})"
                     x-transition
                     @endif>
                    @include('forms.fields.'.$type, compact('field','name','label','required','helpText'))
                </div>
                @endif
                @endforeach

                @if($form->settings['collect_email'] ?? false)
                <div class="col-12">
                    <label class="form-label fw-semibold">Your Email</label>
                    <input type="email" name="_email" class="form-control @error('_email') is-invalid @enderror"
                           value="{{ old('_email') }}" placeholder="your@email.com">
                    @error('_email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                @endif
            </div>

            <div class="mt-4 d-flex justify-content-end">
                <button type="submit" class="btn btn-primary px-5" :disabled="submitting">
                    <span x-show="submitting" class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>
                    <span x-text="submitting ? 'Submitting…' : '{{ addslashes($form->settings['submit_label'] ?? 'Submit') }}'"></span>
                </button>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.1/dist/cdn.min.js" defer></script>
<script>
function dynamicForm() {
    return {
        formData: {},

        init() {
            // Seed formData with any old() values already in the DOM on page load
            document.querySelectorAll('input, select, textarea').forEach(el => this._sync(el));
            // Keep formData reactive on every user interaction
            document.addEventListener('input',  e => this._sync(e.target));
            document.addEventListener('change', e => this._sync(e.target));
        },

        _sync(el) {
            if (!el.name) return;
            // Skip internal fields
            if (el.name === '_token' || el.name === '_form_token' || el.name === '_email') return;
            if (el.type === 'checkbox') {
                const checked = document.querySelectorAll('[name="' + el.name + '"]:checked');
                this.formData[el.name] = Array.from(checked).map(c => c.value);
            } else if (el.type === 'radio') {
                if (el.checked) this.formData[el.name] = el.value;
            } else {
                this.formData[el.name] = el.value;
            }
        },

        matchesCondition(condition) {
            if (!condition || !condition.field) return true;
            const raw  = this.formData[condition.field] ?? '';
            const val  = Array.isArray(raw) ? raw : String(raw);
            const test = String(condition.value ?? '');

            switch (condition.operator) {
                case 'equals':
                    return Array.isArray(val) ? val.includes(test) : val === test;
                case 'not_equals':
                    return Array.isArray(val) ? !val.includes(test) : val !== test;
                case 'contains':
                    return Array.isArray(val)
                        ? val.some(v => v.toLowerCase().includes(test.toLowerCase()))
                        : val.toLowerCase().includes(test.toLowerCase());
                case 'not_empty':
                    return Array.isArray(val) ? val.length > 0 : val !== '';
                case 'is_empty':
                    return Array.isArray(val) ? val.length === 0 : val === '';
                default:
                    return true;
            }
        },
    };
}
</script>
</body>
</html>
