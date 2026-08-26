@extends('layouts.admin')

@section('content')
{{--
    Branch Health Index.

    A dark, self-contained board over HealthScoringService. Everything is scoped
    under .bhx so none of it leaks into the rest of the light admin chrome, and
    the whole dataset ships as one JSON blob -- filtering, sorting and the detail
    drawer are client-side, so this can sit on a wallboard without polling.

    It computes nothing. Every number here comes from the scorer.
--}}
@push('head')
<style>
.bhx{--bhx-bg:#0b0d10;--bhx-panel:#12151a;--bhx-panel-2:#171b21;--bhx-line:#232932;
     --bhx-ink:#e8edf4;--bhx-dim:#8b96a5;--bhx-faint:#5a6472;
     --bhx-voip:#3b82f6;--bhx-network:#c2410c;--bhx-devices:#10b981;
     --bhx-healthy:#22c55e;--bhx-degraded:#a3a635;--bhx-at_risk:#f59e0b;--bhx-critical:#ef4444;--bhx-unknown:#64748b;
     background:var(--bhx-bg);color:var(--bhx-ink);border-radius:14px;padding:22px 24px 34px;
     font-feature-settings:"tnum";margin:-8px -4px 0}
.bhx *{box-sizing:border-box}
.bhx-mono{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace}
.bhx-eyebrow{font-size:11px;letter-spacing:.14em;text-transform:uppercase;color:var(--bhx-faint)}
.bhx-head{display:flex;align-items:baseline;gap:14px;flex-wrap:wrap;margin-bottom:18px}
.bhx-head h1{font-size:19px;font-weight:700;letter-spacing:.02em;margin:0}
.bhx-head .sep{color:var(--bhx-faint)}
.bhx-card{background:var(--bhx-panel);border:1px solid var(--bhx-line);border-radius:12px}

/* ── Fleet summary ─────────────────────────────────────────── */
.bhx-fleet{display:grid;grid-template-columns:auto minmax(210px,1fr) minmax(240px,1.1fr);
           gap:26px;align-items:center;padding:20px 24px;margin-bottom:20px}
@media(max-width:900px){.bhx-fleet{grid-template-columns:1fr}}
.bhx-fleet-score{position:relative;width:150px;height:150px;flex:none}
.bhx-fleet-score .val{position:absolute;inset:0;display:flex;flex-direction:column;
                      align-items:center;justify-content:center;line-height:1}
.bhx-fleet-score .val b{font-size:34px;font-weight:700}
.bhx-fleet-score .val span{font-size:11px;color:var(--bhx-faint);margin-top:4px}
.bhx-catline{display:flex;align-items:center;gap:9px;padding:3px 0;font-size:13px}
.bhx-dot{width:9px;height:9px;border-radius:2px;flex:none}
.bhx-catline .nm{flex:1}
.bhx-catline .pts{font-variant-numeric:tabular-nums}
.bhx-catline .mx{color:var(--bhx-faint)}
.bhx-note{font-size:12px;color:var(--bhx-dim);margin-top:12px;line-height:1.55}
.bhx-bandbar{display:flex;height:26px;border-radius:5px;overflow:hidden;margin-bottom:12px}
.bhx-bandbar div{display:flex;align-items:center;justify-content:center;font-size:12px;
                 font-weight:700;color:#0b0d10}
.bhx-bandkeys{display:grid;grid-template-columns:1fr 1fr;gap:5px 18px;font-size:12px}
.bhx-bandkey{display:flex;align-items:center;gap:7px;color:var(--bhx-dim)}
.bhx-bandkey b{color:var(--bhx-ink)}

/* ── Tabs & toolbar ────────────────────────────────────────── */
.bhx-tabs{display:flex;gap:26px;border-bottom:1px solid var(--bhx-line);margin-bottom:18px}
.bhx-tab{background:none;border:0;padding:9px 2px 12px;color:var(--bhx-dim);font-size:14px;
         cursor:pointer;border-bottom:2px solid transparent;margin-bottom:-1px}
.bhx-tab[aria-selected=true]{color:var(--bhx-ink);border-bottom-color:var(--bhx-voip)}
.bhx-toolbar{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:18px}
.bhx-input,.bhx-select{background:var(--bhx-panel);border:1px solid var(--bhx-line);color:var(--bhx-ink);
                       border-radius:7px;padding:8px 11px;font-size:13px}
.bhx-input{min-width:210px}
.bhx-input::placeholder{color:var(--bhx-faint)}
.bhx-chips{display:flex;gap:7px;margin-left:auto;flex-wrap:wrap}
.bhx-chip{background:var(--bhx-panel);border:1px solid var(--bhx-line);border-radius:999px;
          padding:5px 13px;font-size:12px;color:var(--bhx-dim);cursor:pointer;display:flex;
          align-items:center;gap:7px}
.bhx-chip[aria-pressed=true]{color:var(--bhx-ink);border-color:var(--bhx-faint);background:var(--bhx-panel-2)}

/* ── Scoreboard ────────────────────────────────────────────── */
.bhx-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(268px,1fr));gap:16px}
.bhx-branch{padding:14px 16px 16px;text-align:left;width:100%;cursor:pointer;
            background:var(--bhx-panel);border:1px solid var(--bhx-line);border-radius:12px;
            color:inherit;transition:border-color .15s,transform .15s}
.bhx-branch:hover{border-color:var(--bhx-faint);transform:translateY(-2px)}
.bhx-branch-top{display:flex;justify-content:space-between;align-items:center;
                font-size:11px;color:var(--bhx-faint);margin-bottom:6px}
.bhx-badge{display:inline-flex;align-items:center;gap:5px;font-weight:600}
.bhx-ring-wrap{position:relative;width:132px;height:132px;margin:6px auto 10px}
.bhx-ring-wrap .val{position:absolute;inset:0;display:flex;flex-direction:column;
                    align-items:center;justify-content:center;line-height:1}
.bhx-ring-wrap .val b{font-size:27px;font-weight:700}
.bhx-ring-wrap .val span{font-size:10px;color:var(--bhx-faint);margin-top:3px}
.bhx-branch-name{text-align:center;font-weight:700;font-size:15px}
.bhx-branch-sub{text-align:center;font-size:11px;color:var(--bhx-faint);
                letter-spacing:.06em;text-transform:uppercase;margin-top:2px;margin-bottom:11px}
.bhx-branch-cats{border-top:1px solid var(--bhx-line);padding-top:9px}
.bhx-empty{padding:44px;text-align:center;color:var(--bhx-dim)}

/* ── Check matrix ──────────────────────────────────────────── */
.bhx-matrix-scroll{overflow-x:auto}
.bhx-matrix{border-collapse:separate;border-spacing:0;width:100%;font-size:12px}
.bhx-matrix th,.bhx-matrix td{padding:7px 8px;border-bottom:1px solid var(--bhx-line);white-space:nowrap}
.bhx-matrix thead th{color:var(--bhx-faint);font-weight:600;text-align:center;
                     position:sticky;top:0;background:var(--bhx-bg)}
.bhx-matrix thead th:first-child,.bhx-matrix tbody th{text-align:left;position:sticky;left:0;
                     background:var(--bhx-bg);z-index:1}
.bhx-matrix tbody th{font-weight:600}
.bhx-cell{display:inline-flex;width:32px;height:22px;align-items:center;justify-content:center;
          border-radius:4px;font-weight:700;font-size:11px;color:#0b0d10}
.bhx-matrix .grp{color:var(--bhx-faint);font-size:10px;letter-spacing:.1em;text-transform:uppercase}

/* ── Scoring model ─────────────────────────────────────────── */
.bhx-model{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:16px}
.bhx-model section{padding:16px 18px}
.bhx-model h3{font-size:14px;margin:0 0 2px;display:flex;justify-content:space-between;align-items:baseline}
.bhx-model .blurb{font-size:11px;color:var(--bhx-faint);text-transform:uppercase;
                  letter-spacing:.08em;margin-bottom:11px}
.bhx-model li{display:flex;gap:10px;align-items:baseline;padding:4px 0;font-size:13px;
              border-top:1px solid var(--bhx-line)}
.bhx-model ul{list-style:none;padding:0;margin:0}
.bhx-model .code{color:var(--bhx-faint);font-size:11px;width:22px;flex:none}
.bhx-model .w{margin-left:auto;font-weight:700}
.bhx-rules{margin-top:16px;padding:16px 18px;font-size:13px;line-height:1.65;color:var(--bhx-dim)}
.bhx-rules b{color:var(--bhx-ink)}

/* ── Drawer ────────────────────────────────────────────────── */
.bhx-scrim{position:fixed;inset:0;background:rgba(4,6,9,.62);opacity:0;pointer-events:none;
           transition:opacity .18s;z-index:1080}
.bhx-scrim.open{opacity:1;pointer-events:auto}
.bhx-drawer{position:fixed;top:0;right:0;height:100vh;width:min(560px,94vw);z-index:1090;
            background:var(--bhx-bg);border-left:1px solid var(--bhx-line);
            transform:translateX(100%);transition:transform .22s ease;
            display:flex;flex-direction:column;color:var(--bhx-ink)}
.bhx-drawer.open{transform:none}
.bhx-drawer-head{display:flex;gap:16px;align-items:flex-start;padding:20px 22px;
                 border-bottom:1px solid var(--bhx-line)}
.bhx-drawer-head h2{font-size:19px;margin:0 0 3px;font-weight:700}
.bhx-drawer-body{overflow-y:auto;padding:16px 22px 40px;flex:1}
.bhx-close{margin-left:auto;background:none;border:1px solid var(--bhx-line);color:var(--bhx-dim);
           border-radius:7px;width:30px;height:30px;cursor:pointer;flex:none}
.bhx-close:hover{color:var(--bhx-ink)}
.bhx-cat{margin-bottom:14px;overflow:hidden}
.bhx-cat-head{display:flex;align-items:center;gap:12px;padding:13px 16px;background:var(--bhx-panel-2)}
.bhx-cat-head .nm{flex:1}
.bhx-cat-head .nm b{display:block;font-size:14px}
.bhx-cat-head .nm span{font-size:10px;color:var(--bhx-faint);text-transform:uppercase;letter-spacing:.08em}
.bhx-cat-head .tot{font-size:19px;font-weight:700}
.bhx-cat-head .tot span{font-size:11px;color:var(--bhx-faint);font-weight:400}
.bhx-check{display:flex;gap:12px;align-items:center;padding:11px 16px;border-top:1px solid var(--bhx-line)}
.bhx-check .code{font-size:10px;color:var(--bhx-faint);width:20px;flex:none}
.bhx-check .body{flex:1;min-width:0}
.bhx-check .body b{display:block;font-size:13px;font-weight:600}
.bhx-check .body span{font-size:11px;color:var(--bhx-dim)}
.bhx-check .pts{text-align:right;font-size:13px;font-weight:700;flex:none}
.bhx-check .pts span{display:block;font-size:10px;color:var(--bhx-faint);font-weight:400}
.bhx-flag{font-size:10px;font-weight:700;letter-spacing:.05em;margin-right:5px}
.bhx-fails{margin:5px 0 0;padding-left:15px;font-size:11px;color:var(--bhx-dim);line-height:1.55}
.bhx-cap{margin:0 0 14px;padding:11px 14px;border-radius:9px;font-size:12px;line-height:1.55;
         background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.32);color:#fca5a5}
.bhx-link{color:var(--bhx-voip);text-decoration:none;font-size:11px}
.bhx-link:hover{text-decoration:underline}
</style>
@endpush

<div class="bhx" id="bhx">
    <div class="bhx-head">
        <h1>SG NOC</h1>
        <span class="sep">/</span>
        <span class="bhx-eyebrow">Branch Health Index</span>
        <span class="bhx-eyebrow" style="margin-left:auto">
            Scored {{ $generatedAt->timezone(config('app.display_timezone', 'Africa/Cairo'))->format('H:i') }}
            &middot; recomputed at most every {{ \App\Services\BranchHealth\BranchHealthConfig::get('cache_ttl_seconds') }}s
        </span>
    </div>

    {{-- ── Fleet summary ───────────────────────────────────────── --}}
    <div class="bhx-card bhx-fleet">
        <div class="bhx-fleet-score" id="bhxFleetRing">
            <div class="val">
                <b>{{ $fleet['score'] }}</b>
                <span>/ 100</span>
            </div>
        </div>

        <div>
            <div class="bhx-eyebrow" style="margin-bottom:9px">
                Fleet score &middot; {{ $fleet['branch_count'] }} {{ Str::plural('branch', $fleet['branch_count']) }}
            </div>
            @foreach($fleet['categories'] as $cat)
                <div class="bhx-catline">
                    <span class="bhx-dot" style="background:var(--bhx-{{ $cat['key'] }})"></span>
                    <span class="nm">{{ $cat['label'] }}</span>
                    <span class="pts bhx-mono">{{ number_format($cat['points'], 1) }}</span>
                    <span class="mx bhx-mono">/ {{ $cat['max_points'] }}</span>
                </div>
            @endforeach
            <div class="bhx-note">
                Mean of {{ $fleet['scored_count'] }} branch {{ Str::plural('ring', $fleet['scored_count']) }} &middot;
                <b>{{ $fleet['lost'] }}</b> points lost fleet-wide
                @if($fleet['weakest']) &middot; weakest site <b>{{ $fleet['weakest'] }}</b> @endif
            </div>
        </div>

        <div>
            <div class="bhx-eyebrow" style="margin-bottom:9px">Band distribution</div>
            @php $withCount = collect($fleet['bands'])->where('count', '>', 0); @endphp
            @if($withCount->isNotEmpty())
                <div class="bhx-bandbar">
                    @foreach($withCount as $band)
                        <div style="flex:{{ $band['count'] }};background:var(--bhx-{{ $band['key'] }})"
                             title="{{ $band['count'] }} {{ $band['label'] }}">{{ $band['count'] }}</div>
                    @endforeach
                </div>
            @endif
            <div class="bhx-bandkeys">
                @foreach($fleet['bands'] as $band)
                    <span class="bhx-bandkey">
                        <span class="bhx-dot" style="background:var(--bhx-{{ $band['key'] }})"></span>
                        <b>{{ $band['count'] }}</b> {{ $band['label'] }}
                        <span style="color:var(--bhx-faint)">{{ $band['hint'] }}</span>
                    </span>
                @endforeach
            </div>
        </div>
    </div>

    {{-- ── Tabs ────────────────────────────────────────────────── --}}
    <div class="bhx-tabs" role="tablist">
        <button class="bhx-tab" role="tab" aria-selected="true"  data-panel="scoreboard">Scoreboard</button>
        <button class="bhx-tab" role="tab" aria-selected="false" data-panel="matrix">Check matrix</button>
        <button class="bhx-tab" role="tab" aria-selected="false" data-panel="model">Scoring model</button>
    </div>

    {{-- ── Scoreboard ──────────────────────────────────────────── --}}
    <div data-tabpanel="scoreboard">
        <div class="bhx-toolbar">
            <input class="bhx-input" id="bhxSearch" type="search" placeholder="Find a branch…" autocomplete="off">
            <select class="bhx-select" id="bhxSort">
                <option value="worst">Worst score first</option>
                <option value="best">Best score first</option>
                <option value="name">Branch name</option>
                <option value="coverage">Least monitored first</option>
            </select>
            <div class="bhx-chips" id="bhxChips">
                @foreach($fleet['bands'] as $band)
                    @if($band['count'] > 0)
                        <button class="bhx-chip" aria-pressed="false" data-band="{{ $band['key'] }}">
                            <span class="bhx-dot" style="background:var(--bhx-{{ $band['key'] }})"></span>
                            {{ $band['label'] }}
                        </button>
                    @endif
                @endforeach
            </div>
        </div>
        <div class="bhx-grid" id="bhxGrid"></div>
        <div class="bhx-empty" id="bhxEmpty" hidden>No branch matches those filters.</div>
    </div>

    {{-- ── Check matrix ────────────────────────────────────────── --}}
    <div data-tabpanel="matrix" hidden>
        <div class="bhx-card bhx-matrix-scroll" style="padding:4px 10px 2px">
            <table class="bhx-matrix">
                <thead>
                    <tr>
                        <th rowspan="2">Branch</th>
                        @foreach($model['categories'] as $cat)
                            <th class="grp" colspan="{{ count($cat['checks']) }}">{{ $cat['label'] }}</th>
                        @endforeach
                        <th rowspan="2">Score</th>
                    </tr>
                    <tr>
                        @foreach($model['categories'] as $cat)
                            @foreach($cat['checks'] as $check)
                                <th title="{{ $check['key'] }} · {{ $check['points'] }} pts">{{ $check['code'] }}</th>
                            @endforeach
                        @endforeach
                    </tr>
                </thead>
                <tbody id="bhxMatrixBody"></tbody>
            </table>
        </div>
        <div class="bhx-note" style="padding:0 4px">
            Each cell is the share of that check&rsquo;s points earned. Grey means unknown &mdash;
            no data, or data too old to trust &mdash; which is never counted as a pass.
        </div>
    </div>

    {{-- ── Scoring model ───────────────────────────────────────── --}}
    <div data-tabpanel="model" hidden>
        <div class="bhx-model">
            @foreach($model['categories'] as $cat)
                <section class="bhx-card">
                    <h3>
                        <span>{{ $cat['label'] }}</span>
                        <span class="bhx-mono">{{ $cat['max_points'] }} pts</span>
                    </h3>
                    <div class="blurb">{{ $cat['blurb'] }}</div>
                    <ul>
                        @foreach($cat['checks'] as $check)
                            <li>
                                <span class="code bhx-mono">{{ $check['code'] }}</span>
                                <span>{{ Str::headline($check['key']) }}</span>
                                <span class="w bhx-mono">{{ $check['points'] }}</span>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endforeach
        </div>

        <div class="bhx-card bhx-rules">
            <b>How a branch is scored.</b>
            Each check earns its points in proportion to how much of it passes &mdash;
            eight of ten switches up earns eight tenths of 8 points. Configured devices that
            nothing is monitoring stay in the denominator: they earn nothing and reduce coverage,
            so &ldquo;broken&rdquo; and &ldquo;never watched&rdquo; never look alike.
            <br><br>
            <b>Missing or stale data is never a pass.</b>
            Every source has a freshness window at least twice its collector&rsquo;s cadence
            &mdash; {{ $model['freshness']['monitored_host'] }} min for pings,
            {{ $model['freshness']['access_point'] }} min for access points,
            {{ $model['freshness']['printer_supply'] }} min for toner. Past that the check reads
            unknown, not healthy. A branch with fewer than {{ $model['min_coverage'] }} measurable
            points is not given a band at all.
            <br><br>
            <b>Two conditions cap the score outright.</b>
            Every branch firewall unreachable caps it at {{ $model['caps']['all_firewalls_down'] }};
            an open or acknowledged critical firewall alert caps it at
            {{ $model['caps']['critical_firewall_alert'] }}. Caps are always shown with the reason
            &mdash; the raw score stays visible underneath.
        </div>
    </div>
</div>

{{-- ── Detail drawer ───────────────────────────────────────────── --}}
<div class="bhx-scrim" id="bhxScrim"></div>
<aside class="bhx-drawer bhx" id="bhxDrawer" role="dialog" aria-modal="true" aria-labelledby="bhxDrawerName"
       style="border-radius:0;margin:0;padding:0">
    <div class="bhx-drawer-head">
        <div style="position:relative;width:70px;height:70px;flex:none" id="bhxDrawerRing"></div>
        <div style="min-width:0">
            <h2 id="bhxDrawerName"></h2>
            <div class="bhx-eyebrow" id="bhxDrawerSub"></div>
            <div style="font-size:12px;margin-top:7px" id="bhxDrawerBand"></div>
            <div style="font-size:12px;color:var(--bhx-dim);margin-top:3px" id="bhxDrawerLost"></div>
        </div>
        <button class="bhx-close" id="bhxClose" aria-label="Close">&times;</button>
    </div>
    <div class="bhx-drawer-body" id="bhxDrawerBody"></div>
</aside>
@endsection

@push('scripts')
<script>
(function () {
    const BRANCHES = @json($branches);
    const FLEET_CATEGORIES = @json($fleet['categories']);
    const BANDS = {
        healthy:  {label: 'Healthy',  color: 'var(--bhx-healthy)'},
        degraded: {label: 'Degraded', color: 'var(--bhx-degraded)'},
        at_risk:  {label: 'At risk',  color: 'var(--bhx-at_risk)'},
        critical: {label: 'Critical', color: 'var(--bhx-critical)'},
        unknown:  {label: 'Unknown',  color: 'var(--bhx-unknown)'},
    };
    const CHECK_TONE = {pass: 'healthy', degraded: 'at_risk', fail: 'critical', unknown: 'unknown'};

    // Branch names and device labels are operator-supplied and land in innerHTML.
    const esc = v => v === null || v === undefined ? '' : String(v)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#39;');

    /**
     * A ring whose three arcs are sized by each category's WEIGHT, not by its
     * score — so the geometry itself shows that Network is worth more than
     * Devices, and a full ring can only mean a full 100.
     */
    function ring(categories, size, stroke) {
        const r = (size - stroke) / 2, c = 2 * Math.PI * r, gap = 2.5;
        let offset = 0, arcs = '';

        categories.forEach(cat => {
            const span = c * (cat.max_points / 100);
            const filled = span * (cat.max_points ? cat.points / cat.max_points : 0);
            const track = Math.max(span - gap, 0);
            const common = `cx="${size / 2}" cy="${size / 2}" r="${r}" fill="none" stroke-width="${stroke}"`;
            // Track first, then the earned overlay on top of it.
            arcs += `<circle ${common} stroke="var(--bhx-line)"
                        stroke-dasharray="${track} ${c - track}" stroke-dashoffset="${-offset}"/>`;
            if (filled > 0.5) {
                arcs += `<circle ${common} stroke="var(--bhx-${cat.key})" stroke-linecap="round"
                        stroke-dasharray="${Math.max(filled - gap, 0.5)} ${c}" stroke-dashoffset="${-offset}"/>`;
            }
            offset += span;
        });

        return `<svg width="${size}" height="${size}" viewBox="0 0 ${size} ${size}"
                     style="transform:rotate(-90deg)">${arcs}</svg>`;
    }

    // The fleet ring uses the same geometry as every branch ring, so the
    // headline and the cards below it are read the same way.
    document.getElementById('bhxFleetRing')
        .insertAdjacentHTML('afterbegin', ring(FLEET_CATEGORIES, 150, 11));

    // ── Scoreboard ───────────────────────────────────────────────
    const grid = document.getElementById('bhxGrid');
    const empty = document.getElementById('bhxEmpty');
    const search = document.getElementById('bhxSearch');
    const sort = document.getElementById('bhxSort');
    const activeBands = new Set();

    function visible() {
        const q = search.value.trim().toLowerCase();
        let rows = BRANCHES.filter(b =>
            (!q || (b.name + ' ' + b.code + ' ' + (b.region || '')).toLowerCase().includes(q)) &&
            (activeBands.size === 0 || activeBands.has(b.status))
        );

        const by = {
            worst: (a, b) => a.total - b.total || a.name.localeCompare(b.name),
            best: (a, b) => b.total - a.total || a.name.localeCompare(b.name),
            name: (a, b) => a.name.localeCompare(b.name),
            coverage: (a, b) => a.coverage - b.coverage || a.total - b.total,
        }[sort.value];

        return rows.slice().sort(by);
    }

    function render() {
        const rows = visible();
        empty.hidden = rows.length > 0;

        grid.innerHTML = rows.map((b, i) => {
            const band = BANDS[b.status] || BANDS.unknown;
            const cats = b.categories.map(c => `
                <div class="bhx-catline">
                    <span class="bhx-dot" style="background:var(--bhx-${c.key})"></span>
                    <span class="nm">${esc(c.label)}</span>
                    <span class="pts bhx-mono">${c.points.toFixed(1)}</span>
                    <span class="mx bhx-mono">/ ${c.max_points}</span>
                </div>`).join('');

            return `<button class="bhx-branch" data-id="${b.id}">
                <div class="bhx-branch-top">
                    <span class="bhx-mono">${String(i + 1).padStart(2, '0')}</span>
                    <span class="bhx-badge" style="color:${band.color}">
                        ${b.capped ? '&#9888;' : '&#9679;'} ${esc(band.label)}
                    </span>
                </div>
                <div class="bhx-ring-wrap">
                    ${ring(b.categories, 132, 10)}
                    <div class="val">
                        <b style="color:${band.color}">${b.total}</b>
                        <span>/100</span>
                    </div>
                </div>
                <div class="bhx-branch-name">${esc(b.name)}</div>
                <div class="bhx-branch-sub">
                    ${esc(b.code)}${b.region ? ' &middot; ' + esc(b.region) : ''}
                </div>
                <div class="bhx-branch-cats">${cats}</div>
            </button>`;
        }).join('');
    }

    search.addEventListener('input', render);
    sort.addEventListener('change', render);

    document.getElementById('bhxChips').addEventListener('click', e => {
        const chip = e.target.closest('.bhx-chip');
        if (!chip) return;
        const band = chip.dataset.band;
        activeBands.has(band) ? activeBands.delete(band) : activeBands.add(band);
        chip.setAttribute('aria-pressed', activeBands.has(band));
        render();
    });

    // ── Check matrix ─────────────────────────────────────────────
    document.getElementById('bhxMatrixBody').innerHTML = BRANCHES.map(b => {
        const cells = b.categories.flatMap(c => c.checks).map(check => {
            const tone = CHECK_TONE[check.status] || 'unknown';
            const label = check.status === 'unknown' ? '&ndash;' : check.percent;
            return `<td style="text-align:center">
                <span class="bhx-cell" style="background:var(--bhx-${tone})"
                      title="${esc(check.label)} — ${esc(check.message)}">${label}</span>
            </td>`;
        }).join('');

        const band = BANDS[b.status] || BANDS.unknown;
        return `<tr>
            <th>${esc(b.name)} <span style="color:var(--bhx-faint)">${esc(b.code)}</span></th>
            ${cells}
            <td style="text-align:center;font-weight:700;color:${band.color}">${b.total}</td>
        </tr>`;
    }).join('');

    // ── Tabs ─────────────────────────────────────────────────────
    document.querySelectorAll('.bhx-tab').forEach(tab => {
        tab.addEventListener('click', () => {
            document.querySelectorAll('.bhx-tab').forEach(t =>
                t.setAttribute('aria-selected', t === tab));
            document.querySelectorAll('[data-tabpanel]').forEach(p =>
                p.hidden = p.dataset.tabpanel !== tab.dataset.panel);
        });
    });

    // ── Drawer ───────────────────────────────────────────────────
    const drawer = document.getElementById('bhxDrawer');
    const scrim = document.getElementById('bhxScrim');
    let lastFocus = null;

    function open(branch) {
        const band = BANDS[branch.status] || BANDS.unknown;

        document.getElementById('bhxDrawerRing').innerHTML =
            ring(branch.categories, 70, 7) +
            `<div style="position:absolute;inset:0;display:flex;align-items:center;
                         justify-content:center;font-weight:700;font-size:16px;color:${band.color}">
                ${branch.total}
             </div>`;

        document.getElementById('bhxDrawerName').textContent = branch.name;
        document.getElementById('bhxDrawerSub').textContent =
            [branch.code, branch.region].filter(Boolean).join(' · ');
        document.getElementById('bhxDrawerBand').innerHTML =
            `<span style="color:${band.color};font-weight:600">
                ${branch.capped ? '&#9888;' : '&#9679;'} ${esc(band.label)} &middot; ${branch.total} / 100
             </span>`;
        document.getElementById('bhxDrawerLost').innerHTML =
            `<b style="color:var(--bhx-ink)">${branch.lost}</b> points lost` +
            (branch.coverage < 100
                ? ` &middot; ${100 - branch.coverage} not measurable`
                : '');

        const caps = branch.cap_reasons.length
            ? `<div class="bhx-cap"><b>Score capped at ${branch.total}</b><br>
                 ${branch.cap_reasons.map(esc).join('<br>')}</div>`
            : '';

        const cats = branch.categories.map(cat => {
            const checks = cat.checks.map(check => {
                const tone = CHECK_TONE[check.status] || 'unknown';
                const flag = check.status === 'unknown'
                    ? `<span class="bhx-flag" style="color:var(--bhx-unknown)">NO DATA</span>` : '';
                const fails = check.failures.length
                    ? `<ul class="bhx-fails">${check.failures.map(f =>
                        `<li><b>${esc(f.label)}</b>${f.detail ? ' — ' + esc(f.detail) : ''}</li>`).join('')}</ul>`
                    : '';

                return `<div class="bhx-check">
                    <span class="code bhx-mono">${check.code}</span>
                    <div class="body">
                        <b>${esc(check.label)}</b>
                        <span>${flag}${esc(check.message)}</span>
                        ${fails}
                        <a class="bhx-link" href="${esc(check.portal_url)}">Open source page &rarr;</a>
                    </div>
                    <div class="pts" style="color:var(--bhx-${tone})">
                        ${check.points.toFixed(1)}<span>of ${check.max_points}</span>
                    </div>
                </div>`;
            }).join('');

            return `<div class="bhx-cat bhx-card">
                <div class="bhx-cat-head">
                    <div style="position:relative;width:34px;height:34px;flex:none">
                        ${ring([cat], 34, 4)}
                        <div style="position:absolute;inset:0;display:flex;align-items:center;
                                    justify-content:center;font-size:10px;font-weight:700">${cat.percent}</div>
                    </div>
                    <div class="nm"><b>${esc(cat.label)}</b><span>${esc(cat.blurb)}</span></div>
                    <div class="tot">${cat.points.toFixed(1)}<span> / ${cat.max_points}</span></div>
                </div>
                ${checks}
            </div>`;
        }).join('');

        document.getElementById('bhxDrawerBody').innerHTML = caps + cats +
            `<a class="bhx-link" href="${esc(branch.url)}">Open the full branch page &rarr;</a>`;

        drawer.classList.add('open');
        scrim.classList.add('open');
        document.getElementById('bhxClose').focus();
    }

    function close() {
        drawer.classList.remove('open');
        scrim.classList.remove('open');
        lastFocus?.focus();
    }

    grid.addEventListener('click', e => {
        const card = e.target.closest('.bhx-branch');
        if (!card) return;
        lastFocus = card;
        open(BRANCHES.find(b => String(b.id) === card.dataset.id));
    });

    document.getElementById('bhxClose').addEventListener('click', close);
    scrim.addEventListener('click', close);
    document.addEventListener('keydown', e => e.key === 'Escape' && close());

    render();
})();
</script>
@endpush
