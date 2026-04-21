@extends('layouts.admin')
@section('title', 'Switch QoS Setup: ' . $device->name)

@section('content')
<div class="mb-3">
    <a href="{{ route('admin.switch-qos.dashboard') }}" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i>Back to Dashboard
    </a>
</div>

@if(session('success'))
<div class="alert alert-success alert-dismissible fade show">{{ session('success') }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
@endif
@if(session('error'))
<div class="alert alert-warning alert-dismissible fade show">{{ session('error') }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
@endif

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold">
            <i class="bi {{ $device->type === 'router' ? 'bi-router' : 'bi-hdd-network' }} me-2 text-primary"></i>{{ $device->name }}
        </h4>
        <small class="text-muted font-monospace">{{ $device->ip_address }} <span class="ms-2 text-secondary">{{ $device->branch?->name }}</span></small>
    </div>
    <div class="d-flex gap-2 align-items-center">
        <a href="telnet://{{ $device->ip_address }}" class="btn btn-sm btn-dark" title="Open telnet session to {{ $device->ip_address }}">
            <i class="bi bi-terminal me-1"></i>Open Telnet
        </a>
        @can('manage-credentials')
        <form method="POST" action="{{ route('admin.switch-qos.poll', $device->id) }}" class="d-inline">
            @csrf
            <button type="submit" class="btn btn-sm btn-success" title="Run the poller now">
                <i class="bi bi-play-fill me-1"></i>Poll Now
            </button>
        </form>
        <form method="POST" action="{{ route('admin.switch-qos.clear', $device->id) }}" class="d-inline"
              onsubmit="return confirm('Reset all MLS QoS counters on the switch?\n\nThis runs `clear mls qos interface statistics` on the device — cumulative drop counters will start from zero after the next poll.');">
            @csrf
            <button type="submit" class="btn btn-sm btn-outline-danger" title="Clear counters on the switch">
                <i class="bi bi-eraser me-1"></i>Clear Stats
            </button>
        </form>
        @endcan
        @if($lastPoll)
        <a href="{{ route('admin.switch-qos.device', urlencode($device->ip_address)) }}" class="btn btn-sm btn-outline-primary">
            <i class="bi bi-eye me-1"></i>View QoS Data
        </a>
        @endif
    </div>
</div>

<div class="row g-3">
    {{-- Capability card --}}
    <div class="col-lg-5">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-transparent fw-semibold d-flex justify-content-between align-items-center">
                <span><i class="bi bi-patch-check me-1"></i>Device Capability</span>
                @can('manage-credentials')
                <form method="POST" action="{{ route('admin.switch-qos.test', $device->id) }}" class="d-inline">
                    @csrf
                    <button type="submit" class="btn btn-sm btn-outline-primary" title="Probe telnet + MLS QoS support now">
                        <i class="bi bi-broadcast me-1"></i>Test Now
                    </button>
                </form>
                @endcan
            </div>
            <div class="card-body">
                <table class="table table-sm borderless mb-0">
                    <tbody>
                        <tr>
                            <td class="text-muted" style="width:45%">Telnet reachable</td>
                            <td>
                                @if($device->telnet_reachable === true)
                                    <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Yes</span>
                                @elseif($device->telnet_reachable === false)
                                    <span class="badge bg-danger"><i class="bi bi-x-circle me-1"></i>No</span>
                                @else
                                    <span class="badge bg-secondary">Unknown</span>
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <td class="text-muted">MLS QoS supported</td>
                            <td>
                                @if($device->mls_qos_supported === true)
                                    <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Supported</span>
                                @elseif($device->mls_qos_supported === false)
                                    <span class="badge bg-warning text-dark"><i class="bi bi-slash-circle me-1"></i>Not supported</span>
                                @else
                                    <span class="badge bg-secondary">Unknown</span>
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <td class="text-muted">Has telnet credential</td>
                            <td>
                                @if($telnetCred)
                                    <span class="badge bg-success"><i class="bi bi-key me-1"></i>Set</span>
                                @else
                                    <span class="badge bg-danger"><i class="bi bi-exclamation-triangle me-1"></i>Missing</span>
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <td class="text-muted">Has enable credential</td>
                            <td>
                                @if($enableCred)
                                    <span class="badge bg-success"><i class="bi bi-key me-1"></i>Set</span>
                                @else
                                    <span class="badge bg-secondary">Not set</span>
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <td class="text-muted">Last probed</td>
                            <td class="small">{{ $device->qos_probed_at?->diffForHumans() ?? '— never —' }}</td>
                        </tr>
                        <tr>
                            <td class="text-muted">Last poll</td>
                            <td class="small">{{ $lastPoll?->polled_at?->diffForHumans() ?? '— never polled —' }}</td>
                        </tr>
                        @if($device->qos_probe_error)
                        <tr>
                            <td colspan="2">
                                <div class="alert alert-warning py-2 small mb-0">
                                    <i class="bi bi-exclamation-triangle me-1"></i>{{ $device->qos_probe_error }}
                                </div>
                            </td>
                        </tr>
                        @endif
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- Credentials card --}}
    <div class="col-lg-7">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-transparent fw-semibold">
                <i class="bi bi-shield-lock me-1"></i>Telnet Credentials
                <small class="text-muted fw-normal ms-2">stored encrypted (AES via APP_KEY)</small>
            </div>
            <div class="card-body">
                @cannot('manage-credentials')
                    <div class="text-muted small">
                        <i class="bi bi-lock me-1"></i>You don't have the <code>manage-credentials</code> permission.
                    </div>
                    <div class="mt-2">
                        Telnet: {!! $telnetCred ? '<span class="badge bg-success">Set</span>' : '<span class="badge bg-danger">Missing</span>' !!}
                        &nbsp; Enable: {!! $enableCred ? '<span class="badge bg-success">Set</span>' : '<span class="badge bg-secondary">Not set</span>' !!}
                    </div>
                @else
                    {{-- Telnet credential row --}}
                    <form method="POST" action="{{ route('admin.switch-qos.credentials.save', $device->id) }}" class="row g-2 align-items-end mb-3">
                        @csrf
                        <input type="hidden" name="category" value="telnet">
                        <div class="col-md-5">
                            <label class="form-label small text-muted mb-1">Telnet (vty) password</label>
                            <div class="input-group input-group-sm">
                                <input type="password" name="password" class="form-control" placeholder="{{ $telnetCred ? '•••••••• (set)' : 'not set' }}" autocomplete="new-password" required>
                                <button type="button" class="btn btn-outline-secondary toggle-pw" tabindex="-1"><i class="bi bi-eye"></i></button>
                            </div>
                        </div>
                        <div class="col-auto">
                            <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-save me-1"></i>{{ $telnetCred ? 'Update' : 'Save' }}</button>
                        </div>
                        @if($telnetCred)
                        <div class="col-auto">
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#revealTelnet">
                                <i class="bi bi-eye me-1"></i>Reveal
                            </button>
                        </div>
                        @endif
                    </form>
                    @if($telnetCred)
                    <form method="POST" action="{{ route('admin.switch-qos.credentials.delete', [$device->id, $telnetCred->id]) }}" class="d-inline mb-4" onsubmit="return confirm('Remove telnet credential?')">
                        @csrf @method('DELETE')
                        <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash me-1"></i>Remove telnet</button>
                    </form>
                    @endif

                    <hr class="my-3">

                    {{-- Enable credential row --}}
                    <form method="POST" action="{{ route('admin.switch-qos.credentials.save', $device->id) }}" class="row g-2 align-items-end">
                        @csrf
                        <input type="hidden" name="category" value="enable">
                        <div class="col-md-5">
                            <label class="form-label small text-muted mb-1">Enable secret</label>
                            <div class="input-group input-group-sm">
                                <input type="password" name="password" class="form-control" placeholder="{{ $enableCred ? '•••••••• (set)' : 'not set' }}" autocomplete="new-password" required>
                                <button type="button" class="btn btn-outline-secondary toggle-pw" tabindex="-1"><i class="bi bi-eye"></i></button>
                            </div>
                        </div>
                        <div class="col-auto">
                            <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-save me-1"></i>{{ $enableCred ? 'Update' : 'Save' }}</button>
                        </div>
                        @if($enableCred)
                        <div class="col-auto">
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#revealEnable">
                                <i class="bi bi-eye me-1"></i>Reveal
                            </button>
                        </div>
                        @endif
                    </form>
                    @if($enableCred)
                    <form method="POST" action="{{ route('admin.switch-qos.credentials.delete', [$device->id, $enableCred->id]) }}" class="d-inline mt-2" onsubmit="return confirm('Remove enable credential?')">
                        @csrf @method('DELETE')
                        <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash me-1"></i>Remove enable</button>
                    </form>
                    @endif

                    {{-- Reveal modals --}}
                    @if($telnetCred)
                    <div class="modal fade" id="revealTelnet" tabindex="-1">
                        <div class="modal-dialog modal-sm"><div class="modal-content">
                            <div class="modal-header"><h6 class="modal-title">Telnet password</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                            <div class="modal-body font-monospace small text-break">{{ $telnetCred->password }}</div>
                        </div></div>
                    </div>
                    @endif
                    @if($enableCred)
                    <div class="modal fade" id="revealEnable" tabindex="-1">
                        <div class="modal-dialog modal-sm"><div class="modal-content">
                            <div class="modal-header"><h6 class="modal-title">Enable secret</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                            <div class="modal-body font-monospace small text-break">{{ $enableCred->password }}</div>
                        </div></div>
                    </div>
                    @endif
                @endcannot
            </div>
        </div>
    </div>
</div>

<div class="alert alert-info small mt-4">
    <i class="bi bi-info-circle me-1"></i>
    After setting credentials, click <strong>Test Now</strong> to verify the switch is reachable and MLS QoS is supported.
    The scheduler polls every 5 minutes — or you can run <code>php artisan switch:poll-mls-qos --device={{ $device->ip_address }}</code> manually.
</div>
@endsection

@push('scripts')
<script>
document.querySelectorAll('.toggle-pw').forEach(btn => {
    btn.addEventListener('click', () => {
        const input = btn.parentElement.querySelector('input');
        const icon  = btn.querySelector('i');
        if (input.type === 'password') {
            input.type = 'text';
            icon.classList.replace('bi-eye', 'bi-eye-slash');
        } else {
            input.type = 'password';
            icon.classList.replace('bi-eye-slash', 'bi-eye');
        }
    });
});
</script>
@endpush
