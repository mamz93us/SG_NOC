<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>{{ $form->name }}</title>
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
@verbatim
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  html, body { height: 100%; font-family: 'Inter', sans-serif; background: #0a0a0a; }

  .flag-bg { position: fixed; inset: 0; z-index: 0; display: flex; overflow: hidden; }
  .flag-half { flex: 1; background-size: cover; background-position: center; transform: scale(1.12); filter: blur(7px) brightness(.5); }
  .flag-bg::after {
    content: ''; position: absolute; inset: 0; pointer-events: none;
    background: linear-gradient(135deg, rgba(0,0,0,.6) 0%, rgba(0,0,0,.25) 50%, rgba(0,0,0,.6) 100%);
  }
  .divider-line {
    position: absolute; left: 50%; top: 0; bottom: 0; width: 3px; transform: translateX(-50%);
    background: linear-gradient(180deg, transparent, rgba(255,215,0,.8) 30%, #fff 50%, rgba(255,215,0,.8) 70%, transparent);
    z-index: 2;
  }

  .app { min-height: 100vh; display: flex; flex-direction: column; position: relative; z-index: 10; }

  .header {
    position: relative; z-index: 10; display: flex; align-items: center; justify-content: center;
    padding: 10px 24px; background: rgba(0,0,0,.7); backdrop-filter: blur(14px);
    border-bottom: 1px solid rgba(255,215,0,.2);
  }
  .header img { height: 64px; object-fit: contain; }

  .content { flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 24px 20px 40px; }

  .trophy-section { display: flex; flex-direction: column; align-items: center; margin-bottom: 20px; filter: drop-shadow(0 0 24px rgba(255,215,0,0.4)); }
  .trophy-section img { height: 132px; object-fit: contain; display: block; filter: drop-shadow(0 0 16px rgba(255,215,0,0.5)); }
  .wc-label { font-family: 'Bebas Neue', Impact, Arial, sans-serif; font-size: 12px; letter-spacing: 4px; color: rgba(255,215,0,0.85); text-shadow: 1px 1px 5px rgba(0,0,0,0.9); margin-top: 6px; }

  .wrapper { width: 100%; max-width: 520px; }
  .card { border-radius: 24px; overflow: hidden; box-shadow: 0 0 0 1px rgba(255,255,255,.12), 0 40px 90px rgba(0,0,0,.65); }

  .card-header { background: linear-gradient(160deg, #004d22 0%, #006C35 60%, #004d22 100%); padding: 28px 28px 22px; text-align: center; position: relative; overflow: hidden; }
  .card-header::before { content: ''; position: absolute; top: -40px; right: -40px; width: 180px; height: 180px; border-radius: 50%; background: rgba(255,255,255,.04); }
  .card-header::after { content: ''; position: absolute; bottom: -30px; left: -30px; width: 120px; height: 120px; border-radius: 50%; background: rgba(255,255,255,.03); }
  .header-eyebrow { font-size: 10px; font-weight: 700; letter-spacing: 2.5px; text-transform: uppercase; color: rgba(255,255,255,.5); margin-bottom: 16px; position: relative; }
  .header-teams { display: flex; align-items: center; justify-content: center; gap: 16px; position: relative; }
  .header-team { display: flex; flex-direction: column; align-items: center; gap: 7px; }
  .header-flag { width: 62px; height: 40px; border-radius: 6px; border: 1.5px solid rgba(255,255,255,.2); object-fit: cover; display: block; }
  .header-team-name { font-family: 'Bebas Neue', sans-serif; font-size: 18px; letter-spacing: 3px; color: #fff; }
  .header-vs-badge { width: 42px; height: 42px; border-radius: 50%; background: rgba(255,255,255,.08); border: 1.5px solid rgba(255,255,255,.2); display: flex; align-items: center; justify-content: center; font-family: 'Bebas Neue', sans-serif; font-size: 13px; color: rgba(255,215,0,.9); letter-spacing: 1px; flex-shrink: 0; margin-top: 22px; }

  .card-body { background: #fff; padding: 30px 32px 36px; }

  .user-bar { display: flex; align-items: center; gap: 10px; background: #f6f6f6; border-radius: 12px; padding: 10px 14px; margin-bottom: 24px; }
  .avatar { width: 38px; height: 38px; border-radius: 50%; background: #006233; display: flex; align-items: center; justify-content: center; font-size: 13px; font-weight: 700; color: #fff; flex-shrink: 0; }
  .user-details { flex: 1; min-width: 0; }
  .user-name-txt { font-size: 13px; font-weight: 600; color: #111; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .user-email-txt { font-size: 11px; color: #999; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .verified-tag { display: flex; align-items: center; gap: 3px; font-size: 11px; font-weight: 600; color: #006233; white-space: nowrap; }

  .section-label { font-size: 10px; font-weight: 700; letter-spacing: 1.8px; text-transform: uppercase; color: #c0c0c0; text-align: center; margin-bottom: 22px; }
  .err { background: #fff5f5; border: 1px solid #ffd0d0; color: #c0392b; border-radius: 10px; padding: 10px 14px; font-size: 13px; margin-bottom: 16px; }
  .score-row { display: flex; align-items: flex-end; justify-content: center; gap: 16px; margin-bottom: 28px; }
  .score-col { flex: 1; display: flex; flex-direction: column; align-items: center; gap: 9px; }
  .score-flag { width: 52px; height: 33px; border-radius: 6px; border: 1px solid rgba(0,0,0,.07); object-fit: cover; display: block; }
  .score-team-label { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .8px; color: #888; }
  .select-wrap { width: 100%; position: relative; }
  .select-wrap::after { content: ''; position: absolute; right: 14px; top: 50%; transform: translateY(-50%); width: 0; height: 0; border-left: 6px solid transparent; border-right: 6px solid transparent; border-top: 7px solid #aaa; pointer-events: none; }
  .score-select { width: 100%; padding: 14px 36px 14px 14px; border: 2px solid #ebebeb; border-radius: 14px; font-family: 'Inter', sans-serif; font-size: 24px; font-weight: 900; color: #111; text-align: center; appearance: none; -webkit-appearance: none; background: #fafafa; cursor: pointer; transition: border-color .15s, background .15s; line-height: 1; }
  .score-select:focus { outline: none; border-color: #006C35; background: #fff; }
  .score-select:hover { border-color: #ccc; }
  .score-sep { font-size: 28px; font-weight: 300; color: #ddd; padding-bottom: 14px; flex-shrink: 0; }

  .divider { height: 1px; background: #f0f0f0; margin-bottom: 24px; }

  .submit-btn { width: 100%; padding: 16px; background: linear-gradient(135deg, #FFD700, #FFA500); color: #000; border: none; border-radius: 14px; font-family: 'Bebas Neue', sans-serif; font-size: 22px; letter-spacing: 3px; cursor: pointer; box-shadow: 0 4px 20px rgba(255,165,0,.35); transition: transform .15s, filter .15s; }
  .submit-btn:hover { transform: translateY(-2px); filter: brightness(1.07); }
  .submit-btn:active { transform: translateY(0); }
  .submit-btn:disabled { opacity: .7; cursor: not-allowed; }

  .success-top { text-align: center; margin-bottom: 20px; }
  .check-ring { width: 68px; height: 68px; border-radius: 50%; background: #edfbf0; border: 2px solid #b7e8c4; display: flex; align-items: center; justify-content: center; margin: 0 auto 14px; }
  .success-title { font-size: 20px; font-weight: 800; color: #111; margin-bottom: 4px; }
  .success-sub { font-size: 13px; color: #888; }
  .result-box { background: #f8f8f8; border-radius: 16px; padding: 22px; margin-bottom: 16px; }
  .result-inner { display: flex; align-items: center; justify-content: center; gap: 24px; }
  .result-team { display: flex; flex-direction: column; align-items: center; gap: 6px; }
  .result-flag { width: 48px; height: 30px; border-radius: 5px; border: 1px solid rgba(0,0,0,.07); object-fit: cover; }
  .result-num { font-size: 36px; font-weight: 900; color: #111; letter-spacing: -1px; line-height: 1; }
  .result-lbl { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .8px; color: #bbb; }
  .result-sep { font-size: 32px; font-weight: 300; color: #ccc; margin-top: -10px; }
  .submitter-note { text-align: center; font-size: 13px; color: #aaa; }
  .submitter-note strong { color: #555; font-weight: 600; }
  .closed-note { text-align:center; font-size:13px; color:#888; padding:8px 0 4px; }

  @keyframes spin { to { transform: rotate(360deg); } }
  .spinner { width: 17px; height: 17px; border: 2px solid rgba(0,0,0,0.25); border-top-color: #000; border-radius: 50%; animation: spin 0.7s linear infinite; display: inline-block; vertical-align: middle; }

  @media (max-width: 480px) {
    .header img { height: 52px; }
    .card-body { padding: 24px 20px 28px; }
    .header-team-name { font-size: 15px; }
    .score-select { font-size: 20px; }
    .trophy-section img { height: 104px; }
  }
</style>
@endverbatim
</head>
@php
    $settings  = \App\Models\Setting::get();
    $wc        = $form->settings['worldcup'] ?? [];
    $home      = $wc['home'] ?? null;
    $away      = $wc['away'] ?? null;
    $flagDir   = trim((string) config('worldcup.flag_path', 'images/flags'), '/');
    $flag      = fn ($code) => asset($flagDir.'/'.$code.'.png');
    $eyebrow   = collect([$wc['stage'] ?? null, $wc['match_date'] ?? null, $wc['kickoff'] ?? null])
                    ->map(fn ($v) => trim((string) $v))->filter()->implode(' · ');
    $submitted = $submitted ?? false;
    $maxGoals  = 10;
    // Identity comes from the per-recipient token (no login).
    $who       = $token?->label ?: ($token?->email ?? '');
    $initials  = collect(preg_split('/\s+/', trim((string) $who)))->filter()
                    ->map(fn ($p) => mb_substr($p, 0, 1))->take(2)->implode('');
    $initials  = strtoupper($initials) ?: '?';
@endphp
<body>

<div class="flag-bg">
    <div class="flag-half" @if($home) style="background-image:url('{{ $flag($home['code']) }}')" @else style="background:#004d22" @endif></div>
    <div class="flag-half" @if($away) style="background-image:url('{{ $flag($away['code']) }}')" @else style="background:#7a1020" @endif></div>
    <div class="divider-line"></div>
</div>

<div class="app">
    <header class="header">
        <img src="{{ asset('images/worldcup/samir_wave.png') }}" alt="{{ $settings->company_name ?? 'Samir Group' }}">
    </header>

    <div class="content">
        <div class="trophy-section">
            <img src="{{ asset('images/worldcup/world_cup_logo_white.png') }}" alt="FIFA World Cup 2026">
            <div class="wc-label">FIFA WORLD CUP 2026</div>
        </div>

        <div class="wrapper">
            <div class="card">
                <div class="card-header">
                    @if($eyebrow)<div class="header-eyebrow">{{ $eyebrow }}</div>@endif
                    <div class="header-teams">
                        <div class="header-team">
                            @if($home)<img src="{{ $flag($home['code']) }}" class="header-flag" alt="{{ $home['name'] }}">@endif
                            <div class="header-team-name">{{ $home['name'] ?? 'Home' }}</div>
                        </div>
                        <div class="header-vs-badge">VS</div>
                        <div class="header-team">
                            @if($away)<img src="{{ $flag($away['code']) }}" class="header-flag" alt="{{ $away['name'] }}">@endif
                            <div class="header-team-name">{{ $away['name'] ?? 'Away' }}</div>
                        </div>
                    </div>
                </div>

                <div class="card-body">
                    @if($submitted)
                        {{-- ── Success ── --}}
                        <div class="success-top">
                            <div class="check-ring">
                                <svg width="32" height="32" viewBox="0 0 24 24" fill="none">
                                    <path d="M5 12.5L9.5 17L19 8" stroke="#27ae60" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                            </div>
                            <div class="success-title">Prediction Submitted!</div>
                            <div class="success-sub">Your score has been recorded. Good luck!</div>
                        </div>
                        <div class="result-box">
                            <div class="result-inner">
                                <div class="result-team">
                                    @if($home)<img src="{{ $flag($home['code']) }}" class="result-flag" alt="">@endif
                                    <div class="result-num">{{ $result['home'] }}</div>
                                    <div class="result-lbl">{{ $home['name'] ?? 'Home' }}</div>
                                </div>
                                <div class="result-sep">–</div>
                                <div class="result-team">
                                    @if($away)<img src="{{ $flag($away['code']) }}" class="result-flag" alt="">@endif
                                    <div class="result-num">{{ $result['away'] }}</div>
                                    <div class="result-lbl">{{ $away['name'] ?? 'Away' }}</div>
                                </div>
                            </div>
                        </div>
                        <p class="submitter-note">Submitted by <strong>{{ $result['name'] }}</strong></p>
                    @else
                        {{-- ── Prediction form ── --}}
                        @if($who)
                        <div class="user-bar">
                            <div class="avatar">{{ $initials }}</div>
                            <div class="user-details">
                                <div style="font-size:9px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:#bbb;">Submitting as</div>
                                <div class="user-name-txt">{{ $who }}</div>
                                @if($token?->email)<div class="user-email-txt">{{ $token->email }}</div>@endif
                            </div>
                            <div class="verified-tag">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none">
                                    <path d="M9 12L11 14L15 10M20.618 5.984A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622C17.176 19.29 21 14.591 21 9c0-1.06-.15-2.084-.432-3.016z" stroke="#006233" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                                Verified
                            </div>
                        </div>
                        @endif

                        <div class="section-label">Your Score Prediction</div>

                        @if($errors->any())
                        <div class="err">{{ $errors->first() }}</div>
                        @endif

                        <form method="POST" action="{{ url()->current() }}" id="predict-form">
                            @csrf
                            @if($token)<input type="hidden" name="_form_token" value="{{ $token->token }}">@endif

                            <div class="score-row">
                                <div class="score-col">
                                    @if($home)<img src="{{ $flag($home['code']) }}" class="score-flag" alt="">@endif
                                    <div class="score-team-label">{{ $home['name'] ?? 'Home' }}</div>
                                    <div class="select-wrap">
                                        <select class="score-select" name="home_score" required>
                                            @for($i = 0; $i <= $maxGoals; $i++)
                                            <option value="{{ $i }}" {{ (string) old('home_score') === (string) $i ? 'selected' : '' }}>{{ $i }}</option>
                                            @endfor
                                        </select>
                                    </div>
                                </div>

                                <div class="score-sep">–</div>

                                <div class="score-col">
                                    @if($away)<img src="{{ $flag($away['code']) }}" class="score-flag" alt="">@endif
                                    <div class="score-team-label">{{ $away['name'] ?? 'Away' }}</div>
                                    <div class="select-wrap">
                                        <select class="score-select" name="away_score" required>
                                            @for($i = 0; $i <= $maxGoals; $i++)
                                            <option value="{{ $i }}" {{ (string) old('away_score') === (string) $i ? 'selected' : '' }}>{{ $i }}</option>
                                            @endfor
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <div class="divider"></div>
                            <button type="submit" class="submit-btn" id="submitBtn">{{ $form->settings['submit_label'] ?? 'Submit Prediction' }}</button>
                        </form>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>

<script>
  var f = document.getElementById('predict-form');
  if (f) f.addEventListener('submit', function () {
    var b = document.getElementById('submitBtn');
    if (b) { b.disabled = true; b.innerHTML = '<span class="spinner"></span>&nbsp; Submitting…'; }
  });
</script>
</body>
</html>
