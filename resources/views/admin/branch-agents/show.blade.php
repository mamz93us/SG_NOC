@extends('layouts.admin')

@section('title', "Branch Agent · {$agent->code}")

@php
    $installUrl = rtrim(config('app.url'), '/') . '/branch-agent/install.sh';
@endphp

@section('content')
<div class="container-fluid py-3">

    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h4 class="mb-0">{{ $agent->name }} <code class="ms-1">{{ $agent->code }}</code></h4>
            <small class="text-muted">{{ $agent->fqdn() ?? 'no DDNS name configured' }}</small>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('admin.branch-agents.edit', $agent) }}" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-pencil me-1"></i>Edit
            </a>
            <form action="{{ route('admin.branch-agents.destroy', $agent) }}" method="POST"
                  onsubmit="return confirm('Remove branch agent {{ $agent->code }}? The VM itself is untouched.');">
                @csrf @method('DELETE')
                <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
            </form>
        </div>
    </div>

    @if(session('success'))
        <div class="alert alert-success alert-dismissible fade show py-2">
            {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    {{-- Enrollment / linking --}}
    <div class="card mb-3 border-info">
        <div class="card-header bg-info-subtle d-flex justify-content-between align-items-center">
            <strong><i class="bi bi-link-45deg me-1"></i>Link this agent to the NOC</strong>
            <form action="{{ route('admin.branch-agents.regenerate-code', $agent) }}" method="POST">
                @csrf
                <button class="btn btn-sm btn-outline-info">
                    <i class="bi bi-arrow-repeat me-1"></i>New enrollment code
                </button>
            </form>
        </div>
        <div class="card-body">
            @if($agent->enrollmentPending())
                <p class="mb-2">On a fresh <strong>Ubuntu 24.04</strong> VM, run:</p>
                <pre class="bg-dark text-light p-2 rounded small mb-3"><code>curl -fsSL {{ $installUrl }} | sudo bash</code></pre>
                <p class="mb-1">Then open the printed <code>http://&lt;branch-ip&gt;:8080</code> and enter this enrollment code in the setup wizard:</p>
                <div class="d-flex align-items-center gap-3">
                    <span class="display-6 font-monospace">{{ $agent->enrollment_code }}</span>
                    <span class="text-muted small">expires {{ $agent->enrollment_expires_at->diffForHumans() }}</span>
                </div>
            @elseif($agent->api_token)
                <p class="mb-2 text-success"><i class="bi bi-check-circle-fill me-1"></i>This agent is enrolled and has a live token.</p>
                <form action="{{ route('admin.branch-agents.revoke-token', $agent) }}" method="POST"
                      onsubmit="return confirm('Revoke the token? The agent loses NOC access and log search until it re-enrolls.');">
                    @csrf
                    <button class="btn btn-sm btn-outline-danger"><i class="bi bi-x-octagon me-1"></i>Revoke token</button>
                </form>
            @else
                <p class="mb-2 text-muted">Not linked and no active enrollment code.</p>
                <form action="{{ route('admin.branch-agents.regenerate-code', $agent) }}" method="POST">
                    @csrf
                    <button class="btn btn-sm btn-info"><i class="bi bi-key me-1"></i>Generate enrollment code</button>
                </form>
            @endif
        </div>
    </div>

    <div class="row g-3">
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-header"><strong>Agent</strong></div>
                <div class="card-body small">
                    <dl class="row mb-0">
                        <dt class="col-5">Status</dt><dd class="col-7">{{ $agent->computeStatus() }}</dd>
                        <dt class="col-5">Version</dt><dd class="col-7 font-monospace">{{ $agent->agent_version ?? '—' }}</dd>
                        <dt class="col-5">Hostname</dt><dd class="col-7 font-monospace">{{ $agent->hostname ?? '—' }}:{{ $agent->port }}</dd>
                        <dt class="col-5">Last heartbeat</dt><dd class="col-7">{{ $agent->last_heartbeat_at?->diffForHumans() ?? 'never' }}</dd>
                        <dt class="col-5">Enabled</dt><dd class="col-7">{{ $agent->enabled ? 'yes' : 'no' }}</dd>
                    </dl>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-header"><strong>DDNS &amp; links</strong></div>
                <div class="card-body small">
                    <dl class="row mb-0">
                        <dt class="col-5">FQDN</dt><dd class="col-7 font-monospace">{{ $agent->fqdn() ?? '—' }}</dd>
                        <dt class="col-5">Current WAN IP</dt><dd class="col-7 font-monospace">{{ $agent->wan_ip ?? '—' }}</dd>
                        <dt class="col-5">Updated</dt><dd class="col-7">{{ $agent->wan_ip_updated_at?->diffForHumans() ?? '—' }}</dd>
                        <dt class="col-5">GoDaddy acct</dt><dd class="col-7">{{ $agent->dnsAccount?->label ?? '—' }}</dd>
                        <dt class="col-5">VPN tunnel</dt><dd class="col-7">{{ $agent->vpnTunnel?->name ?? '—' }}</dd>
                    </dl>
                </div>
            </div>
        </div>
    </div>

    {{-- Last health snapshot --}}
    @if($agent->last_health)
        <div class="card mt-3">
            <div class="card-header"><strong>Last health snapshot</strong></div>
            <div class="card-body">
                <pre class="small mb-0">{{ json_encode($agent->last_health, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
            </div>
        </div>
    @endif

    {{-- WAN IP history --}}
    <div class="card mt-3">
        <div class="card-header"><strong>WAN IP history</strong></div>
        <div class="card-body p-0">
            <table class="table table-sm mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Changed</th><th>IP</th><th>Previous</th>
                        <th>DNS</th><th>Tunnel</th><th>Note</th>
                    </tr>
                </thead>
                <tbody>
                @forelse($history as $h)
                    <tr>
                        <td class="small text-muted">{{ $h->changed_at?->diffForHumans() }}</td>
                        <td class="font-monospace">{{ $h->ip }}</td>
                        <td class="font-monospace text-muted">{{ $h->previous_ip ?? '—' }}</td>
                        <td>@if($h->applied_dns)<span class="badge bg-success">ok</span>@else<span class="badge bg-secondary">—</span>@endif</td>
                        <td>@if($h->applied_tunnel)<span class="badge bg-success">ok</span>@else<span class="badge bg-secondary">—</span>@endif</td>
                        <td class="small text-danger">{{ $h->note }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-center text-muted py-4">No WAN IP changes recorded yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
