{{--
    Device Access Panel — SSH Sessions + Access Logs
    Included in: resources/views/admin/devices/show.blade.php
    Variables expected: $device (with sshSessions + accessLogs already loaded)
--}}

{{-- ── Quick Access ── --}}
@if($device->ip_address)
<div class="card shadow-sm mb-3">
    <div class="card-header py-2 d-flex align-items-center gap-2">
        <h6 class="mb-0 fw-semibold"><i class="bi bi-terminal me-2"></i>Quick Access</h6>
        @if($device->ip_address)
        <span class="ms-auto badge bg-secondary font-monospace" style="font-size:.7rem">{{ $device->ip_address }}</span>
        @endif
    </div>
    <div class="card-body py-2">
        <div class="d-flex flex-wrap gap-2">

            {{-- SSH (goes through DeviceSshController for session logging) --}}
            @can('manage-devices')
            <a href="{{ route('admin.devices.ssh.connect', $device) }}"
               class="btn btn-sm btn-outline-info">
                <i class="bi bi-shield-lock-fill me-1"></i>SSH
                @if($device->ssh_port && $device->ssh_port != 22)
                <span class="badge bg-info bg-opacity-20 text-info ms-1" style="font-size:.65rem">:{{ $device->ssh_port }}</span>
                @endif
            </a>
            @endcan

            {{-- Telnet (uses existing TelnetController — requires view-noc) --}}
            @can('view-noc')
            <form method="POST" action="{{ route('admin.telnet.connect') }}" class="d-inline">
                @csrf
                <input type="hidden" name="host"     value="{{ $device->ip_address }}">
                <input type="hidden" name="port"     value="23">
                <input type="hidden" name="protocol" value="telnet">
                <input type="hidden" name="label"    value="{{ $device->name }}">
                <button type="submit" class="btn btn-sm btn-outline-success">
                    <i class="bi bi-terminal-fill me-1"></i>Telnet
                </button>
            </form>
            @endcan

            {{-- SSH via TelnetController (quick-connect, no session logging) --}}
            @can('view-noc')
            <form method="POST" action="{{ route('admin.telnet.connect') }}" class="d-inline">
                @csrf
                <input type="hidden" name="host"     value="{{ $device->ip_address }}">
                <input type="hidden" name="port"     value="{{ $device->ssh_port ?? 22 }}">
                <input type="hidden" name="protocol" value="ssh">
                <input type="hidden" name="label"    value="{{ $device->name }}">
                @if($device->ssh_username)
                <input type="hidden" name="username" value="{{ $device->ssh_username }}">
                @endif
                <button type="submit" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-terminal me-1"></i>SSH (quick)
                </button>
            </form>
            @endcan

            {{-- Web Browser — opens browser pre-filled with device IP --}}
            @can('view-noc')
            <div class="dropdown d-inline">
                <button class="btn btn-sm btn-outline-primary dropdown-toggle"
                        data-bs-toggle="dropdown" data-bs-auto-close="outside"
                        aria-expanded="false">
                    <i class="bi bi-globe me-1"></i>Web Browser
                </button>
                <div class="dropdown-menu p-3 shadow" style="min-width:260px">
                    <div class="mb-2">
                        <label class="form-label small fw-semibold mb-1">Protocol</label>
                        <div class="btn-group btn-group-sm w-100" id="wb-proto-{{ $device->id }}">
                            <input type="radio" class="btn-check" name="wb-proto-{{ $device->id }}"
                                   id="wb-http-{{ $device->id }}" value="http" checked>
                            <label class="btn btn-outline-secondary" for="wb-http-{{ $device->id }}">http://</label>

                            <input type="radio" class="btn-check" name="wb-proto-{{ $device->id }}"
                                   id="wb-https-{{ $device->id }}" value="https">
                            <label class="btn btn-outline-secondary" for="wb-https-{{ $device->id }}">https://</label>
                        </div>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-semibold mb-1">Port</label>
                        <input type="number" class="form-control form-control-sm wb-port-input"
                               id="wb-port-{{ $device->id }}"
                               value="{{ $device->web_port ?? 80 }}" min="1" max="65535">
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold mb-1">Path</label>
                        <input type="text" class="form-control form-control-sm wb-path-input"
                               id="wb-path-{{ $device->id }}"
                               value="{{ $device->web_path ?? '/' }}" placeholder="/">
                    </div>
                    <button class="btn btn-primary btn-sm w-100"
                            onclick="openWebBrowser('{{ $device->id }}', '{{ $device->ip_address }}')">
                        <i class="bi bi-arrow-right-circle-fill me-1"></i>Open
                    </button>
                </div>
            </div>
            @endcan

        </div>
    </div>
</div>
@endif

{{-- ── SSH Sessions ── --}}
@if($sshSessions->isNotEmpty())
<div class="card shadow-sm mb-3">
    <div class="card-header py-2 d-flex align-items-center">
        <h6 class="mb-0 fw-semibold"><i class="bi bi-shield-lock me-2"></i>SSH Sessions</h6>
        <span class="ms-auto badge bg-secondary">{{ $sshSessions->count() }}</span>
    </div>
    <div class="card-body p-0">
        <table class="table table-sm table-hover align-middle mb-0 small">
            <thead class="table-light">
                <tr>
                    <th class="ps-3">User</th>
                    <th>Login</th>
                    <th>Duration</th>
                    <th>Status</th>
                    <th>IP</th>
                </tr>
            </thead>
            <tbody>
                @foreach($sshSessions as $sess)
                <tr>
                    <td class="ps-3 fw-semibold">{{ $sess->user?->name ?? '—' }}</td>
                    <td class="text-muted">
                        {{ $sess->started_at?->format('d M Y H:i') ?? '—' }}
                        @if($sess->ssh_username)
                        <span class="font-monospace text-info ms-1">{{ $sess->ssh_username }}</span>
                        @endif
                    </td>
                    <td class="font-monospace">{{ $sess->durationLabel() }}</td>
                    <td>
                        <span class="badge {{ $sess->statusBadgeClass() }}">{{ ucfirst($sess->status) }}</span>
                    </td>
                    <td class="text-muted font-monospace">{{ $sess->client_ip ?? '—' }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endif

{{-- ── Access Log ── --}}
@if($accessLogs->isNotEmpty())
<div class="card shadow-sm mb-3">
    <div class="card-header py-2 d-flex align-items-center">
        <h6 class="mb-0 fw-semibold"><i class="bi bi-journal-text me-2"></i>Access Log</h6>
        <span class="ms-auto badge bg-secondary">{{ $accessLogs->count() }}</span>
    </div>
    <div class="card-body p-0">
        <div style="max-height:260px;overflow-y:auto">
            <table class="table table-sm table-hover align-middle mb-0 small">
                <thead class="table-light sticky-top" style="top:0">
                    <tr>
                        <th class="ps-3">Time</th>
                        <th>User</th>
                        <th>Type</th>
                        <th>Action</th>
                        <th>IP</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($accessLogs as $log)
                    <tr>
                        <td class="ps-3 text-muted" style="white-space:nowrap">
                            {{ $log->created_at->format('d M H:i') }}
                        </td>
                        <td class="fw-semibold">{{ $log->user?->name ?? '—' }}</td>
                        <td>
                            <span class="badge {{ $log->accessTypeBadgeClass() }}">
                                {{ strtoupper($log->access_type) }}
                            </span>
                        </td>
                        <td>{{ $log->actionLabel() }}</td>
                        <td class="text-muted font-monospace">{{ $log->client_ip ?? '—' }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
@endif
