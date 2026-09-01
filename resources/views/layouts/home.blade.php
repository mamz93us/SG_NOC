<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="{{ csrf_token() }}">
<meta name="robots" content="noindex, nofollow">
<title>@yield('title', 'Samir Group | Employee Portal')</title>
<link rel="icon" href="{{ asset('images/brand/samir-mark.png') }}">
{{--
    Fonts are self-hosted deliberately. This page opens on every browser launch
    on every PC, including branches reaching the NOC over the Azure VPN — a
    round trip to fonts.googleapis.com on that critical path is the wrong trade,
    and it would also mean the start page renders unstyled whenever the internet
    is down but the tunnel is up. The stack degrades to Segoe UI, which is on
    every Windows machine in the fleet anyway.
--}}
<style>
  :root{
    /* Samir brand palette — from the Email Signature Guidelines (EC2024 / 58595B / D8D8D8) */
    --gray-900:#2B2C2D;
    --gray-800:#3E3F41;
    --gray-700:#58595B;
    --gray-600:#77787A;
    --gray-500:#9A9B9D;
    --red-600:#EC2024;
    --red-500:#F13337;
    --red-700:#C81A1E;
    --red-100:#FCE6E6;
    --bg:#F4F4F5;
    --card:#FFFFFF;
    --ink:#2B2C2D;
    --ink-soft:#6B6C6E;
    --line:#E4E4E5;
    --green:#16A34A;
    --amber:#D97706;
    --shadow: 0 1px 2px rgba(43,44,45,.05), 0 8px 24px rgba(43,44,45,.06);
    --shadow-hover: 0 4px 10px rgba(43,44,45,.08), 0 18px 40px rgba(43,44,45,.12);
    --font-sans:'Montserrat','Segoe UI',-apple-system,BlinkMacSystemFont,Roboto,sans-serif;
    --font-ar:'Cairo','Segoe UI',Tahoma,sans-serif;
  }
  *{box-sizing:border-box; margin:0; padding:0;}
  html{height:100%;}
  body{
    background:var(--bg);
    color:var(--ink);
    font-family:var(--font-sans);
    -webkit-font-smoothing:antialiased;
    min-height:100vh;
    display:flex;
    flex-direction:column;
  }
  a{color:inherit;}

  /* ===== Header ===== */
  header{
    position:relative;
    flex-shrink:0;
    background:linear-gradient(120deg, var(--gray-900) 0%, var(--gray-800) 55%, var(--gray-700) 100%);
    overflow:hidden;
    padding:22px clamp(20px, 4vw, 56px);
  }
  header::before{
    content:"";
    position:absolute; inset:0;
    background:
      radial-gradient(600px 240px at 85% -20%, rgba(236,32,36,.18), transparent 60%),
      radial-gradient(400px 200px at 15% 120%, rgba(236,32,36,.10), transparent 60%);
    pointer-events:none;
  }
  .header-inner{
    position:relative;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:24px;
    max-width:1440px;
    margin:0 auto;
  }
  .brand{ display:flex; align-items:center; gap:20px; }
  .brand img{ height:38px; width:auto; display:block; filter:brightness(0) invert(1); }
  .brand-divider{ width:1px; height:34px; background:rgba(255,255,255,.25); }
  .brand-title h1{
    font-family:var(--font-sans);
    font-weight:700;
    font-size:clamp(18px, 2.4vw, 24px);
    letter-spacing:.4px;
    color:#fff;
  }
  .brand-title p{ font-family:var(--font-ar); font-size:13px; color:rgba(255,255,255,.6); margin-top:2px; }

  .header-right{ display:flex; align-items:center; gap:22px; }
  .header-clock{ display:flex; align-items:center; gap:12px; color:#fff; text-align:right; }
  .header-clock svg{ flex-shrink:0; opacity:.85; }
  .header-clock .time{ font-size:20px; font-weight:600; letter-spacing:.3px; line-height:1.15; }
  .header-clock .date{ font-size:12.5px; color:rgba(255,255,255,.62); margin-top:1px; }
  .header-signout{
    background:rgba(255,255,255,.08);
    border:1px solid rgba(255,255,255,.16);
    color:rgba(255,255,255,.75);
    border-radius:10px;
    padding:8px 14px;
    font-family:var(--font-sans);
    font-size:12.5px;
    font-weight:600;
    cursor:pointer;
    transition:background .18s ease, color .18s ease;
  }
  .header-signout:hover{ background:rgba(255,255,255,.16); color:#fff; }

  /* ===== Main ===== */
  main{
    flex:1;
    max-width:1440px;
    width:100%;
    margin:0 auto;
    padding:clamp(24px,4vw,44px) clamp(20px,4vw,56px) 60px;
  }
  .section-label{
    font-size:12px;
    font-weight:600;
    letter-spacing:1.6px;
    text-transform:uppercase;
    color:var(--ink-soft);
    margin:0 0 14px 2px;
  }
  .grid-primary{ display:grid; grid-template-columns:repeat(3, 1fr); gap:20px; }
  .grid-secondary{ display:grid; grid-template-columns:repeat(4, 1fr); gap:20px; margin-top:14px; }

  /* ===== Greeting ===== */
  .greeting{ margin:0 0 26px 2px; }
  .greeting h2{
    font-size:clamp(22px, 3vw, 30px);
    font-weight:700;
    letter-spacing:-.2px;
    color:var(--ink);
  }
  .greeting h2 .greet-name{ color:var(--red-600); }
  .greeting .greet-line{
    margin-top:6px;
    font-size:14.5px;
    color:var(--ink-soft);
  }
  .greeting .greet-line .ar{ font-family:var(--font-ar); }
  .greeting .greet-meta{
    margin-top:10px;
    font-size:12.5px;
    color:var(--gray-500);
    display:flex;
    align-items:center;
    gap:14px;
    flex-wrap:wrap;
  }
  .greeting .greet-meta span{ display:inline-flex; align-items:center; gap:6px; }
  .greeting .greet-meta svg{ width:14px; height:14px; opacity:.8; }

  /* ===== Announcement slider ===== */
  .ann-slider{
    position:relative;
    border-radius:18px;
    overflow:hidden;
    background:linear-gradient(135deg, var(--gray-900) 0%, var(--gray-700) 100%);
    color:#fff;
    box-shadow:var(--shadow);
    margin-bottom:30px;
    min-height:132px;
  }
  .ann-slider::before{
    content:"";
    position:absolute; inset:0;
    background:radial-gradient(520px 220px at 92% -30%, rgba(236,32,36,.28), transparent 62%);
    pointer-events:none;
  }
  .ann-track{ position:relative; }
  .ann-slide{
    position:absolute;
    inset:0;
    padding:24px clamp(52px, 6vw, 68px) 24px clamp(22px, 3vw, 30px);
    display:flex;
    flex-direction:column;
    justify-content:center;
    gap:8px;
    opacity:0;
    transform:translateX(18px);
    /* Outgoing slide: fade out quickly, no delay. */
    transition:opacity .22s ease, transform .22s ease;
    pointer-events:none;
  }
  .ann-slide.active{
    opacity:1;
    transform:translateX(0);
    pointer-events:auto;
    position:relative;
    /* Incoming slide waits for the outgoing one to clear. Without the delay
       both slides occupy the same box at partial opacity for ~400ms and the
       two headlines are legibly printed over each other. */
    transition:opacity .32s ease .22s, transform .32s ease .22s;
  }
  .ann-slide-top{ display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
  .ann-pill{
    font-size:10.5px;
    font-weight:700;
    letter-spacing:.6px;
    text-transform:uppercase;
    padding:3px 9px;
    border-radius:20px;
    background:rgba(255,255,255,.18);
    border:1px solid rgba(255,255,255,.22);
  }
  .ann-pill.sev-urgent{ background:var(--red-600); border-color:var(--red-600); }
  .ann-pill.sev-success{ background:var(--green); border-color:var(--green); }
  .ann-date{ font-size:12px; color:rgba(255,255,255,.6); }
  .ann-slide h3{ font-size:19px; font-weight:700; line-height:1.3; }
  .ann-slide p{ font-size:13.5px; line-height:1.55; color:rgba(255,255,255,.78); max-width:78ch; }
  .ann-slide a.ann-link{
    align-self:flex-start;
    margin-top:2px;
    font-size:12.5px;
    font-weight:700;
    color:#fff;
    text-decoration:none;
    border-bottom:1.5px solid var(--red-500);
    padding-bottom:1px;
  }
  .ann-nav{
    position:absolute;
    top:50%;
    transform:translateY(-50%);
    width:34px; height:34px;
    border-radius:50%;
    border:1px solid rgba(255,255,255,.2);
    background:rgba(255,255,255,.1);
    color:#fff;
    display:flex; align-items:center; justify-content:center;
    cursor:pointer;
    z-index:2;
    transition:background .18s ease;
  }
  .ann-nav:hover{ background:rgba(255,255,255,.22); }
  .ann-nav svg{ width:16px; height:16px; }
  .ann-prev{ right:calc(clamp(22px, 3vw, 30px) + 42px); }
  .ann-next{ right:clamp(22px, 3vw, 30px); }
  .ann-dots{
    position:absolute;
    left:clamp(22px, 3vw, 30px);
    bottom:14px;
    display:flex;
    gap:7px;
    z-index:2;
  }
  .ann-dot{
    width:7px; height:7px;
    border-radius:50%;
    border:0;
    padding:0;
    background:rgba(255,255,255,.3);
    cursor:pointer;
    transition:background .2s ease, width .2s ease;
  }
  .ann-dot.active{ background:var(--red-500); width:20px; border-radius:20px; }
  .ann-single .ann-nav, .ann-single .ann-dots{ display:none; }

  /* ===== Card base ===== */
  .card{
    position:relative;
    background:var(--card);
    border:1px solid var(--line);
    border-radius:18px;
    box-shadow:var(--shadow);
    padding:26px 22px;
    cursor:pointer;
    transition:transform .22s ease, box-shadow .22s ease, border-color .22s ease;
    display:flex;
    flex-direction:column;
    align-items:flex-start;
    gap:16px;
    overflow:hidden;
    text-decoration:none;
    color:inherit;
    font:inherit;
    text-align:left;
    width:100%;
  }
  .card::after{
    content:"";
    position:absolute; left:0; right:0; bottom:0;
    height:3px;
    background:linear-gradient(90deg, var(--red-600), var(--red-500));
    transform:scaleX(0);
    transform-origin:left;
    transition:transform .3s ease;
  }
  .card:hover{ transform:translateY(-4px); box-shadow:var(--shadow-hover); border-color:transparent; }
  .card:hover::after{ transform:scaleX(1); }
  .card:focus-visible{ outline:2px solid var(--red-500); outline-offset:3px; }

  .icon-wrap{
    position:relative;
    width:54px; height:54px;
    border-radius:14px;
    background:var(--red-100);
    display:flex; align-items:center; justify-content:center;
    color:var(--red-600);
    flex-shrink:0;
  }
  .icon-wrap svg{ width:28px; height:28px; }
  .badge{
    position:absolute;
    bottom:-4px; right:-4px;
    width:18px; height:18px;
    border-radius:50%;
    background:var(--green);
    border:2.5px solid #fff;
    display:flex; align-items:center; justify-content:center;
  }
  .badge.red{ background:var(--red-600); }
  .badge svg{ width:9px; height:9px; }

  .card h3{ font-size:16.5px; font-weight:600; color:var(--ink); letter-spacing:.1px; }
  .card p.meta{ font-size:13px; color:var(--ink-soft); margin-top:-10px; }
  .grid-primary .card{ min-height:150px; justify-content:center; }
  .grid-secondary .card{ min-height:150px; }
  .card.span-2{ grid-column:span 2; }
  .card.span-1{ grid-column:span 1; }

  /* ===== Announcements card bell ===== */
  .bell-badge{ background:var(--red-600); }
  .bell-badge svg{ width:10px; height:10px; }
  .bell-badge.pulse::before{
    content:"";
    position:absolute;
    inset:-4px;
    border-radius:50%;
    background:var(--red-600);
    opacity:.5;
    animation:bellPulse 1.8s ease-out infinite;
  }
  @keyframes bellPulse{
    0%{ transform:scale(.7); opacity:.55; }
    100%{ transform:scale(1.9); opacity:0; }
  }
  .announcement-preview{ display:flex; align-items:center; gap:8px; }
  .announcement-preview .new-pill{
    font-size:10.5px;
    font-weight:700;
    letter-spacing:.4px;
    color:#fff;
    background:var(--red-600);
    padding:2px 8px;
    border-radius:20px;
    flex-shrink:0;
  }

  /* ===== Security score ===== */
  .security-stats{ display:flex; gap:22px; width:100%; }
  .security-stats .stat{ display:flex; flex-direction:column; gap:2px; }
  .stat-num{ font-weight:700; font-size:22px; line-height:1; }
  .stat-num.risk-low{ color:var(--green); }
  .stat-num.risk-medium{ color:var(--amber); }
  .stat-num.risk-high{ color:var(--red-600); }
  .stat-lbl{ font-size:10.5px; letter-spacing:.4px; text-transform:uppercase; color:var(--ink-soft); }
  .card p.meta.small{ font-size:11.5px; color:var(--ink-soft); margin-top:-6px; }
  .security-icon{ color:var(--red-600); }
  .training-due{
    display:flex; align-items:flex-start; gap:8px;
    width:100%; margin-top:-2px;
    background:#FFF7E6; border:1px solid #F5D9A0; border-radius:10px;
    padding:9px 11px; font-size:12.5px; line-height:1.45; color:#7A5B00;
  }
  .training-due svg{ width:16px; height:16px; flex-shrink:0; margin-top:1px; }
  .training-due a{ font-weight:700; color:#7A5B00; text-decoration:underline; }

  /* ===== Payroll card ===== */
  /* ── IT Service Desk: raise + track in one card ──────────────────
     A plain <div>, not a card-wide link: it holds two separate actions
     (a modal trigger and a link), and nesting those inside an anchor is
     invalid and unusable by keyboard. */
  .svc-card{
    grid-column:span 2;
    background:var(--card); border:1px solid var(--line); border-radius:18px;
    box-shadow:var(--shadow); padding:22px 24px;
    display:flex; align-items:center; gap:22px; min-height:150px;
  }
  .svc-card:hover{ box-shadow:var(--shadow-hover); }
  .svc-count{
    display:flex; flex-direction:column; align-items:center; justify-content:center;
    text-decoration:none; color:inherit; flex:0 0 auto;
    min-width:104px; padding:12px 14px; border-radius:14px; background:var(--bg);
  }
  .svc-count:hover{ background:#EDEDEF; }
  .svc-count .n{ font-size:38px; font-weight:800; line-height:1; color:var(--ink); }
  .svc-count .n.has-open{ color:var(--red-600); }
  .svc-count .l{
    font-size:10px; font-weight:700; letter-spacing:.5px; text-transform:uppercase;
    color:var(--ink-soft); margin-top:6px; text-align:center;
  }
  .svc-body{ flex:1; min-width:0; }
  .svc-body h3{ font-size:16.5px; font-weight:600; color:var(--ink); }
  .svc-body p{ font-size:13px; color:var(--ink-soft); margin-top:5px; }
  .svc-actions{ display:flex; gap:10px; margin-top:14px; flex-wrap:wrap; }
  .svc-btn{
    display:inline-flex; align-items:center; gap:7px;
    font-size:13px; font-weight:600; font-family:inherit;
    border-radius:10px; padding:9px 15px; cursor:pointer;
    border:1px solid var(--line); background:#fff; color:var(--ink); text-decoration:none;
  }
  .svc-btn:hover{ border-color:var(--gray-500); }
  .svc-btn.primary{ background:var(--red-600); border-color:var(--red-600); color:#fff; }
  .svc-btn.primary:hover{ filter:brightness(1.07); }
  .svc-btn svg{ width:15px; height:15px; }
  @media (max-width:560px){
    .svc-card{ flex-direction:column; align-items:flex-start; }
    .svc-count{ flex-direction:row; gap:10px; min-width:0; }
    .svc-count .l{ margin-top:0; }
  }

  /* ── Security score: a gauge, not a footnote ─────────────────────
     KnowBe4 risk is 0–100 and HIGHER IS WORSE, so the arc fills as the
     score gets worse and the colour bands match Knowbe4Score::riskBand(). */
  .risk-card{
    grid-column:span 2;
    background:var(--card); border:1px solid var(--line); border-radius:18px;
    box-shadow:var(--shadow); padding:22px 24px; min-height:150px;
    display:flex; flex-direction:column; gap:14px;
  }
  .risk-head{ display:flex; align-items:center; gap:10px; }
  .risk-head h3{ font-size:16.5px; font-weight:600; color:var(--ink); }
  .risk-head svg{ width:20px; height:20px; color:var(--red-600); }
  .risk-head .src{ margin-left:auto; font-size:11px; color:var(--ink-soft); }
  .risk-main{ display:flex; align-items:center; gap:22px; flex-wrap:wrap; }
  .risk-gauge{ position:relative; width:104px; height:104px; flex:0 0 auto; }
  .risk-gauge svg{ transform:rotate(-90deg); }
  .risk-gauge .g-bg{ fill:none; stroke:var(--bg); stroke-width:9; }
  .risk-gauge .g-fg{ fill:none; stroke-width:9; stroke-linecap:round; transition:stroke-dashoffset .6s ease; }
  .risk-gauge .g-fg.risk-low{ stroke:var(--green); }
  .risk-gauge .g-fg.risk-medium{ stroke:var(--amber); }
  .risk-gauge .g-fg.risk-high{ stroke:var(--red-600); }
  .risk-gauge .g-center{
    position:absolute; inset:0; display:flex; flex-direction:column;
    align-items:center; justify-content:center; gap:1px;
  }
  .risk-gauge .g-center .v{ font-size:26px; font-weight:800; line-height:1; }
  .risk-gauge .g-center .v.risk-low{ color:var(--green); }
  .risk-gauge .g-center .v.risk-medium{ color:var(--amber); }
  .risk-gauge .g-center .v.risk-high{ color:var(--red-600); }
  .risk-gauge .g-center .u{ font-size:10px; color:var(--ink-soft); }
  .risk-side{ flex:1; min-width:170px; display:flex; flex-direction:column; gap:12px; }
  .risk-band{ font-size:14px; font-weight:700; }
  .risk-band.risk-low{ color:var(--green); }
  .risk-band.risk-medium{ color:var(--amber); }
  .risk-band.risk-high{ color:var(--red-600); }
  .risk-band .hint{ display:block; font-size:12px; font-weight:400; color:var(--ink-soft); margin-top:3px; }
  .risk-figs{ display:flex; gap:24px; }
  .risk-figs .stat{ display:flex; flex-direction:column; gap:2px; }

  .payroll-card{
    background:linear-gradient(150deg, var(--gray-800) 0%, var(--gray-700) 55%, var(--gray-600) 100%);
    border:none;
    color:#fff;
    flex-direction:row;
    align-items:center;
    justify-content:space-between;
    gap:18px;
  }
  .payroll-card::after{ display:none; }
  .payroll-card:hover{ box-shadow:0 10px 30px rgba(14,27,59,.28), 0 2px 8px rgba(14,27,59,.2); }
  .payroll-left h3{ color:#fff; }
  .payroll-left p.meta{ color:rgba(255,255,255,.55); margin-top:4px; }
  .payroll-left .sub{ font-size:12.5px; color:rgba(255,255,255,.5); margin-top:10px; }
  .ring-wrap{ position:relative; width:96px; height:96px; flex-shrink:0; }
  .ring-wrap svg{ transform:rotate(-90deg); }
  .ring-bg{ stroke:rgba(255,255,255,.14); fill:none; stroke-width:8; }
  .ring-fg{
    stroke:var(--red-500);
    fill:none;
    stroke-width:8;
    stroke-linecap:round;
    transition:stroke-dashoffset 1s cubic-bezier(.4,0,.2,1);
  }
  .ring-center{ position:absolute; inset:0; display:flex; flex-direction:column; align-items:center; justify-content:center; }
  .ring-center .num{ font-weight:700; font-size:24px; line-height:1; }
  .ring-center .lbl{ font-size:9.5px; letter-spacing:.6px; text-transform:uppercase; color:rgba(255,255,255,.55); margin-top:3px; }

  /* ===== Events list on the calendar card ===== */
  .event-list{ list-style:none; width:100%; display:flex; flex-direction:column; gap:7px; margin-top:-4px; }
  .event-list li{ font-size:12.5px; color:var(--ink-soft); display:flex; gap:8px; align-items:baseline; }
  .event-list li .ev-when{ color:var(--red-600); font-weight:600; flex-shrink:0; }
  .event-list li .ev-what{ overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }

  /* ===== Portal layout (main + ID card sidebar) ===== */
  .portal-layout{ display:flex; align-items:flex-start; gap:24px; }
  .portal-main{ flex:1; min-width:0; }

  /* ===== Employee ID card ===== */
  .id-card-aside{ width:300px; flex-shrink:0; position:sticky; top:24px; padding-top:32px; }
  .id-card{
    background:linear-gradient(160deg, var(--gray-900) 0%, var(--gray-800) 55%, var(--gray-700) 100%);
    border-radius:22px;
    padding:26px 24px 24px;
    color:#fff;
    box-shadow:var(--shadow-hover);
    position:relative;
    overflow:hidden;
  }
  .id-card::before{
    content:"";
    position:absolute; inset:0;
    background:radial-gradient(320px 180px at 100% 0%, rgba(236,32,36,.16), transparent 60%);
    pointer-events:none;
  }
  .id-card-top{ position:relative; display:flex; align-items:center; justify-content:space-between; margin-bottom:26px; }
  .id-card-logo{ height:26px; width:auto; filter:brightness(0) invert(1); }
  .eye-toggle{
    position:relative;
    width:34px; height:34px;
    border-radius:50%;
    background:rgba(255,255,255,.08);
    border:1px solid rgba(255,255,255,.16);
    color:rgba(255,255,255,.75);
    display:flex; align-items:center; justify-content:center;
    cursor:pointer;
    transition:background .2s ease, color .2s ease, border-color .2s ease;
  }
  .eye-toggle:hover{ background:rgba(255,255,255,.16); color:#fff; }
  .eye-toggle.revealed{ background:var(--red-600); border-color:var(--red-600); color:#fff; }
  .eye-toggle svg{ width:18px; height:18px; }
  .eye-toggle .eye-closed{ display:none; }
  .eye-toggle.revealed .eye-open{ display:none; }
  .eye-toggle.revealed .eye-closed{ display:block; }

  .id-card-label{
    font-size:10.5px;
    font-weight:600;
    letter-spacing:1.2px;
    text-transform:uppercase;
    color:rgba(255,255,255,.45);
    margin-bottom:5px;
  }
  .id-card-name{ font-weight:700; font-size:26px; line-height:1.15; margin-bottom:22px; }
  .id-card-field{ margin-bottom:18px; }
  .id-card-field:last-of-type{ margin-bottom:0; }
  .id-card-value{ font-size:15px; line-height:1.4; color:rgba(255,255,255,.92); word-break:break-word; }
  .id-blur{ filter:blur(7px); transition:filter .3s ease; user-select:none; }
  .id-blur.revealed{ filter:blur(0); }
  .id-card-hint{ margin-top:24px; font-size:11.5px; color:rgba(255,255,255,.42); text-align:center; }

  /* ===== Buttons ===== */
  .btn{
    font-family:var(--font-sans);
    font-size:13.5px;
    font-weight:600;
    padding:9px 18px;
    border-radius:10px;
    border:none;
    cursor:pointer;
    transition:opacity .15s ease, background .15s ease;
  }
  .btn-primary{ background:var(--red-600); color:#fff; }
  .btn-primary:hover{ background:var(--red-700); }
  .btn-primary:disabled{ opacity:.5; cursor:not-allowed; }
  .btn-ghost{ background:var(--gray-700); color:#fff; }
  .btn-ghost:hover{ opacity:.88; }

  /* ===== Ticket modal ===== */
  .modal-overlay{
    position:fixed; inset:0;
    background:rgba(43,44,45,.55);
    backdrop-filter:blur(2px);
    display:flex;
    align-items:flex-start;
    justify-content:center;
    padding:40px 20px;
    opacity:0;
    pointer-events:none;
    transition:opacity .2s ease;
    z-index:1000;
    overflow-y:auto;
  }
  .modal-overlay.open{ opacity:1; pointer-events:auto; }
  .ticket-modal{
    background:#fff;
    width:100%;
    max-width:760px;
    border-radius:18px;
    box-shadow:0 30px 70px rgba(0,0,0,.28);
    overflow:hidden;
    transform:translateY(14px);
    transition:transform .22s ease;
  }
  .modal-overlay.open .ticket-modal{ transform:translateY(0); }
  .ticket-modal-header{
    display:flex; align-items:center; justify-content:space-between;
    gap:16px; padding:16px 22px; border-bottom:1px solid var(--line); flex-wrap:wrap;
  }
  .ticket-modal-header-left{ display:flex; align-items:center; gap:12px; }
  .ticket-modal-header-left h2{
    font-size:14.5px; font-weight:700; letter-spacing:.6px; text-transform:uppercase; color:var(--ink);
  }
  .icon-btn{
    width:30px; height:30px; border-radius:8px;
    border:1px solid var(--line); background:#fff; color:var(--ink-soft);
    display:flex; align-items:center; justify-content:center; cursor:pointer;
    transition:background .15s ease, color .15s ease;
  }
  .icon-btn:hover{ background:var(--bg); color:var(--ink); }
  .icon-btn svg{ width:16px; height:16px; }
  .ticket-modal-header-right{ display:flex; gap:10px; }
  .ticket-modal-body{ padding:22px; max-height:72vh; overflow-y:auto; }
  .field{ margin-bottom:18px; }
  .field label{ display:block; font-size:13px; font-weight:600; color:var(--ink); margin-bottom:7px; }
  .field select,
  .field input[type="text"],
  .field textarea{
    width:100%;
    font-family:var(--font-sans);
    font-size:14px;
    color:var(--ink);
    background:#fff;
    border:1px solid var(--line);
    border-radius:10px;
    padding:11px 13px;
    transition:border-color .15s ease, box-shadow .15s ease;
  }
  .field select:focus,
  .field input[type="text"]:focus,
  .field textarea:focus{
    outline:none; border-color:var(--red-600); box-shadow:0 0 0 3px var(--red-100);
  }
  .field select:disabled{ background:var(--bg); color:var(--gray-500); cursor:not-allowed; }
  .field textarea{ resize:vertical; min-height:100px; font-family:var(--font-sans); }
  .field-error{ font-size:12.5px; color:var(--red-600); margin:-8px 0 16px; min-height:1px; }

  .attachments-panel{ background:var(--bg); border-radius:14px; padding:20px; }
  .attachments-panel h3{ font-size:15px; font-weight:700; color:var(--ink); margin-bottom:14px; }
  .upload-zone{ border:1.5px dashed var(--gray-500); border-radius:12px; background:#fff; padding:26px 18px; text-align:center; }
  .upload-title{ font-weight:700; font-size:14px; color:var(--ink); margin-bottom:4px; }
  .upload-sub{ font-size:12.5px; color:var(--ink-soft); margin-bottom:2px; }
  .upload-control{ display:flex; align-items:center; justify-content:center; gap:10px; margin-top:14px; flex-wrap:wrap; }
  .choose-file-btn{
    font-family:var(--font-sans); font-size:13px; font-weight:600;
    padding:8px 16px; border-radius:8px; border:1px solid var(--gray-500);
    background:#fff; color:var(--ink); cursor:pointer; transition:background .15s ease;
  }
  .choose-file-btn:hover{ background:var(--bg); }
  #chooseFileLabel{ font-size:12.5px; color:var(--ink-soft); }
  .file-list{ list-style:none; margin-top:14px; display:flex; flex-direction:column; gap:8px; }
  .file-list li{
    display:flex; align-items:center; justify-content:space-between; gap:10px;
    background:#fff; border:1px solid var(--line); border-radius:8px;
    padding:8px 12px; font-size:12.5px; color:var(--ink);
  }
  .file-list li .file-size{ color:var(--ink-soft); flex-shrink:0; margin-left:auto; margin-right:10px; }
  .file-remove{ border:none; background:none; color:var(--red-600); cursor:pointer; font-size:16px; line-height:1; padding:0 2px; flex-shrink:0; }

  .ticket-success{ text-align:center; padding:52px 22px; }
  .success-icon{
    width:60px; height:60px; margin:0 auto 18px; border-radius:50%;
    background:#E9F8EF; color:var(--green);
    display:flex; align-items:center; justify-content:center;
  }
  .success-icon svg{ width:30px; height:30px; }
  .ticket-success h3{ font-size:18px; font-weight:700; color:var(--ink); margin-bottom:8px; }
  .ticket-success p{ font-size:13.5px; color:var(--ink-soft); margin-bottom:22px; }

  /* ===== Add to Wallet ===== */
  .wallet-section{ margin-top:22px; position:relative; }
  .wallet-btn{
    width:100%;
    border:1px solid rgba(255,255,255,.22);
    border-radius:12px;
    padding:12px 16px;
    background:var(--red-600);
    color:#fff;
    font-family:var(--font-sans);
    font-size:13.5px;
    font-weight:700;
    letter-spacing:.2px;
    display:flex; align-items:center; justify-content:center; gap:9px;
    cursor:pointer;
    box-shadow:0 8px 20px rgba(236,32,36,.24);
    transition:background .2s ease, transform .2s ease, box-shadow .2s ease;
  }
  .wallet-btn:hover{ background:var(--red-700); transform:translateY(-1px); box-shadow:0 10px 24px rgba(236,32,36,.32); }
  .wallet-btn + .wallet-btn{ margin-top:10px; }
  .wallet-btn-secondary{
    background:rgba(255,255,255,.10);
    border-color:rgba(255,255,255,.24);
    box-shadow:none;
  }
  .wallet-btn-secondary:hover{
    background:rgba(255,255,255,.20);
    box-shadow:none;
  }
  .wallet-btn:focus-visible{ outline:2px solid #fff; outline-offset:3px; }
  .wallet-btn svg{ width:19px; height:19px; flex-shrink:0; }
  .wallet-modal-overlay{
    position:fixed; inset:0; z-index:3500; padding:24px;
    display:flex; align-items:center; justify-content:center;
    background:rgba(20,20,21,.68);
    backdrop-filter:blur(4px);
    opacity:0; pointer-events:none; transition:opacity .2s ease;
  }
  .wallet-modal-overlay.open{ opacity:1; pointer-events:auto; }
  .wallet-modal{
    width:100%; max-width:390px; background:#fff; border-radius:20px;
    padding:26px; text-align:center; box-shadow:0 28px 70px rgba(0,0,0,.30);
    transform:translateY(12px) scale(.98); transition:transform .2s ease;
  }
  .wallet-modal-overlay.open .wallet-modal{ transform:translateY(0) scale(1); }
  .wallet-modal-icon{
    width:48px; height:48px; margin:0 auto 12px; border-radius:14px;
    display:flex; align-items:center; justify-content:center;
    background:var(--red-100); color:var(--red-600);
  }
  .wallet-modal-icon svg{ width:25px; height:25px; }
  .wallet-modal h2{ font-size:19px; font-weight:700; color:var(--ink); margin-bottom:7px; }
  .wallet-modal-copy{ font-size:13px; line-height:1.55; color:var(--ink-soft); margin-bottom:18px; }
  .wallet-qr-wrap{
    width:258px; max-width:100%; margin:0 auto 16px; padding:12px;
    border:1px solid var(--line); border-radius:16px; background:#fff;
  }
  .wallet-qr-wrap img{ width:100%; height:auto; aspect-ratio:1; display:block; border-radius:8px; }
  .wallet-employee{ font-size:13px; font-weight:700; color:var(--ink); margin-bottom:3px; }
  .wallet-instruction{ font-size:11.5px; color:var(--ink-soft); margin-bottom:18px; }
  .wallet-card-url{
    font-size:11.5px;
    color:var(--ink-soft);
    margin-bottom:18px;
    word-break:break-all;
    font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;
  }
  .wallet-close-btn{
    width:100%; border:0; border-radius:10px; padding:11px 16px;
    background:var(--gray-700); color:#fff;
    font-family:var(--font-sans); font-size:13px; font-weight:600; cursor:pointer;
  }
  .wallet-close-btn:hover{ background:var(--gray-800); }

  /* ===== Footer ===== */
  footer{
    background:var(--gray-900);
    color:rgba(255,255,255,.5);
    text-align:center;
    padding:18px 20px;
    font-size:12.5px;
  }
  footer span.ar{ font-family:var(--font-ar); }

  /* ===== Responsive ===== */
  @media (max-width:1180px){
    .grid-primary{ grid-template-columns:repeat(2,1fr); }
    .grid-secondary{ grid-template-columns:repeat(2,1fr); }
    .card.span-2{ grid-column:span 2; }
    .svc-card, .risk-card{ grid-column:span 2; }
    .portal-layout{ flex-direction:column; }
    .id-card-aside{ width:100%; max-width:380px; position:static; padding-top:0; }
  }
  @media (max-width:640px){
    .header-inner{ flex-direction:column; align-items:flex-start; gap:16px; }
    .header-right{ align-self:flex-start; }
    .header-clock{ text-align:left; }
    .grid-primary{ grid-template-columns:1fr; }
    .grid-secondary{ grid-template-columns:1fr; }
    .card.span-2{ grid-column:span 1; }
    .svc-card, .risk-card{ grid-column:span 1; }
    .id-card-aside{ max-width:none; }
    .payroll-card{ flex-direction:column; align-items:flex-start; }
    .ann-slide{ padding-right:clamp(22px,3vw,30px); padding-bottom:40px; }
    .ann-nav{ top:auto; bottom:12px; transform:none; }
  }

  @media (prefers-reduced-motion:reduce){
    .card, .ring-fg, .ann-slide{ transition:none; }
    .bell-badge.pulse::before{ animation:none; }
  }
</style>
@stack('head')
</head>
<body>

<header>
  <div class="header-inner">
    <div class="brand">
      <img src="{{ asset('images/brand/samir-logo.png') }}" alt="Samir Group">
      <div class="brand-divider"></div>
      <div class="brand-title">
        <h1>EMPLOYEE PORTAL</h1>
        <p>بوابة الموظفين — Samir Group</p>
      </div>
    </div>
    <div class="header-right">
      <div class="header-clock">
        <svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><circle cx="12" cy="12" r="9.25"/><path d="M12 7v5.2l3.4 2" stroke-linecap="round" stroke-linejoin="round"/></svg>
        <div>
          <div class="time" id="clockTime">--:--</div>
          <div class="date" id="clockDate">&nbsp;</div>
        </div>
      </div>
      @auth
        <form method="POST" action="{{ route('home.logout') }}">
          @csrf
          <button type="submit" class="header-signout">Sign out</button>
        </form>
      @endauth
    </div>
  </div>
</header>

<main>
  @yield('content')
</main>

<footer>
  Samir Group &copy; <span id="yearNow">{{ now()->year }}</span> — Internal use only &nbsp;·&nbsp; <span class="ar">للاستخدام الداخلي فقط</span>
</footer>

<script>
  // Live clock. Rendered server-side as a placeholder first so the header does
  // not jump; the browser's own locale formats it from here on.
  (function () {
    var timeEl = document.getElementById('clockTime');
    var dateEl = document.getElementById('clockDate');
    if (!timeEl || !dateEl) return;

    function tick() {
      var now = new Date();
      timeEl.textContent = now.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
      dateEl.textContent = now.toLocaleDateString([], {
        weekday: 'long', day: 'numeric', month: 'long', year: 'numeric'
      });
    }
    tick();
    setInterval(tick, 15000);
  })();
</script>
@stack('scripts')
</body>
</html>
