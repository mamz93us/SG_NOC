<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>NOC Wallboard - SG NOC</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        :root {
            --wb-bg: #0d1117;
            --wb-card: #161b22;
            --wb-border: #30363d;
            --wb-text: #c9d1d9;
            --wb-muted: #8b949e;
            --wb-green: #3fb950;
            --wb-yellow: #d29922;
            --wb-red: #f85149;
            --wb-blue: #58a6ff;
        }
        * { box-sizing: border-box; }
        body {
            background: var(--wb-bg);
            color: var(--wb-text);
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Helvetica, Arial, sans-serif;
            margin: 0; padding: 0;
            overflow-x: hidden;
        }

        /* Header */
        .wb-header {
            background: var(--wb-card);
            border-bottom: 1px solid var(--wb-border);
            padding: 12px 24px;
            display: flex; align-items: center; justify-content: space-between;
        }
        .wb-header h1 { font-size: 1.3rem; font-weight: 700; margin: 0; }
        .wb-header .wb-time { font-size: 1.1rem; font-family: monospace; color: var(--wb-muted); }
        .wb-header .wb-status { font-size: .75rem; color: var(--wb-green); }

        /* Stat Cards */
        .wb-stats {
            display: flex; gap: 12px; padding: 16px 24px; flex-wrap: wrap;
        }
        .wb-stat {
            flex: 1; min-width: 120px;
            background: var(--wb-card);
            border: 1px solid var(--wb-border);
            border-radius: 8px;
            padding: 14px 18px;
            text-align: center;
        }
        .wb-stat .wb-val { font-size: 2rem; font-weight: 800; line-height: 1; }
        .wb-stat .wb-label { font-size: .72rem; color: var(--wb-muted); text-transform: uppercase; letter-spacing: .5px; margin-top: 4px; }

        /* Sections */
        .wb-section {
            padding: 0 24px 16px;
        }
        .wb-section-title {
            font-size: .8rem; font-weight: 700; text-transform: uppercase;
            letter-spacing: .5px; color: var(--wb-muted); margin-bottom: 8px;
            display: flex; align-items: center; gap: 6px;
        }

        /* Extension Grid */
        .wb-ext-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
            gap: 6px;
        }
        .wb-ext {
            background: var(--wb-card);
            border: 1px solid var(--wb-border);
            border-radius: 6px;
            padding: 8px 10px;
            display: flex; align-items: center; gap: 8px;
            font-size: .78rem;
        }
        .wb-ext .wb-dot {
            width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0;
        }
        .wb-ext .wb-ext-num { font-weight: 700; font-family: monospace; min-width: 36px; }
        .wb-ext .wb-ext-name { flex: 1; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .wb-ext .wb-ext-port { font-size: .65rem; color: var(--wb-muted); white-space: nowrap; }

        .dot-idle { background: var(--wb-green); }
        .dot-inuse, .dot-busy, .dot-ringing { background: var(--wb-yellow); }
        .dot-unavailable { background: var(--wb-red); }
        .dot-default { background: var(--wb-muted); }

        /* Tables */
        .wb-table {
            width: 100%;
            border-collapse: collapse;
            font-size: .78rem;
        }
        .wb-table th {
            background: var(--wb-card);
            color: var(--wb-muted);
            font-weight: 600; text-transform: uppercase;
            font-size: .68rem; letter-spacing: .5px;
            padding: 8px 12px;
            border-bottom: 1px solid var(--wb-border);
        }
        .wb-table td {
            padding: 6px 12px;
            border-bottom: 1px solid var(--wb-border);
        }

        /* Status badges */
        .wb-badge {
            display: inline-block; padding: 2px 8px; border-radius: 4px;
            font-size: .68rem; font-weight: 600;
        }
        .wb-badge-green { background: rgba(63, 185, 80, .15); color: var(--wb-green); }
        .wb-badge-red { background: rgba(248, 81, 73, .15); color: var(--wb-red); }
        .wb-badge-yellow { background: rgba(210, 153, 34, .15); color: var(--wb-yellow); }

        /* Switches strip */
        .wb-switch-strip { display: flex; gap: 8px; flex-wrap: wrap; }
        .wb-switch {
            background: var(--wb-card);
            border: 1px solid var(--wb-border);
            border-radius: 6px;
            padding: 6px 12px;
            font-size: .75rem; font-weight: 600;
            display: flex; align-items: center; gap: 6px;
        }

        /* Alerts ticker */
        .wb-alert {
            background: rgba(248, 81, 73, .08);
            border: 1px solid rgba(248, 81, 73, .2);
            border-radius: 6px;
            padding: 8px 14px;
            margin-bottom: 4px;
            display: flex; align-items: center; gap: 10px;
            font-size: .78rem;
        }
        .wb-alert-critical { border-color: rgba(248, 81, 73, .4); }
        .wb-alert-warning { background: rgba(210, 153, 34, .08); border-color: rgba(210, 153, 34, .3); }

        /* Layout grid */
        .wb-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        @media (max-width: 1200px) { .wb-grid-2 { grid-template-columns: 1fr; } }
    </style>
</head>
<body>

<!-- Header -->
<div class="wb-header">
    <div class="d-flex align-items-center gap-3">
        <h1><i class="bi bi-display me-2"></i>NOC Wallboard</h1>
        <span class="wb-status" id="syncStatus"><i class="bi bi-circle-fill me-1" style="font-size:.5rem;"></i>Live</span>
    </div>
    <div class="wb-time" id="clock"></div>
</div>

<!-- Global Stats -->
<div class="wb-stats" id="statsRow">
    <div class="wb-stat">
        <div class="wb-val" style="color:var(--wb-green);" id="statSwitches">{{ $stats['switches_online'] }}</div>
        <div class="wb-label">Switches Online</div>
    </div>
    <div class="wb-stat">
        <div class="wb-val" style="color:var(--wb-blue);" id="statExtensions">{{ $stats['extensions'] }}</div>
        <div class="wb-label">Extensions</div>
    </div>
    <div class="wb-stat">
        <div class="wb-val" style="color:var(--wb-yellow);" id="statCalls">{{ $stats['active_calls'] }}</div>
        <div class="wb-label">Active Calls</div>
    </div>
    <div class="wb-stat">
        <div class="wb-val" style="color:var(--wb-green);" id="statVpn">{{ $stats['vpn'] }}</div>
        <div class="wb-label">VPN Tunnels</div>
    </div>
    <div class="wb-stat">
        <div class="wb-val" style="color:{{ $stats['critical'] > 0 ? 'var(--wb-red)' : 'var(--wb-green)' }};" id="statAlerts">{{ $stats['alerts'] }}</div>
        <div class="wb-label">Open Alerts</div>
    </div>
</div>

<!-- Extension Grid -->
<div class="wb-section">
    <div class="wb-section-title"><i class="bi bi-telephone-fill"></i> Extensions</div>
    <div class="wb-ext-grid" id="extGrid">
        @foreach($extension_grid as $ext)
        <div class="wb-ext">
            <span class="wb-dot dot-{{ $ext['status'] }}"></span>
            <span class="wb-ext-num">{{ $ext['extension'] }}</span>
            <span class="wb-ext-name">{{ $ext['name'] }}</span>
            <span class="wb-ext-port">{{ $ext['location'] !== '-' ? $ext['location'] : '' }}</span>
        </div>
        @endforeach
    </div>
</div>

<!-- Active Calls + Trunks side-by-side -->
<div class="wb-section">
    <div class="wb-grid-2">
        <!-- Active Calls -->
        <div>
            <div class="wb-section-title"><i class="bi bi-telephone-forward-fill" style="color:var(--wb-yellow);"></i> Active Calls</div>
            <table class="wb-table" id="callsTable">
                <thead><tr><th>Caller</th><th>Destination</th><th>Duration</th></tr></thead>
                <tbody id="callsBody">
                    @forelse($active_calls as $call)
                    <tr>
                        <td class="fw-semibold font-monospace">{{ $call['caller'] }}</td>
                        <td class="font-monospace">{{ $call['callee'] }}</td>
                        <td>{{ $call['duration'] }}</td>
                    </tr>
                    @empty
                    <tr><td colspan="3" style="color:var(--wb-muted);text-align:center;">No active calls</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <!-- Trunks -->
        <div>
            <div class="wb-section-title"><i class="bi bi-hdd-network-fill" style="color:var(--wb-blue);"></i> SIP Trunks</div>
            <table class="wb-table">
                <thead><tr><th>Trunk</th><th>Host</th><th>Status</th></tr></thead>
                <tbody id="trunksBody">
                    @forelse($trunks as $trunk)
                    <tr>
                        <td class="fw-semibold">{{ $trunk['name'] }}</td>
                        <td class="font-monospace" style="font-size:.72rem;">{{ $trunk['host'] }}</td>
                        <td><span class="wb-badge {{ str_contains($trunk['status'], 'unreachable') ? 'wb-badge-red' : 'wb-badge-green' }}">{{ $trunk['status'] }}</span></td>
                    </tr>
                    @empty
                    <tr><td colspan="3" style="color:var(--wb-muted);text-align:center;">No trunks</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Network Switches -->
<div class="wb-section">
    <div class="wb-section-title"><i class="bi bi-router-fill" style="color:var(--wb-blue);"></i> Network Switches</div>
    <div class="wb-switch-strip" id="switchStrip">
        @foreach($switches as $sw)
        <div class="wb-switch">
            <span class="wb-dot {{ $sw['status'] === 'online' ? 'dot-idle' : 'dot-unavailable' }}"></span>
            {{ $sw['name'] }}
        </div>
        @endforeach
    </div>
</div>

<!-- Alerts -->
@if(count($alerts) > 0)
<div class="wb-section">
    <div class="wb-section-title"><i class="bi bi-exclamation-triangle-fill" style="color:var(--wb-red);"></i> Open Alerts</div>
    <div id="alertsList">
        @foreach($alerts as $alert)
        <div class="wb-alert wb-alert-{{ $alert['severity'] }}">
            <i class="bi bi-{{ $alert['severity'] === 'critical' ? 'exclamation-octagon-fill' : 'exclamation-triangle-fill' }}" style="color:{{ $alert['severity'] === 'critical' ? 'var(--wb-red)' : 'var(--wb-yellow)' }};"></i>
            <span class="fw-semibold">{{ $alert['title'] }}</span>
            <span style="color:var(--wb-muted);margin-left:auto;font-size:.72rem;">{{ $alert['time'] }}</span>
        </div>
        @endforeach
    </div>
</div>
@endif

<script>
// Clock
function updateClock() {
    const now = new Date();
    document.getElementById('clock').textContent =
        now.toLocaleDateString('en-GB', { weekday: 'short', day: '2-digit', month: 'short', year: 'numeric' }) +
        '  ' + now.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
}
updateClock();
setInterval(updateClock, 1000);

// Auto-refresh every 15 seconds
function refreshWallboard() {
    const status = document.getElementById('syncStatus');
    status.innerHTML = '<i class="bi bi-arrow-repeat me-1 spin"></i>Updating...';

    fetch('{{ route("admin.noc.wallboard.data") }}')
        .then(r => r.json())
        .then(data => {
            // Stats
            document.getElementById('statSwitches').textContent = data.stats.switches_online;
            document.getElementById('statExtensions').textContent = data.stats.extensions;
            document.getElementById('statCalls').textContent = data.stats.active_calls;
            document.getElementById('statVpn').textContent = data.stats.vpn;
            document.getElementById('statAlerts').textContent = data.stats.alerts;
            document.getElementById('statAlerts').style.color = data.stats.critical > 0 ? 'var(--wb-red)' : 'var(--wb-green)';

            // Extension grid
            const extGrid = document.getElementById('extGrid');
            extGrid.innerHTML = data.extension_grid.map(e =>
                `<div class="wb-ext">
                    <span class="wb-dot dot-${e.status}"></span>
                    <span class="wb-ext-num">${e.extension}</span>
                    <span class="wb-ext-name">${e.name}</span>
                    <span class="wb-ext-port">${e.location !== '-' ? e.location : ''}</span>
                </div>`
            ).join('');

            // Active calls
            const callsBody = document.getElementById('callsBody');
            if (data.active_calls.length > 0) {
                callsBody.innerHTML = data.active_calls.map(c =>
                    `<tr>
                        <td class="fw-semibold font-monospace">${c.caller}</td>
                        <td class="font-monospace">${c.callee}</td>
                        <td>${c.duration}</td>
                    </tr>`
                ).join('');
            } else {
                callsBody.innerHTML = '<tr><td colspan="3" style="color:var(--wb-muted);text-align:center;">No active calls</td></tr>';
            }

            // Trunks
            const trunksBody = document.getElementById('trunksBody');
            trunksBody.innerHTML = data.trunks.map(t =>
                `<tr>
                    <td class="fw-semibold">${t.name}</td>
                    <td class="font-monospace" style="font-size:.72rem;">${t.host}</td>
                    <td><span class="wb-badge ${t.status.includes('unreachable') ? 'wb-badge-red' : 'wb-badge-green'}">${t.status}</span></td>
                </tr>`
            ).join('') || '<tr><td colspan="3" style="color:var(--wb-muted);text-align:center;">No trunks</td></tr>';

            // Switches
            const switchStrip = document.getElementById('switchStrip');
            switchStrip.innerHTML = data.switches.map(s =>
                `<div class="wb-switch">
                    <span class="wb-dot ${s.status === 'online' ? 'dot-idle' : 'dot-unavailable'}"></span>
                    ${s.name}
                </div>`
            ).join('');

            // Alerts
            const alertsList = document.getElementById('alertsList');
            if (alertsList) {
                if (data.alerts.length > 0) {
                    alertsList.innerHTML = data.alerts.map(a =>
                        `<div class="wb-alert wb-alert-${a.severity}">
                            <i class="bi bi-${a.severity === 'critical' ? 'exclamation-octagon-fill' : 'exclamation-triangle-fill'}" style="color:${a.severity === 'critical' ? 'var(--wb-red)' : 'var(--wb-yellow)'};"></i>
                            <span class="fw-semibold">${a.title}</span>
                            <span style="color:var(--wb-muted);margin-left:auto;font-size:.72rem;">${a.time}</span>
                        </div>`
                    ).join('');
                } else {
                    alertsList.innerHTML = '';
                }
            }

            status.innerHTML = '<i class="bi bi-circle-fill me-1" style="font-size:.5rem;"></i>Live';
        })
        .catch(() => {
            status.innerHTML = '<i class="bi bi-exclamation-circle me-1" style="color:var(--wb-red);"></i>Connection lost';
        });
}

setInterval(refreshWallboard, 15000);
</script>

</body>
</html>
