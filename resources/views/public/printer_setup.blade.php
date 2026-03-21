<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>🖨️ Printer Setup — {{ $branch?->name ?? 'SG NOC' }}</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <style>
    body { background: #f0f4f8; font-family: 'Segoe UI', system-ui, sans-serif; }
    .header-bar { background: #1e3a5f; color: #fff; padding: 1.25rem 1.5rem; }
    .printer-card { background: #fff; border-radius: .75rem; border: 1px solid #dee2e6; padding: 1.25rem 1.5rem; margin-bottom: 1rem; transition: box-shadow .15s; }
    .printer-card:hover { box-shadow: 0 4px 16px rgba(30,58,95,.1); }
    .printer-icon { font-size: 2rem; color: #1e3a5f; }
    .btn-win { background: #0078d4; color: #fff; border: none; }
    .btn-win:hover { background: #106ebe; color: #fff; }
    .btn-mac { background: #555; color: #fff; border: none; }
    .btn-mac:hover { background: #333; color: #fff; }
    .copy-path-btn { font-family: monospace; font-size: .8rem; }
    .info-box { background: #e8f4fd; border-left: 4px solid #0d6efd; border-radius: .5rem; }
    .employee-greeting { background: #fff; border-radius: .75rem; border: 1px solid #dee2e6; }
  </style>
</head>
<body>

  <!-- Header -->
  <div class="header-bar">
    <div class="container">
      <div class="d-flex align-items-center gap-3">
        <i class="bi bi-printer-fill" style="font-size:1.75rem;"></i>
        <div>
          <h5 class="mb-0 fw-bold">Printer Setup — {{ $branch?->name ?? 'Branch' }}</h5>
          <small style="opacity:.75;">SG NOC System · Samir Group IT</small>
        </div>
      </div>
    </div>
  </div>

  <div class="container py-4" style="max-width:780px;">

    <!-- Employee Greeting -->
    @if($employee)
    <div class="employee-greeting p-4 mb-4 d-flex align-items-center gap-3">
      <div class="rounded-circle bg-primary d-flex align-items-center justify-content-center text-white fw-bold"
           style="width:48px;height:48px;flex-shrink:0;font-size:1.1rem;">
        {{ $employee->initials() }}
      </div>
      <div>
        <div class="fw-semibold">Hello, {{ $employee->name }}!</div>
        <div class="text-muted small">
          Setting up printers for <strong>{{ $branch?->name }}</strong>.
          Follow the steps below to install your office printers.
        </div>
      </div>
    </div>
    @endif

    <!-- Printers -->
    @forelse($printers as $printer)
    <div class="printer-card">
      <div class="d-flex align-items-start gap-3">
        <i class="bi bi-printer printer-icon mt-1"></i>
        <div class="flex-grow-1">
          <h6 class="mb-1 fw-bold">{{ $printer->printer_name }}</h6>

          <div class="row g-2 mb-3 small text-muted">
            @if($printer->ip_address)
            <div class="col-sm-6">
              <i class="bi bi-hdd-network me-1"></i>IP: <code>{{ $printer->ip_address }}</code>
            </div>
            @endif
            @if($printer->locationLabel() !== '—')
            <div class="col-sm-6">
              <i class="bi bi-geo-alt me-1"></i>Location: {{ $printer->locationLabel() }}
            </div>
            @endif
            @if($printer->model)
            <div class="col-sm-6">
              <i class="bi bi-tag me-1"></i>Model: {{ $printer->manufacturer ? $printer->manufacturer . ' ' : '' }}{{ $printer->model }}
            </div>
            @endif
          </div>

          <div class="d-flex flex-wrap gap-2">
            {{-- Windows install --}}
            <a href="{{ '/printer/setup/script?token=' . $token->token . '&printer_id=' . $printer->id . '&os=windows' }}"
               class="btn btn-sm btn-win">
              <i class="bi bi-windows me-1"></i>Windows Install
            </a>

            {{-- Mac install (only if IP is set) --}}
            @if($printer->ip_address)
            <a href="{{ '/printer/setup/script?token=' . $token->token . '&printer_id=' . $printer->id . '&os=mac' }}"
               class="btn btn-sm btn-mac">
              <i class="bi bi-apple me-1"></i>Mac Install
            </a>
            @endif

            {{-- Copy UNC Path --}}
            @if($printer->ip_address)
            @php
              $share = preg_replace('/[^A-Za-z0-9_-]/', '', $printer->printer_name);
              $uncPath = '\\\\' . $printer->ip_address . '\\' . $share;
            @endphp
            <button class="btn btn-sm btn-outline-secondary copy-path-btn"
                    onclick="copyPath('{{ addslashes($uncPath) }}', this)">
              <i class="bi bi-clipboard me-1"></i>{{ $uncPath }}
            </button>
            @endif
          </div>
        </div>
      </div>
    </div>
    @empty
    <div class="alert alert-warning">
      <i class="bi bi-exclamation-triangle me-2"></i>
      No printers are configured for <strong>{{ $branch?->name }}</strong> yet.
      Contact your IT administrator.
    </div>
    @endforelse

    <!-- Help Box -->
    <div class="info-box p-3 mt-2">
      <p class="mb-1 small fw-semibold">Need help?</p>
      <p class="mb-0 small text-muted">
        If you have trouble installing, contact IT at
        <a href="mailto:support@samirgroup.com">support@samirgroup.com</a>
        or open a ticket from the IT portal.
      </p>
    </div>

    <p class="text-center text-muted small mt-4">
      <i class="bi bi-shield-lock me-1"></i>
      This link is personal and can only be used by you.
      It expires on {{ $token->expires_at?->format('d M Y') }}.
    </p>

  </div>

  <script>
  function copyPath(path, btn) {
    navigator.clipboard.writeText(path).then(function() {
      var orig = btn.innerHTML;
      btn.innerHTML = '<i class="bi bi-check2 me-1"></i>Copied!';
      btn.classList.replace('btn-outline-secondary', 'btn-outline-success');
      setTimeout(function() {
        btn.innerHTML = orig;
        btn.classList.replace('btn-outline-success', 'btn-outline-secondary');
      }, 2000);
    });
  }
  </script>
</body>
</html>
