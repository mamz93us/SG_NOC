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
</style>
</head>
<body x-data="dynamicForm()">

<div class="form-card">
    {{-- Header --}}
    <div class="form-header">
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
                @foreach($form->schema as $field)
                @php
                    $name      = $field['name'] ?? null;
                    $label     = $field['label'] ?? '';
                    $required  = $field['required'] ?? false;
                    $helpText  = $field['help_text'] ?? '';
                    $type      = $field['type'] ?? 'text';
                    $width     = $field['width'] ?? 'full';
                    $colClass  = $width === 'half' ? 'col-md-6' : 'col-12';
                    $condition = $field['conditional'] ?? null;
                @endphp

                @if($type === 'section')
                <div class="col-12">
                    <h5 class="fw-semibold border-bottom pb-2 mt-2">{{ $label }}</h5>
                </div>
                @elseif($name)
                <div class="{{ $colClass }}"
                     @if($condition)
                     x-show="fieldValue('{{ $condition['field'] }}') {{ $condition['operator'] === 'equals' ? '===' : '!==' }} '{{ $condition['value'] }}'"
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
                <button type="submit" class="btn btn-primary px-5">
                    {{ $form->settings['submit_label'] ?? 'Submit' }}
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

        fieldValue(name) {
            const el = document.querySelector('[name="' + name + '"]');
            return el ? el.value : '';
        },
    };
}
</script>
</body>
</html>
