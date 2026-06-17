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
body { background:#0b1f17; background-image:linear-gradient(160deg,#0b1f17,#0a1530); min-height:100vh; }
.wc-card { max-width:640px; margin:32px auto; background:#fff; border-radius:16px; box-shadow:0 12px 40px rgba(0,0,0,.35); overflow:hidden; }
.wc-banner { display:block; width:100%; height:auto; }
.wc-logo { text-align:center; padding:18px 24px 0; }
.wc-logo img { height:40px; width:auto; object-fit:contain; }
.wc-body { padding:24px 28px 30px; }
.wc-fixture { display:flex; align-items:center; justify-content:center; gap:8px; margin:6px 0 22px; }
.wc-team { flex:1; text-align:center; }
.wc-team img { width:84px; height:auto; border-radius:6px; box-shadow:0 2px 8px rgba(0,0,0,.18); }
.wc-team .name { font-weight:700; margin-top:8px; font-size:1.05rem; }
.wc-vs { font-weight:800; color:#6c47ff; font-size:1.3rem; padding:0 4px; }
.wc-score { display:flex; align-items:center; justify-content:center; gap:18px; margin-bottom:8px; }
.wc-score input { width:96px; height:84px; font-size:2.6rem; font-weight:800; text-align:center; border-radius:14px;
    border:2px solid #dee2e6; }
.wc-score input:focus { border-color:#6c47ff; box-shadow:0 0 0 .25rem rgba(108,71,255,.2); outline:none; }
.wc-score .sep { font-size:2rem; font-weight:800; color:#adb5bd; }
.wc-kick { text-align:center; color:#6c757d; font-size:.9rem; margin-bottom:18px; }
.wc-submit { background:linear-gradient(135deg,#0a7d3c,#6c47ff); border:none; font-weight:700; }
[x-cloak] { display:none !important; }
</style>
</head>
@php
    $settings = \App\Models\Setting::get();
    $wc       = $form->settings['worldcup'] ?? [];
    $home     = $wc['home'] ?? null;
    $away     = $wc['away'] ?? null;
    $flagDir  = trim((string) config('worldcup.flag_path', 'images/flags'), '/');
    $flag     = fn ($code) => asset($flagDir.'/'.$code.'.png');
    $schema   = is_array($form->schema) ? $form->schema : (json_decode($form->schema, true) ?? []);
    // Fields other than the two score boxes (e.g. an optional comment) are rendered below.
    $extraFields = collect($schema)->reject(fn ($f) => in_array($f['name'] ?? '', ['home_score', 'away_score'], true));
@endphp
<body x-data="{ submitting:false }">

<div class="wc-card">
    @if($settings->company_logo)
    <div class="wc-logo">
        <img src="{{ \Illuminate\Support\Facades\Storage::url($settings->company_logo) }}" alt="{{ $settings->company_name ?? 'Logo' }}">
    </div>
    @endif

    <img class="wc-banner" src="{{ asset('images/worldcup-banner.svg') }}" alt="World Cup 2026">

    <div class="wc-body">
        <h4 class="fw-bold text-center mb-1">{{ $form->name }}</h4>
        @if($form->description)
        <p class="text-muted text-center small mb-3">{{ $form->description }}</p>
        @endif

        {{-- Fixture --}}
        <div class="wc-fixture">
            <div class="wc-team">
                @if($home)
                <img src="{{ $flag($home['code']) }}" alt="{{ $home['name'] }} flag" onerror="this.style.display='none'">
                <div class="name">{{ $home['name'] }}</div>
                @else
                <div class="name text-muted">Home team</div>
                @endif
            </div>
            <div class="wc-vs">VS</div>
            <div class="wc-team">
                @if($away)
                <img src="{{ $flag($away['code']) }}" alt="{{ $away['name'] }} flag" onerror="this.style.display='none'">
                <div class="name">{{ $away['name'] }}</div>
                @else
                <div class="name text-muted">Away team</div>
                @endif
            </div>
        </div>

        @if(!empty($wc['kickoff']))
        <div class="wc-kick"><i class="bi bi-clock me-1"></i>Kick-off: {{ $wc['kickoff'] }}</div>
        @endif

        @if($errors->any())
        <div class="alert alert-danger py-2 small">
            <ul class="mb-0 ps-3">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
        </div>
        @endif

        <form method="POST" action="{{ route('forms.submit', $form->slug) }}"
              enctype="multipart/form-data" @submit="submitting = true" novalidate>
            @csrf
            @if($token)<input type="hidden" name="_form_token" value="{{ $token->token }}">@endif

            <div class="wc-score">
                <input type="number" name="home_score" min="0" max="20" inputmode="numeric"
                       value="{{ old('home_score') }}" placeholder="0" required
                       aria-label="{{ $home['name'] ?? 'Home' }} goals">
                <span class="sep">:</span>
                <input type="number" name="away_score" min="0" max="20" inputmode="numeric"
                       value="{{ old('away_score') }}" placeholder="0" required
                       aria-label="{{ $away['name'] ?? 'Away' }} goals">
            </div>
            <p class="text-center text-muted small mb-4">
                {{ $home['name'] ?? 'Home' }} &nbsp;—&nbsp; {{ $away['name'] ?? 'Away' }}
            </p>

            {{-- Any extra fields the builder added (e.g. an optional comment) --}}
            @if($extraFields->isNotEmpty())
            <div class="row g-3 mb-2">
                @foreach($extraFields as $field)
                @php
                    $name     = $field['name'] ?? null;
                    $label    = $field['label'] ?? '';
                    $required = $field['required'] ?? false;
                    $helpText = $field['help_text'] ?? '';
                    $type     = $field['type'] ?? 'text';
                    $width    = $field['width'] ?? 'full';
                    $colClass = $width === 'half' ? 'col-md-6' : 'col-12';
                @endphp
                @if($type === 'section')
                <div class="col-12"><h6 class="fw-semibold border-bottom pb-2 mt-2">{{ $label }}</h6></div>
                @elseif($name)
                <div class="{{ $colClass }}">
                    @include('forms.fields.'.$type, compact('field','name','label','required','helpText'))
                </div>
                @endif
                @endforeach
            </div>
            @endif

            @if($form->settings['collect_email'] ?? false)
            <div class="mb-3">
                <label class="form-label fw-semibold">Your Email</label>
                <input type="email" name="_email" class="form-control @error('_email') is-invalid @enderror"
                       value="{{ old('_email') }}" placeholder="your@email.com">
                @error('_email')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            @endif

            <div class="d-grid mt-3">
                <button type="submit" class="btn btn-lg wc-submit text-white" :disabled="submitting">
                    <span x-show="submitting" class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>
                    <span x-text="submitting ? 'Submitting…' : '{{ addslashes($form->settings['submit_label'] ?? 'Submit my guess') }}'"></span>
                </button>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.1/dist/cdn.min.js" defer></script>
</body>
</html>
