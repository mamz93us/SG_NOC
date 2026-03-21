@extends('layouts.admin')

@section('content')

{{-- ── Page Header ────────────────────────────────────────────────────────── --}}
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold">
            <i class="bi bi-printer-fill me-2 text-primary"></i>My Printers
        </h4>
        <small class="text-muted">
            @if($branch)
                Branch: <strong>{{ $branch->name }}</strong>
            @else
                <span class="text-warning">No branch assigned</span>
            @endif
        </small>
    </div>

    @if($employee)
    <form method="POST" action="/admin/printer-deploy" class="d-inline">
        @csrf
        <input type="hidden" name="employee_id" value="{{ $employee->id }}">
        <button type="submit" class="btn btn-sm btn-outline-primary">
            <i class="bi bi-envelope me-1"></i>Send Setup Email
        </button>
    </form>
    @endif
</div>

{{-- ── Flash Messages ──────────────────────────────────────────────────────── --}}
@if(session('success') && str_contains(session('success'), 'Printer'))
<div class="alert alert-success alert-dismissible fade show py-2 mb-3">
    <i class="bi bi-check-circle me-1"></i>{{ session('success') }}
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
@endif

@if($errors->any())
<div class="alert alert-danger alert-dismissible fade show py-2 mb-3">
    <i class="bi bi-exclamation-triangle me-1"></i>{{ $errors->first() }}
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
@endif

{{-- ── No Employee Record ───────────────────────────────────────────────────── --}}
@if(! $employee)
<div class="card shadow-sm border-0">
    <div class="card-body p-4 text-center">
        <i class="bi bi-person-x display-5 text-secondary mb-3 d-block"></i>
        <h5 class="fw-semibold mb-2">Account Not Linked</h5>
        <p class="text-muted mb-0">
            Your account (<strong>{{ $user->email }}</strong>) is not linked to an employee record.<br>
            Ask your IT administrator to create an employee record and set your branch.
        </p>
    </div>
</div>

{{-- ── Employee Found But No Printers ─────────────────────────────────────── --}}
@elseif($printers->isEmpty())
<div class="card shadow-sm border-0">
    <div class="card-body p-4 text-center">
        <i class="bi bi-printer display-5 text-secondary mb-3 d-block"></i>
        <h5 class="fw-semibold mb-2">No Printers Configured</h5>
        <p class="text-muted mb-0">
            No printers are configured for <strong>{{ $branch->name }}</strong> yet.<br>
            Contact IT to have printers added to your branch.
        </p>
    </div>
</div>

{{-- ── Printer Grid ─────────────────────────────────────────────────────────── --}}
@else

<div class="row g-3 mb-4">
    @foreach($printers as $printer)
    @php
        $hasIp       = ! empty($printer->ip_address);
        $hasToken    = (bool) $token;
        $safeName    = preg_replace('/[^A-Za-z0-9_\-]/', '_', $printer->printer_name);
        $uncPath     = $hasIp ? '\\\\' . $printer->ip_address . '\\' . $safeName : null;
        $location    = $printer->locationLabel();
        $modelLabel  = trim(($printer->manufacturer ?? '') . ' ' . ($printer->model ?? ''));
    @endphp
    <div class="col-md-6 col-lg-4">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-white border-bottom py-2 d-flex align-items-center gap-2">
                <i class="bi bi-printer-fill text-primary fs-5"></i>
                <span class="fw-semibold text-truncate" title="{{ $printer->printer_name }}">
                    {{ $printer->printer_name }}
                </span>
            </div>
            <div class="card-body py-3 px-3">
                <table class="table table-sm table-borderless small mb-3">
                    <tr>
                        <th class="text-muted ps-0" style="width:38%">IP Address</th>
                        <td class="font-monospace">{{ $printer->ip_address ?: '—' }}</td>
                    </tr>
                    <tr>
                        <th class="text-muted ps-0">Location</th>
                        <td>{{ $location ?: '—' }}</td>
                    </tr>
                    <tr>
                        <th class="text-muted ps-0">Model</th>
                        <td>{{ $modelLabel ?: '—' }}</td>
                    </tr>
                    @if($printer->departmentLabel() !== '—')
                    <tr>
                        <th class="text-muted ps-0">Department</th>
                        <td>{{ $printer->departmentLabel() }}</td>
                    </tr>
                    @endif
                    @if($printer->toner_model)
                    <tr>
                        <th class="text-muted ps-0">Toner</th>
                        <td>{{ $printer->toner_model }}</td>
                    </tr>
                    @endif
                </table>

                <div class="d-flex gap-2 flex-wrap">
                    {{-- Windows install button --}}
                    @if($hasToken && $hasIp)
                        <a href="{{ '/printer/setup/script?token=' . $token->token . '&printer_id=' . $printer->id . '&os=windows' }}"
                           class="btn btn-sm btn-outline-primary"
                           title="Download Windows install script">
                            <i class="bi bi-windows me-1"></i>Windows
                        </a>
                    @else
                        <button class="btn btn-sm btn-outline-secondary" disabled title="{{ $hasIp ? 'No setup token available' : 'No IP address configured' }}">
                            <i class="bi bi-windows me-1"></i>Windows
                        </button>
                    @endif

                    {{-- Mac install button (only if IP exists) --}}
                    @if($hasIp)
                        @if($hasToken)
                            <a href="{{ '/printer/setup/script?token=' . $token->token . '&printer_id=' . $printer->id . '&os=mac' }}"
                               class="btn btn-sm btn-outline-dark"
                               title="Download macOS install script">
                                <i class="bi bi-apple me-1"></i>Mac
                            </a>
                        @else
                            <button class="btn btn-sm btn-outline-secondary" disabled title="No setup token available">
                                <i class="bi bi-apple me-1"></i>Mac
                            </button>
                        @endif
                    @endif

                    {{-- Open Web Panel button --}}
                    @if($printer->printer_url)
                        <a href="{{ $printer->printer_url }}" target="_blank" rel="noopener"
                           class="btn btn-sm btn-outline-info"
                           title="Open printer web management page">
                            <i class="bi bi-gear me-1"></i>Web Panel
                        </a>
                    @endif

                    {{-- Copy UNC path button --}}
                    @if($uncPath)
                        <button type="button"
                                class="btn btn-sm btn-outline-secondary"
                                id="copy-btn-{{ $printer->id }}"
                                onclick="copyPath('{{ $uncPath }}', this)"
                                title="Copy network path to clipboard">
                            <i class="bi bi-clipboard me-1"></i>Path
                        </button>
                    @endif
                </div>
            </div>
        </div>
    </div>
    @endforeach
</div>

{{-- ── Branch Info Box ──────────────────────────────────────────────────────── --}}
<div class="alert alert-light border d-flex align-items-start gap-3 mb-4">
    <i class="bi bi-info-circle-fill text-primary mt-1 flex-shrink-0"></i>
    <div class="small text-muted">
        These printers are assigned to your branch: <strong>{{ $branch->name }}</strong>.<br>
        If a printer is missing, contact IT at
        <a href="mailto:support@samirgroup.com">support@samirgroup.com</a>.
    </div>
</div>

{{-- ── Share Setup Link ─────────────────────────────────────────────────────── --}}
<div class="card shadow-sm border-0">
    <div class="card-header bg-white border-bottom py-2">
        <h6 class="mb-0 fw-semibold">
            <i class="bi bi-share me-2 text-secondary"></i>Share Setup Link
        </h6>
    </div>
    <div class="card-body p-3">
        <p class="text-muted small mb-3">
            To set up printers on another device, send yourself a setup link:
        </p>
        @if($employee && $employee->email)
        <form method="POST" action="/admin/printer-deploy" class="d-flex align-items-center gap-2 flex-wrap">
            @csrf
            <input type="hidden" name="employee_id" value="{{ $employee->id }}">
            <span class="text-muted small">
                <i class="bi bi-envelope me-1"></i>{{ $employee->email }}
            </span>
            <button type="submit" class="btn btn-sm btn-primary">
                <i class="bi bi-send me-1"></i>Send Setup Link
            </button>
        </form>
        @if(session('success') && str_contains(session('success'), 'Printer'))
        <div class="mt-2 text-success small">
            <i class="bi bi-check-circle me-1"></i>{{ session('success') }}
        </div>
        @endif
        @else
        <p class="text-muted small mb-0">No email address on record for your employee profile.</p>
        @endif
    </div>
</div>

@endif {{-- end printers exist --}}

@endsection

@push('scripts')
<script>
function copyPath(path, btn) {
    if (! navigator.clipboard) {
        // Fallback for non-secure contexts
        const ta = document.createElement('textarea');
        ta.value = path;
        ta.style.position = 'fixed';
        ta.style.opacity  = '0';
        document.body.appendChild(ta);
        ta.focus();
        ta.select();
        try { document.execCommand('copy'); } catch (e) {}
        document.body.removeChild(ta);
        _flashCopyBtn(btn);
        return;
    }
    navigator.clipboard.writeText(path).then(function () {
        _flashCopyBtn(btn);
    }).catch(function () {
        alert('Could not copy: ' + path);
    });
}

function _flashCopyBtn(btn) {
    const original = btn.innerHTML;
    btn.innerHTML  = '<i class="bi bi-check-lg me-1"></i>Copied!';
    btn.classList.remove('btn-outline-secondary');
    btn.classList.add('btn-success');
    setTimeout(function () {
        btn.innerHTML = original;
        btn.classList.remove('btn-success');
        btn.classList.add('btn-outline-secondary');
    }, 2000);
}
</script>
@endpush
