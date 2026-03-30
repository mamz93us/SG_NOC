<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $device->name }} — Web Interface</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        html, body { height: 100%; background: #0d1117; color: #e6edf3; font-family: system-ui, sans-serif; }

        #toolbar {
            display: flex;
            align-items: center;
            gap: .5rem;
            height: 42px;
            padding: 0 .75rem;
            background: #161b22;
            border-bottom: 1px solid #30363d;
            flex-shrink: 0;
        }

        #browser-frame {
            width: 100%;
            height: calc(100vh - 42px);
            border: none;
            background: #fff;
        }

        .label {
            font-size: .85rem;
            font-weight: 600;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 260px;
        }

        .url-bar {
            flex: 1;
            background: #0d1117;
            border: 1px solid #30363d;
            border-radius: 6px;
            color: #8b949e;
            font-size: .8rem;
            padding: .25rem .6rem;
            font-family: monospace;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .btn { display: inline-flex; align-items: center; gap: .3rem; border: 1px solid transparent;
               border-radius: 6px; padding: .25rem .6rem; font-size: .8rem; cursor: pointer;
               text-decoration: none; line-height: 1.4; }
        .btn-secondary { background: transparent; color: #8b949e; border-color: #30363d; }
        .btn-secondary:hover { background: #21262d; color: #e6edf3; }
        .btn-warning  { background: transparent; color: #e3b341; border-color: #e3b341; }
        .btn-warning:hover  { background: #2d2208; }
    </style>
</head>
<body>

<div id="toolbar">
    {{-- Back --}}
    <a href="{{ route('admin.devices.show', $device) }}" class="btn btn-secondary" title="Back to device">
        <i class="bi bi-arrow-left"></i>
    </a>

    <span class="label">
        <i class="bi bi-globe me-1" style="color:#58a6ff"></i>{{ $device->name }}
    </span>

    {{-- URL display --}}
    <span class="url-bar" title="Proxied via SG NOC">
        {{ $device->web_protocol ?? 'http' }}://{{ $device->ip_address }}:{{ $device->web_port ?? 80 }}{{ $device->web_path ?? '/' }}
    </span>

    {{-- Reload --}}
    <button class="btn btn-secondary" onclick="document.getElementById('browser-frame').src = document.getElementById('browser-frame').src" title="Reload">
        <i class="bi bi-arrow-clockwise"></i>
    </button>

    {{-- Open directly in new tab (bypass proxy) --}}
    <a href="{{ ($device->web_protocol ?? 'http') . '://' . $device->ip_address . ':' . ($device->web_port ?? 80) . ($device->web_path ?? '/') }}"
       target="_blank" class="btn btn-warning" title="Open directly (no proxy)">
        <i class="bi bi-box-arrow-up-right"></i> Direct
    </a>
</div>

<iframe id="browser-frame"
        src="{{ route('admin.devices.proxy', [$device, ltrim($device->web_path ?? '/', '/')]) }}"
        title="Device Web Interface — {{ $device->name }}"></iframe>

</body>
</html>
