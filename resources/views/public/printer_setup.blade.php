<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Printer Setup</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
  body { background: #f0f4f8; min-height: 100vh; }
  .header-bar { background: linear-gradient(135deg, #0d6efd, #0a58ca); color: #fff; padding: 28px 0; margin-bottom: 32px; }
  .step-card { border: none; border-radius: 12px; box-shadow: 0 2px 12px rgba(0,0,0,.07); }
  .step-number { width: 36px; height: 36px; background: #0d6efd; color: #fff; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-weight: 700; flex-shrink: 0; }
</style>
</head>
<body>

@php
  $printerName = $config['printer_name'] ?? 'Office Printer';
  $ip          = $config['ip_address']   ?? '—';
  $mfr         = $config['manufacturer'] ?? '';
  $model       = $config['model']         ?? '';
  $branch      = $config['branch']        ?? null;
  $location    = $config['location']      ?? null;
  $winUrl      = url('/printer-setup/script?token=' . $token->token . '&os=windows');
  $linuxUrl    = url('/printer-setup/script?token=' . $token->token . '&os=linux');
@endphp

<div class="header-bar">
  <div class="container">
    <h2 class="mb-1 fw-bold"><i class="bi bi-printer me-2"></i>Printer Setup</h2>
    <p class="mb-0 opacity-75">Follow the steps below to add this printer to your computer</p>
  </div>
</div>

<div class="container pb-5">
  <div class="row g-4">

    <!-- Printer Info -->
    <div class="col-lg-4">
      <div class="card step-card h-100">
        <div class="card-body">
          <h5 class="fw-bold mb-3"><i class="bi bi-info-circle text-primary me-2"></i>Printer Details</h5>
          <dl class="mb-0">
            <dt class="small text-muted">Printer Name</dt>
            <dd class="fw-semibold mb-2">{{ $printerName }}</dd>
            @if($mfr || $model)
            <dt class="small text-muted">Make / Model</dt>
            <dd class="mb-2">{{ trim($mfr . ' ' . $model) }}</dd>
            @endif
            <dt class="small text-muted">IP Address</dt>
            <dd class="mb-2"><code>{{ $ip }}</code></dd>
            @if($branch)
            <dt class="small text-muted">Branch</dt>
            <dd class="mb-2">{{ $branch }}</dd>
            @endif
            @if($location && $location !== '—')
            <dt class="small text-muted">Location</dt>
            <dd class="mb-2">{{ $location }}</dd>
            @endif
          </dl>
          <hr>
          <p class="small text-muted mb-0">
            <i class="bi bi-clock me-1"></i>
            Link expires: <strong>{{ $token->expires_at?->format('d M Y') ?? 'N/A' }}</strong>
          </p>
        </div>
      </div>
    </div>

    <!-- Steps -->
    <div class="col-lg-8">

      <!-- Step 1: Auto-install scripts -->
      <div class="card step-card mb-4">
        <div class="card-body">
          <div class="d-flex gap-3 align-items-start mb-3">
            <span class="step-number">1</span>
            <div>
              <h5 class="fw-bold mb-1">Automatic Installation (Recommended)</h5>
              <p class="text-muted mb-0 small">Download and run the script for your operating system</p>
            </div>
          </div>

          <div class="row g-3">
            <div class="col-sm-6">
              <a href="{{ $winUrl }}" class="btn btn-outline-primary w-100 d-flex align-items-center gap-2 py-3">
                <i class="bi bi-windows fs-4"></i>
                <div class="text-start">
                  <div class="fw-semibold">Windows</div>
                  <small class="opacity-75">Download .bat script</small>
                </div>
              </a>
            </div>
            <div class="col-sm-6">
              <a href="{{ $linuxUrl }}" class="btn btn-outline-secondary w-100 d-flex align-items-center gap-2 py-3">
                <i class="bi bi-terminal fs-4"></i>
                <div class="text-start">
                  <div class="fw-semibold">macOS / Linux</div>
                  <small class="opacity-75">Download .sh script</small>
                </div>
              </a>
            </div>
          </div>

          <div class="alert alert-info border-0 mt-3 mb-0 small">
            <i class="bi bi-info-circle me-1"></i>
            <strong>Windows:</strong> Right-click the .bat file and choose <em>Run as administrator</em>.
            <strong>macOS/Linux:</strong> Run <code>chmod +x install_printer.sh && ./install_printer.sh</code> in Terminal.
          </div>
        </div>
      </div>

      <!-- Step 2: Manual -->
      <div class="card step-card mb-4">
        <div class="card-body">
          <div class="d-flex gap-3 align-items-start mb-3">
            <span class="step-number">2</span>
            <div>
              <h5 class="fw-bold mb-1">Manual Installation</h5>
              <p class="text-muted mb-0 small">If the script doesn't work, add the printer manually</p>
            </div>
          </div>

          <ul class="list-unstyled mb-0">
            <li class="mb-3 d-flex gap-2">
              <span class="badge bg-primary-subtle text-primary mt-1">Win</span>
              <span class="small">Open <strong>Settings → Bluetooth &amp; devices → Printers &amp; scanners → Add device</strong>.<br>
              Select <em>"The printer I want isn't listed"</em> → <em>"Add a printer using IP address or hostname"</em> → enter <code>{{ $ip }}</code></span>
            </li>
            <li class="mb-3 d-flex gap-2">
              <span class="badge bg-secondary-subtle text-secondary mt-1">Mac</span>
              <span class="small">Open <strong>System Settings → Printers &amp; Scanners → Add Printer</strong>.<br>
              Click <em>"IP"</em> tab, enter <code>{{ $ip }}</code> in the Address field, Protocol: <em>IPP</em>.</span>
            </li>
            <li class="d-flex gap-2">
              <span class="badge bg-dark-subtle text-dark mt-1">Linux</span>
              <span class="small">Run: <code>sudo lpadmin -p "{{ preg_replace('/[^A-Za-z0-9_-]/', '_', $printerName) }}" -E -v "socket://{{ $ip }}:9100" -m everywhere</code></span>
            </li>
          </ul>
        </div>
      </div>

      <!-- Step 3: Test -->
      <div class="card step-card">
        <div class="card-body">
          <div class="d-flex gap-3 align-items-start">
            <span class="step-number">3</span>
            <div>
              <h5 class="fw-bold mb-1">Print a Test Page</h5>
              <p class="text-muted mb-0 small">
                Once added, right-click the printer → <strong>Printer properties → Print Test Page</strong>.<br>
                If you need help, contact IT with the printer name: <strong>{{ $printerName }}</strong>.
              </p>
            </div>
          </div>
        </div>
      </div>

    </div>
  </div>
</div>

</body>
</html>
