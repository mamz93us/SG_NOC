<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Printer Setup — {{ $token->branch?->name }}</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { background: #f0f4f8; min-height: 100vh; }
        .setup-header {
            background: linear-gradient(135deg, #1e3a5f 0%, #2d6a9f 100%);
            color: white;
            padding: 2rem;
            text-align: center;
        }
        .printer-card { transition: box-shadow .2s; }
        .printer-card:hover { box-shadow: 0 .5rem 1.5rem rgba(0,0,0,.15) !important; }
        .driver-badge { font-size: .75rem; }
        .copy-btn-success { background-color: #198754 !important; color: white !important; }
    </style>
</head>
<body>

<div class="setup-header mb-4">
    <div style="font-size:2.5rem">🖨️</div>
    <h2 class="fw-bold mb-1">Printer Setup</h2>
    <div class="opacity-75">{{ $token->branch?->name }}</div>
</div>

<div class="container" style="max-width:900px">

    {{-- Greeting --}}
    <div class="alert alert-light border mb-4">
        <i class="bi bi-person-circle me-2 text-primary"></i>
        Hello <strong>{{ $token->employee?->name ?? $token->sent_to_email }}</strong>,
        here are the printers configured for your branch.
        Click the install button for your operating system to set up each printer.
    </div>

    {{-- Printer Cards --}}
    @forelse($printerData as $item)
    @php
        $printer   = $item['printer'];
        $winDriver = $item['win_driver'];
        $macDriver = $item['mac_driver'];
        $hasIp     = ! empty($printer->ip_address);
    @endphp
    <div class="card printer-card shadow-sm border-0 mb-3">
        <div class="card-header bg-white border-bottom d-flex align-items-center gap-2 py-2">
            <i class="bi bi-printer-fill text-primary fs-5"></i>
            <span class="fw-semibold fs-6">{{ $printer->printer_name }}</span>
        </div>
        <div class="card-body">
            <div class="row g-2 mb-3">
                <div class="col-sm-6">
                    <small class="text-muted d-block">Location</small>
                    <span>{{ $printer->locationLabel() ?: '—' }}</span>
                </div>
                @if($hasIp)
                <div class="col-sm-6">
                    <small class="text-muted d-block">IP Address</small>
                    <code>{{ $printer->ip_address }}</code>
                    <button type="button"
                            class="btn btn-xs btn-outline-secondary py-0 px-1 ms-1"
                            style="font-size:.7rem"
                            onclick="copyToClipboard('{{ $printer->ip_address }}', this)">
                        <i class="bi bi-clipboard"></i> Copy
                    </button>
                </div>
                @endif
            </div>

            {{-- Driver status --}}
            <div class="mb-3 p-2 bg-light rounded small">
                <strong class="d-block mb-1">Driver status:</strong>
                @if($winDriver && $winDriver->driver_file_path)
                    <span class="text-success"><i class="bi bi-check-circle-fill me-1"></i>Driver included in package</span>
                @elseif($winDriver)
                    <span class="text-warning"><i class="bi bi-exclamation-triangle-fill me-1"></i>Driver info available (no file uploaded)</span>
                @else
                    <span class="text-info"><i class="bi bi-info-circle-fill me-1"></i>Uses built-in Windows driver</span>
                @endif
            </div>

            {{-- Install buttons --}}
            <div class="d-flex gap-2 flex-wrap">
                {{-- Windows --}}
                @if($hasIp)
                <a href="/printer-setup/script?token={{ $token->token }}&printer_id={{ $printer->id }}&os=windows"
                   class="btn btn-outline-primary btn-sm"
                   title="{{ $winDriver && $winDriver->driver_file_path
                       ? 'Downloads zip with driver + install script. Run as Administrator.'
                       : 'Downloads install script. Requires printer driver already installed on Windows.' }}">
                    <i class="bi bi-windows me-1"></i>Windows Install
                    @if($winDriver && $winDriver->driver_file_path)
                        <span class="badge bg-success ms-1 driver-badge">+Driver</span>
                    @endif
                </a>
                @else
                <button class="btn btn-outline-secondary btn-sm" disabled title="No IP address configured">
                    <i class="bi bi-windows me-1"></i>Windows Install
                </button>
                @endif

                {{-- Mac --}}
                @if($hasIp)
                <a href="/printer-setup/script?token={{ $token->token }}&printer_id={{ $printer->id }}&os=mac"
                   class="btn btn-outline-dark btn-sm"
                   title="Downloads macOS setup script using driverless IPP.">
                    <i class="bi bi-apple me-1"></i>Mac Install
                </a>
                @endif

                {{-- Open web panel --}}
                @if($printer->printer_url)
                <a href="{{ $printer->printer_url }}" target="_blank" rel="noopener"
                   class="btn btn-outline-info btn-sm">
                    <i class="bi bi-gear me-1"></i>Web Panel
                </a>
                @endif
            </div>
        </div>
    </div>
    @empty
    <div class="card border-0 shadow-sm">
        <div class="card-body text-center py-5">
            <i class="bi bi-printer display-4 text-muted d-block mb-3"></i>
            <h5 class="text-muted">No Printers Configured</h5>
            <p class="text-muted small">No printers are set up for your branch yet. Contact IT.</p>
        </div>
    </div>
    @endforelse

    {{-- Info box --}}
    <div class="alert alert-secondary small mt-4 mb-4">
        <i class="bi bi-info-circle me-1"></i>
        <strong>Need help?</strong>
        If automatic setup fails, contact IT at
        <a href="mailto:support@samirgroup.com">support@samirgroup.com</a>.
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
function copyToClipboard(text, btn) {
    navigator.clipboard?.writeText(text).then(() => {
        const orig = btn.innerHTML;
        btn.innerHTML = '<i class="bi bi-check-lg"></i> Copied';
        btn.classList.add('copy-btn-success');
        setTimeout(() => {
            btn.innerHTML = orig;
            btn.classList.remove('copy-btn-success');
        }, 2000);
    }).catch(() => alert('IP: ' + text));
}
</script>
</body>
</html>
