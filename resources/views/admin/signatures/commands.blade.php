@extends('layouts.admin')

@section('content')

<div class="d-flex align-items-center mb-4">
    <div>
        <h1 class="h3 mb-0 fw-bold">Signature Commands</h1>
        <small class="text-muted">Copy-and-run PowerShell for operating the signature system — server-side rules, users, testing, and device force-apply</small>
    </div>
    <div class="ms-auto d-flex gap-2">
        <a href="{{ route('admin.signatures.transport-preview') }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-eye me-1"></i>Transport Preview
        </a>
        <a href="{{ route('admin.signatures.index') }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Back to Templates
        </a>
    </div>
</div>

{{-- ── API key entry ── --}}
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <label for="apiKey" class="form-label fw-semibold mb-1">
            <i class="bi bi-key me-1 text-warning"></i>Signature API key
        </label>
        <p class="text-muted small mb-2">
            Paste an active <strong>Signature</strong>-scoped key. It is <strong>not stored on this page</strong> —
            it only fills the commands below in your browser. The key is never shown after it's created, so keep your copy safe.
        </p>
        <div class="input-group input-group-sm" style="max-width:640px;">
            <span class="input-group-text font-monospace">hrk_</span>
            <input type="text" class="form-control font-monospace" id="apiKey" autocomplete="off" spellcheck="false"
                   placeholder="paste the rest of the signature API key here…">
            <button class="btn btn-outline-secondary" type="button" id="toggleKey" title="Show / hide">
                <i class="bi bi-eye"></i>
            </button>
        </div>

        @if($keys->isNotEmpty())
        <div class="mt-3">
            <p class="text-muted small fw-semibold mb-1">Active signature keys (prefix only)</p>
            <div class="d-flex flex-wrap gap-2">
                @foreach($keys as $k)
                    <span class="badge {{ $k->scope === 'signature' ? 'bg-warning text-dark' : 'bg-secondary' }} border font-monospace"
                          title="{{ $k->name }}{{ $k->last_used_at ? ' — last used '.$k->last_used_at->diffForHumans() : '' }}">
                        {{ $k->key_prefix }}… · {{ $k->name }}
                    </span>
                @endforeach
            </div>
        </div>
        @else
        <div class="alert alert-warning small mt-3 mb-0">
            <i class="bi bi-exclamation-triangle me-1"></i>
            No active signature API key found. Create one under <strong>API Keys</strong> (scope: Signature) first.
        </div>
        @endif
    </div>
</div>

<div class="alert alert-warning d-flex align-items-start small" id="keyWarn">
    <i class="bi bi-arrow-up-circle me-2 mt-1"></i>
    <div>Paste the API key above and the commands below will fill in automatically. Each block has a <strong>Copy</strong> button.
        Run them in <strong>Windows PowerShell</strong> — each script command already sets a per-session
        <code>ExecutionPolicy Bypass</code> (no admin needed, reverts when the window closes) so locked-down devices don't reject it.</div>
</div>

{{-- ═══════════ 1. Update server-side signature ═══════════ --}}
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-transparent py-3 d-flex align-items-center">
        <span class="badge rounded-pill bg-primary me-2">1</span>
        <span class="fw-semibold"><i class="bi bi-cloud-arrow-up me-1 text-primary"></i>Update server-side signature</span>
        <span class="ms-auto text-muted small">New Outlook / OWA / mobile — Exchange transport rules</span>
    </div>
    <div class="card-body">
        <p class="text-muted small">
            Re-pushes the rendered signature HTML to the Exchange mail-flow rules. Run this <strong>after editing a template,
            logo, or colour</strong> in NOC. Fast and safe to repeat (idempotent). Runs from any PC with Exchange admin rights —
            it fetches the script from NOC, so nothing to keep locally.
        </p>

        <p class="fw-semibold small mb-1"><i class="bi bi-lightning-charge me-1 text-warning"></i>Refresh rules only (most common)</p>
        @include('admin.signatures.partials.cmd', ['id' => 'srvRefresh', 'cmd' =>
            'Set-ExecutionPolicy -Scope Process Bypass -Force; $k = \'__KEY__\'; irm "'.$baseUrl.'/signature/deploy.ps1?file=setup&api_key=$k" -OutFile "$env:TEMP\Setup-ServerSignatures.ps1"; & "$env:TEMP\Setup-ServerSignatures.ps1" -ApiKey $k -RefreshOnly'
        ])

        <p class="fw-semibold small mb-1 mt-3"><i class="bi bi-eye me-1 text-muted"></i>Preview first (changes nothing)</p>
        @include('admin.signatures.partials.cmd', ['id' => 'srvWhatIf', 'cmd' =>
            'Set-ExecutionPolicy -Scope Process Bypass -Force; $k = \'__KEY__\'; irm "'.$baseUrl.'/signature/deploy.ps1?file=setup&api_key=$k" -OutFile "$env:TEMP\Setup-ServerSignatures.ps1"; & "$env:TEMP\Setup-ServerSignatures.ps1" -ApiKey $k -WhatIf'
        ])

        <p class="fw-semibold small mb-1 mt-3"><i class="bi bi-stars me-1 text-primary"></i>First-time full setup (creates groups + rules + adds everyone)</p>
        @include('admin.signatures.partials.cmd', ['id' => 'srvFull', 'cmd' =>
            'Set-ExecutionPolicy -Scope Process Bypass -Force; $k = \'__KEY__\'; irm "'.$baseUrl.'/signature/deploy.ps1?file=setup&api_key=$k" -OutFile "$env:TEMP\Setup-ServerSignatures.ps1"; & "$env:TEMP\Setup-ServerSignatures.ps1" -ApiKey $k'
        ])
        <div class="small text-muted mt-2">
            <i class="bi bi-info-circle me-1"></i>The first run prompts for an Exchange Online admin sign-in and installs the
            <code>ExchangeOnlineManagement</code> module if missing.
        </div>
    </div>
</div>

{{-- ═══════════ 2. Add / sync users ═══════════ --}}
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-transparent py-3 d-flex align-items-center">
        <span class="badge rounded-pill bg-success me-2">2</span>
        <span class="fw-semibold"><i class="bi bi-people me-1 text-success"></i>Add / sync users</span>
        <span class="ms-auto text-muted small">Populate the scope groups from NOC data</span>
    </div>
    <div class="card-body">
        <p class="text-muted small">
            Syncs the Exchange scope-group membership from NOC (by domain and gender), so new / changed employees start
            getting the server-side signature. Skips the transport rules. Existing members are left in place.
        </p>

        <p class="fw-semibold small mb-1"><i class="bi bi-arrow-repeat me-1 text-success"></i>Sync all groups from NOC</p>
        @include('admin.signatures.partials.cmd', ['id' => 'usrSync', 'cmd' =>
            'Set-ExecutionPolicy -Scope Process Bypass -Force; $k = \'__KEY__\'; irm "'.$baseUrl.'/signature/deploy.ps1?file=setup&api_key=$k" -OutFile "$env:TEMP\Setup-ServerSignatures.ps1"; & "$env:TEMP\Setup-ServerSignatures.ps1" -ApiKey $k -PopulateOnly'
        ])

        <p class="fw-semibold small mb-1 mt-3"><i class="bi bi-person-plus me-1 text-muted"></i>Add one specific user by hand</p>
        <p class="text-muted small mb-2">In an already-connected Exchange session (<code>Connect-ExchangeOnline</code>), add a single mailbox to the right group:</p>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-2" style="font-size:12px;">
                <thead class="text-muted"><tr><th>Scope</th><th>Domain</th><th>Group</th></tr></thead>
                <tbody>
                @foreach($groups as $g)
                    <tr>
                        <td>{{ $g['label'] }}</td>
                        <td class="font-monospace">{{ $g['domain'] }}</td>
                        <td class="font-monospace">{{ $g['group'] }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @include('admin.signatures.partials.cmd', ['id' => 'usrOne', 'cmd' =>
            'Add-DistributionGroupMember -Identity "SG-Signature-Male" -Member "firstname.lastname@samirgroup.com"'
        ])
        <div class="small text-muted mt-2">
            <i class="bi bi-info-circle me-1"></i>Swap the group for the one matching the user's domain/gender above.
            Male group also covers unknown-gender users.
        </div>
    </div>
</div>

{{-- ═══════════ 3. Test ═══════════ --}}
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-transparent py-3 d-flex align-items-center">
        <span class="badge rounded-pill bg-info text-dark me-2">3</span>
        <span class="fw-semibold"><i class="bi bi-clipboard-check me-1 text-info"></i>Test</span>
        <span class="ms-auto text-muted small">Verify a device install or the API output</span>
    </div>
    <div class="card-body">
        <p class="fw-semibold small mb-1"><i class="bi bi-pc-display me-1 text-info"></i>Full clean-slate test on a device</p>
        <p class="text-muted small mb-2">Wipes existing signatures, reinstalls per account, and verifies — for a controlled test on one PC.</p>
        @include('admin.signatures.partials.cmd', ['id' => 'testDevice', 'cmd' =>
            'Set-ExecutionPolicy -Scope Process Bypass -Force; $k = \'__KEY__\'; irm "'.$baseUrl.'/signature/deploy.ps1?file=test&api_key=$k" -OutFile "$env:TEMP\Test-Signature.ps1"; & "$env:TEMP\Test-Signature.ps1"'
        ])

        <p class="fw-semibold small mb-1 mt-3"><i class="bi bi-braces me-1 text-muted"></i>Check the raw signature NOC returns for a user</p>
        <p class="text-muted small mb-2">Replace the UPN with the person you want to check — returns the exact HTML NOC serves for them.</p>
        @include('admin.signatures.partials.cmd', ['id' => 'testApi', 'cmd' =>
            '$k = \'__KEY__\'; irm "'.$baseUrl.'/api/signature?upn=firstname.lastname@samirgroup.com&api_key=$k"'
        ])
    </div>
</div>

{{-- ═══════════ 4. Force-apply on employee device ═══════════ --}}
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-transparent py-3 d-flex align-items-center">
        <span class="badge rounded-pill bg-danger me-2">4</span>
        <span class="fw-semibold"><i class="bi bi-download me-1 text-danger"></i>Force-apply on an employee device</span>
        <span class="ms-auto text-muted small">Classic Outlook — per-account install now</span>
    </div>
    <div class="card-body">
        <p class="text-muted small">
            Run this <strong>on the employee's PC</strong> (in their Windows session) to install the correct signature for every
            Outlook account immediately, without waiting for the Intune schedule. Also registers the daily self-refresh task.
        </p>

        <p class="fw-semibold small mb-1"><i class="bi bi-lightning-charge me-1 text-danger"></i>Install now (all accounts)</p>
        @include('admin.signatures.partials.cmd', ['id' => 'devApply', 'cmd' =>
            'Set-ExecutionPolicy -Scope Process Bypass -Force; $k = \'__KEY__\'; irm "'.$baseUrl.'/signature/deploy.ps1?api_key=$k" -OutFile "$env:TEMP\Deploy-Signature.ps1"; & "$env:TEMP\Deploy-Signature.ps1"'
        ])

        <p class="fw-semibold small mb-1 mt-3"><i class="bi bi-trash me-1 text-muted"></i>Remove the NOC-managed signature from a device</p>
        @include('admin.signatures.partials.cmd', ['id' => 'devRemove', 'cmd' =>
            'Set-ExecutionPolicy -Scope Process Bypass -Force; $k = \'__KEY__\'; irm "'.$baseUrl.'/signature/deploy.ps1?api_key=$k" -OutFile "$env:TEMP\Deploy-Signature.ps1"; & "$env:TEMP\Deploy-Signature.ps1" -RemoveClientSignature'
        ])
        <div class="small text-muted mt-2">
            <i class="bi bi-info-circle me-1"></i>Close Outlook before running so the new signature loads on next launch.
            This is the same script Intune deploys — running it by hand just applies it early.
        </div>
    </div>
</div>

@push('scripts')
<script>
(function () {
    const keyInput = document.getElementById('apiKey');
    const toggle   = document.getElementById('toggleKey');
    const warn     = document.getElementById('keyWarn');
    const STORE    = 'sg-sig-cmd-key'; // sessionStorage only — cleared when the tab closes

    // Every command carries its template in data-tpl with the __KEY__ placeholder.
    const blocks = Array.from(document.querySelectorAll('[data-cmd]'));

    function fullKey() {
        const v = keyInput.value.trim();
        if (!v) return '';
        return v.startsWith('hrk_') ? v : ('hrk_' + v);
    }

    function refresh() {
        const k = fullKey();
        const has = k.length > 4;
        warn.classList.toggle('d-none', has);
        blocks.forEach(b => {
            const tpl = b.getAttribute('data-tpl');
            const code = b.querySelector('code');
            code.textContent = tpl.replaceAll('__KEY__', has ? k : '<PASTE-API-KEY>');
        });
    }

    // Persist within the tab session so a refresh doesn't lose it (never to disk).
    try {
        const saved = sessionStorage.getItem(STORE);
        if (saved) keyInput.value = saved;
    } catch (e) {}

    keyInput.addEventListener('input', () => {
        try { sessionStorage.setItem(STORE, keyInput.value.trim()); } catch (e) {}
        refresh();
    });

    toggle.addEventListener('click', () => {
        const showing = keyInput.type === 'text';
        keyInput.type = showing ? 'password' : 'text';
        toggle.querySelector('i').className = showing ? 'bi bi-eye-slash' : 'bi bi-eye';
    });

    // Copy buttons
    document.querySelectorAll('[data-copy]').forEach(btn => {
        btn.addEventListener('click', () => {
            const target = document.getElementById(btn.getAttribute('data-copy'));
            const text = target.querySelector('code').textContent;
            if (text.includes('<PASTE-API-KEY>')) {
                btn.innerHTML = '<i class="bi bi-exclamation-triangle me-1"></i>Add key first';
                setTimeout(() => { btn.innerHTML = '<i class="bi bi-clipboard me-1"></i>Copy'; }, 1500);
                keyInput.focus();
                return;
            }
            navigator.clipboard.writeText(text).then(() => {
                btn.innerHTML = '<i class="bi bi-check2 me-1"></i>Copied';
                setTimeout(() => { btn.innerHTML = '<i class="bi bi-clipboard me-1"></i>Copy'; }, 1500);
            });
        });
    });

    refresh();
})();
</script>
@endpush

@endsection
