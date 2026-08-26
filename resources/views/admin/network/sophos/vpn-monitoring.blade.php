@extends('layouts.admin')

@section('content')
@php
    use App\Services\Sophos\SophosVpnBoard;

    $tone = [
        SophosVpnBoard::STATUS_UP       => ['bg-success',            'Up'],
        SophosVpnBoard::STATUS_DOWN     => ['bg-danger',             'Down'],
        SophosVpnBoard::STATUS_DISABLED => ['bg-secondary',          'Disabled on firewall'],
        SophosVpnBoard::STATUS_MUTED    => ['bg-dark',               'Not monitored'],
        SophosVpnBoard::STATUS_UNKNOWN  => ['bg-warning text-dark',  'No reading'],
    ];
@endphp

<div class="d-flex align-items-center justify-content-between mb-1">
    <h4 class="mb-0"><i class="bi bi-shield-lock me-2"></i>VPN Monitoring &mdash; {{ $firewall->name }}</h4>
    <a href="{{ route('admin.network.sophos.show', $firewall) }}" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i>Back to firewall
    </a>
</div>
<p class="text-muted small mb-4">
    Choose which IPsec tunnels the NOC dashboard reports on.
    A tunnel switched off on the firewall is detected automatically &mdash; you only need
    to mute one here if it is enabled on the firewall but should not be raising alarms.
</p>

@if(session('success'))
    <div class="alert alert-success py-2">{{ session('success') }}</div>
@endif

@if(! $hasHost)
    <div class="alert alert-warning">
        <strong>No monitored host linked.</strong>
        Tunnel state comes from SNMP, so this firewall needs a linked monitored host
        before its tunnels can appear here.
    </div>
@else
    <div class="d-flex gap-2 mb-3 flex-wrap">
        <span class="badge bg-success">{{ $summary['up'] }} Up</span>
        @if($summary['down'] > 0)<span class="badge bg-danger">{{ $summary['down'] }} Down</span>@endif
        @if($summary['disabled'] > 0)<span class="badge bg-secondary">{{ $summary['disabled'] }} Disabled</span>@endif
        @if($summary['muted'] > 0)<span class="badge bg-dark">{{ $summary['muted'] }} Not monitored</span>@endif
        @if($summary['unknown'] > 0)<span class="badge bg-warning text-dark">{{ $summary['unknown'] }} No reading</span>@endif
        @if($summary['retired'] > 0)<span class="badge bg-light text-dark border">{{ $summary['retired'] }} Retired</span>@endif
    </div>

    <div class="card shadow-sm border-0">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">Tunnel</th>
                        <th>Status</th>
                        <th>Last reading</th>
                        <th class="text-center">Monitored</th>
                        <th class="text-end pe-3">&nbsp;</th>
                    </tr>
                </thead>
                <tbody>
                @forelse($tunnels as $t)
                    @php [$badge, $label] = $tone[$t['status']] ?? ['bg-secondary', ucfirst($t['status'])]; @endphp
                    <tr class="{{ $t['retired'] ? 'opacity-50' : '' }}">
                        <td class="ps-3">
                            <span class="fw-semibold">{{ $t['name'] }}</span>
                            @if($t['retired'])
                                <span class="badge bg-light text-dark border ms-1"
                                      title="No longer reported by the firewall since {{ $t['retired_at'] }}">retired</span>
                            @endif
                            <div class="small text-muted">{{ $t['branch'] }}</div>
                        </td>
                        <td><span class="badge {{ $badge }}">{{ $label }}</span></td>
                        <td class="small text-muted">{{ $t['last_checked'] }}</td>
                        <td class="text-center">
                            @can('manage-noc')
                                <form method="POST" action="{{ route('admin.network.sophos-vpn.toggle', $t['sensor_id']) }}"
                                      class="d-inline">
                                    @csrf
                                    <input type="hidden" name="monitor_enabled" value="{{ $t['monitor_enabled'] ? 0 : 1 }}">
                                    <button class="btn btn-sm {{ $t['monitor_enabled'] ? 'btn-outline-success' : 'btn-outline-secondary' }}"
                                            {{ $t['retired'] ? 'disabled' : '' }}
                                            title="{{ $t['monitor_enabled'] ? 'Stop monitoring this tunnel' : 'Start monitoring this tunnel' }}">
                                        <i class="bi {{ $t['monitor_enabled'] ? 'bi-toggle-on' : 'bi-toggle-off' }}"></i>
                                        {{ $t['monitor_enabled'] ? 'On' : 'Off' }}
                                    </button>
                                </form>
                            @else
                                <i class="bi {{ $t['monitor_enabled'] ? 'bi-toggle-on text-success' : 'bi-toggle-off text-muted' }}"></i>
                            @endcan
                        </td>
                        <td class="text-end pe-3">
                            {{-- Deleting is offered only for retired tunnels: one the firewall
                                 still reports would simply be recreated on the next discovery,
                                 and the only lasting effect would be losing its history. --}}
                            @if($t['retired'])
                                @can('manage-noc')
                                    <form method="POST" action="{{ route('admin.network.sophos-vpn.forget', $t['sensor_id']) }}"
                                          class="d-inline"
                                          onsubmit="return confirm('Remove {{ addslashes($t['name']) }} and its recorded history? This cannot be undone.')">
                                        @csrf @method('DELETE')
                                        <button class="btn btn-sm btn-outline-danger">
                                            <i class="bi bi-trash"></i> Remove
                                        </button>
                                    </form>
                                @endcan
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-center py-4 text-muted">
                        No VPN tunnels discovered on this firewall yet.
                    </td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <p class="text-muted small mt-3 mb-0">
        Tunnels are discovered over SNMP. One that disappears from the firewall is marked
        <em>retired</em> and hidden from the dashboard, but its history is kept until you
        remove it here &mdash; so a failed SNMP poll can never destroy data on its own.
    </p>
@endif
@endsection
