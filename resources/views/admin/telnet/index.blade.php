@extends('layouts.admin')

@section('title', 'Telnet Client')

@section('content')
<div class="container-fluid py-4">

    {{-- ── Header ── --}}
    <div class="d-flex align-items-center justify-content-between mb-4">
        <div>
            <h4 class="mb-0 fw-bold">
                <i class="bi bi-terminal-fill me-2 text-success"></i>Telnet Client
            </h4>
            <p class="text-muted small mb-0">Connect to network devices, printers, and routers via Telnet or SSH</p>
        </div>
    </div>

    @if(session('error'))
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="bi bi-exclamation-triangle-fill me-2"></i>{{ session('error') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    @endif

    <div class="row g-4">

        {{-- ── Quick Connect form ─────────────────────────────────────── --}}
        <div class="col-12 col-xl-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-dark text-white d-flex align-items-center gap-2">
                    <i class="bi bi-plug-fill text-success"></i>
                    <span class="fw-semibold">Quick Connect</span>
                </div>
                <div class="card-body" x-data="quickConnect()">
                    <form method="POST" action="{{ route('admin.telnet.connect') }}">
                        @csrf

                        {{-- Protocol toggle --}}
                        <div class="mb-3">
                            <label class="form-label fw-semibold small">Protocol</label>
                            <div class="btn-group w-100" role="group">
                                <input type="radio" class="btn-check" name="protocol" id="proto-telnet"
                                       value="telnet" x-model="protocol">
                                <label class="btn btn-outline-success btn-sm" for="proto-telnet">
                                    <i class="bi bi-terminal me-1"></i>Telnet
                                </label>
                                <input type="radio" class="btn-check" name="protocol" id="proto-ssh"
                                       value="ssh" x-model="protocol">
                                <label class="btn btn-outline-info btn-sm" for="proto-ssh">
                                    <i class="bi bi-shield-lock me-1"></i>SSH
                                </label>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold small">Host / IP Address <span class="text-danger">*</span></label>
                            <input type="text" name="host" class="form-control @error('host') is-invalid @enderror"
                                   placeholder="192.168.1.1 or switch.local"
                                   value="{{ old('host') }}" required autofocus>
                            @error('host')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold small">Port</label>
                            <input type="number" name="port" class="form-control"
                                   :value="protocol === 'ssh' ? 22 : 23"
                                   min="1" max="65535">
                            <div class="form-text" x-text="protocol === 'ssh' ? 'Default: 22 (SSH)' : 'Default: 23 (Telnet)'"></div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold small">Label <small class="text-muted">(optional)</small></label>
                            <input type="text" name="label" class="form-control"
                                   placeholder="e.g. Core Switch - Main Branch"
                                   value="{{ old('label') }}" maxlength="150">
                        </div>
                        <hr>
                        <p class="small text-muted mb-2">
                            <i class="bi bi-shield-lock me-1"></i>Credentials are sent securely and never stored.
                        </p>
                        <div class="mb-3">
                            <label class="form-label fw-semibold small">
                                Username
                                <small class="text-muted" x-text="protocol === 'ssh' ? '(required for SSH)' : '(optional)'"></small>
                            </label>
                            <input type="text" name="username" class="form-control"
                                   placeholder="admin" autocomplete="off"
                                   :required="protocol === 'ssh'">
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold small">Password <small class="text-muted">(optional)</small></label>
                            <input type="password" name="password" class="form-control" autocomplete="new-password">
                        </div>
                        <div class="d-grid mt-4">
                            <button type="submit" class="btn"
                                    :class="protocol === 'ssh' ? 'btn-info' : 'btn-success'">
                                <i class="bi me-2" :class="protocol === 'ssh' ? 'bi-shield-lock' : 'bi-terminal'"></i>
                                <span x-text="protocol === 'ssh' ? 'Open SSH Session' : 'Open Telnet Session'">Open Terminal</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        {{-- ── Device lists ─────────────────────────────────────────────── --}}
        <div class="col-12 col-xl-8">
            <div class="row g-4 h-100">

                {{-- Switches --}}
                <div class="col-12 col-md-6">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-header bg-dark text-white d-flex align-items-center justify-content-between">
                            <span class="fw-semibold"><i class="bi bi-diagram-3-fill me-2 text-info"></i>Switches</span>
                            <span class="badge bg-secondary">{{ $switches->count() }}</span>
                        </div>
                        <div class="card-body p-0">
                            @if($switches->isEmpty())
                            <p class="text-muted text-center py-4 small">No switches with IPs found.</p>
                            @else
                            <div class="list-group list-group-flush" style="max-height:480px;overflow-y:auto;">
                                @foreach($switches as $sw)
                                <div class="list-group-item list-group-item-action p-3 d-flex align-items-center gap-3">
                                    <div class="flex-grow-1 min-w-0">
                                        <div class="fw-semibold text-truncate small">{{ $sw->name }}</div>
                                        <div class="text-muted font-monospace" style="font-size:.75rem">{{ $sw->lan_ip }}</div>
                                        @if($sw->model)
                                        <div class="text-muted" style="font-size:.7rem">{{ $sw->model }}</div>
                                        @endif
                                    </div>
                                    <div class="d-flex gap-1 flex-shrink-0">
                                        <form method="POST" action="{{ route('admin.telnet.connect') }}">
                                            @csrf
                                            <input type="hidden" name="host"     value="{{ $sw->lan_ip }}">
                                            <input type="hidden" name="port"     value="23">
                                            <input type="hidden" name="protocol" value="telnet">
                                            <input type="hidden" name="label"    value="{{ $sw->name }}">
                                            <button type="submit" class="btn btn-sm btn-outline-success" title="Telnet">
                                                <i class="bi bi-terminal"></i>
                                            </button>
                                        </form>
                                        <form method="POST" action="{{ route('admin.telnet.connect') }}">
                                            @csrf
                                            <input type="hidden" name="host"     value="{{ $sw->lan_ip }}">
                                            <input type="hidden" name="port"     value="22">
                                            <input type="hidden" name="protocol" value="ssh">
                                            <input type="hidden" name="label"    value="{{ $sw->name }}">
                                            <button type="submit" class="btn btn-sm btn-outline-info" title="SSH">
                                                <i class="bi bi-shield-lock"></i>
                                            </button>
                                        </form>
                                    </div>
                                </div>
                                @endforeach
                            </div>
                            @endif
                        </div>
                    </div>
                </div>

                {{-- Printers --}}
                <div class="col-12 col-md-6">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-header bg-dark text-white d-flex align-items-center justify-content-between">
                            <span class="fw-semibold"><i class="bi bi-printer-fill me-2 text-warning"></i>Printers</span>
                            <span class="badge bg-secondary">{{ $printers->count() }}</span>
                        </div>
                        <div class="card-body p-0">
                            @if($printers->isEmpty())
                            <p class="text-muted text-center py-4 small">No printers with IPs found.</p>
                            @else
                            <div class="list-group list-group-flush" style="max-height:480px;overflow-y:auto;">
                                @foreach($printers as $p)
                                <div class="list-group-item list-group-item-action p-3 d-flex align-items-center gap-3">
                                    <div class="flex-grow-1 min-w-0">
                                        <div class="fw-semibold text-truncate small">{{ $p->printer_name }}</div>
                                        <div class="text-muted font-monospace" style="font-size:.75rem">{{ $p->ip_address }}</div>
                                    </div>
                                    <div class="d-flex gap-1 flex-shrink-0">
                                        <form method="POST" action="{{ route('admin.telnet.connect') }}">
                                            @csrf
                                            <input type="hidden" name="host"     value="{{ $p->ip_address }}">
                                            <input type="hidden" name="port"     value="23">
                                            <input type="hidden" name="protocol" value="telnet">
                                            <input type="hidden" name="label"    value="{{ $p->printer_name }}">
                                            <button type="submit" class="btn btn-sm btn-outline-success" title="Telnet">
                                                <i class="bi bi-terminal"></i>
                                            </button>
                                        </form>
                                        <form method="POST" action="{{ route('admin.telnet.connect') }}">
                                            @csrf
                                            <input type="hidden" name="host"     value="{{ $p->ip_address }}">
                                            <input type="hidden" name="port"     value="22">
                                            <input type="hidden" name="protocol" value="ssh">
                                            <input type="hidden" name="label"    value="{{ $p->printer_name }}">
                                            <button type="submit" class="btn btn-sm btn-outline-info" title="SSH">
                                                <i class="bi bi-shield-lock"></i>
                                            </button>
                                        </form>
                                    </div>
                                </div>
                                @endforeach
                            </div>
                            @endif
                        </div>
                    </div>
                </div>

            </div>
        </div>

    </div>{{-- /row --}}

</div>
@endsection

@push('scripts')
<script>
function quickConnect() {
    return {
        protocol: '{{ old('protocol', 'telnet') }}',
    };
}
</script>
@endpush
