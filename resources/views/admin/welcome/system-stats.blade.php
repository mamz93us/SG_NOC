@php
    /** @var array{vpn:array{up:int,total:int},users:int,assets:int,pending_approvals:int} $systemStats */

    $vpnUp    = $systemStats['vpn']['up']    ?? 0;
    $vpnTotal = $systemStats['vpn']['total'] ?? 0;
    $vpnPct   = $vpnTotal > 0 ? (int) round($vpnUp / $vpnTotal * 100) : 100;
    $vpnTone  = $vpnTotal === 0
                ? 'slate'
                : ($vpnUp === $vpnTotal ? 'green' : ($vpnUp / $vpnTotal >= 0.5 ? 'amber' : 'red'));

    $vpnGradient = match ($vpnTone) {
        'green' => 'from-emerald-500 to-teal-600',
        'amber' => 'from-amber-500 to-orange-600',
        'red'   => 'from-red-500 to-rose-600',
        default => 'from-slate-500 to-slate-700',
    };
    $vpnGlow = match ($vpnTone) {
        'green' => 'bg-emerald-500/10',
        'amber' => 'bg-amber-500/10',
        'red'   => 'bg-red-500/10',
        default => 'bg-slate-500/10',
    };

    $approvalsCount = $systemStats['pending_approvals'] ?? 0;
    $approvalsTone  = $approvalsCount === 0 ? 'green' : ($approvalsCount > 5 ? 'red' : 'amber');
    $approvalsGrad  = match ($approvalsTone) {
        'green' => 'from-emerald-500 to-teal-600',
        'amber' => 'from-amber-500 to-orange-600',
        'red'   => 'from-red-500 to-rose-600',
    };
    $approvalsGlow  = match ($approvalsTone) {
        'green' => 'bg-emerald-500/10',
        'amber' => 'bg-amber-500/10',
        'red'   => 'bg-red-500/10',
    };
@endphp

<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">

    {{-- VPN Hub --}}
    @php $href = \Route::has('admin.network.vpn.index') ? route('admin.network.vpn.index') : null; @endphp
    <{{ $href ? 'a' : 'div' }}
        @if($href) href="{{ $href }}" @endif
        class="group relative overflow-hidden bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-5 shadow-sm transition-all hover:shadow-lg @if($href) hover:-translate-y-0.5 hover:border-slate-300 dark:hover:border-slate-600 cursor-pointer @endif">
        <div class="absolute -top-8 -right-8 w-24 h-24 rounded-full {{ $vpnGlow }} blur-2xl"></div>
        <div class="relative flex items-start gap-4">
            <div class="w-12 h-12 rounded-xl flex items-center justify-center bg-gradient-to-br {{ $vpnGradient }} shadow-md shrink-0">
                <i class="bi bi-shield-lock-fill text-2xl text-white"></i>
            </div>
            <div class="min-w-0 flex-1">
                <div class="flex items-baseline gap-1">
                    <span class="text-3xl font-bold text-slate-800 dark:text-slate-100 leading-none tracking-tight">{{ $vpnUp }}</span>
                    <span class="text-sm text-slate-500 dark:text-slate-400">/ {{ $vpnTotal }}</span>
                </div>
                <div class="text-sm font-medium text-slate-700 dark:text-slate-200 mt-1.5">VPN Tunnels Up</div>
                <div class="text-xs mt-1 font-medium @if($vpnTone === 'green') text-emerald-600 dark:text-emerald-400 @elseif($vpnTone === 'amber') text-amber-600 dark:text-amber-400 @elseif($vpnTone === 'red') text-red-600 dark:text-red-400 @else text-slate-500 @endif">
                    @if($vpnTotal === 0) no tunnels configured
                    @elseif($vpnUp === $vpnTotal) all healthy
                    @else {{ $vpnPct }}% online
                    @endif
                </div>
            </div>
        </div>
    </{{ $href ? 'a' : 'div' }}>

    {{-- Users --}}
    @php $href = \Route::has('admin.users.index') ? route('admin.users.index') : null; @endphp
    <{{ $href ? 'a' : 'div' }}
        @if($href) href="{{ $href }}" @endif
        class="group relative overflow-hidden bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-5 shadow-sm transition-all hover:shadow-lg @if($href) hover:-translate-y-0.5 hover:border-slate-300 dark:hover:border-slate-600 cursor-pointer @endif">
        <div class="absolute -top-8 -right-8 w-24 h-24 rounded-full bg-indigo-500/10 blur-2xl"></div>
        <div class="relative flex items-start gap-4">
            <div class="w-12 h-12 rounded-xl flex items-center justify-center bg-gradient-to-br from-indigo-500 to-purple-600 shadow-md shrink-0">
                <i class="bi bi-people-fill text-2xl text-white"></i>
            </div>
            <div class="min-w-0 flex-1">
                <div class="text-3xl font-bold text-slate-800 dark:text-slate-100 leading-none tracking-tight">{{ number_format($systemStats['users'] ?? 0) }}</div>
                <div class="text-sm font-medium text-slate-700 dark:text-slate-200 mt-1.5">System Users</div>
                <div class="text-xs text-indigo-600 dark:text-indigo-400 mt-1 font-medium">across all roles</div>
            </div>
        </div>
    </{{ $href ? 'a' : 'div' }}>

    {{-- Assets --}}
    @php $href = \Route::has('admin.devices.index') ? route('admin.devices.index') : null; @endphp
    <{{ $href ? 'a' : 'div' }}
        @if($href) href="{{ $href }}" @endif
        class="group relative overflow-hidden bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-5 shadow-sm transition-all hover:shadow-lg @if($href) hover:-translate-y-0.5 hover:border-slate-300 dark:hover:border-slate-600 cursor-pointer @endif">
        <div class="absolute -top-8 -right-8 w-24 h-24 rounded-full bg-cyan-500/10 blur-2xl"></div>
        <div class="relative flex items-start gap-4">
            <div class="w-12 h-12 rounded-xl flex items-center justify-center bg-gradient-to-br from-cyan-500 to-blue-600 shadow-md shrink-0">
                <i class="bi bi-cpu-fill text-2xl text-white"></i>
            </div>
            <div class="min-w-0 flex-1">
                <div class="text-3xl font-bold text-slate-800 dark:text-slate-100 leading-none tracking-tight">{{ number_format($systemStats['assets'] ?? 0) }}</div>
                <div class="text-sm font-medium text-slate-700 dark:text-slate-200 mt-1.5">Assets Tracked</div>
                <div class="text-xs text-cyan-600 dark:text-cyan-400 mt-1 font-medium">devices in inventory</div>
            </div>
        </div>
    </{{ $href ? 'a' : 'div' }}>

    {{-- Pending Approvals --}}
    @php $href = \Route::has('admin.workflows.pending') ? route('admin.workflows.pending') : null; @endphp
    <{{ $href ? 'a' : 'div' }}
        @if($href) href="{{ $href }}" @endif
        class="group relative overflow-hidden bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-5 shadow-sm transition-all hover:shadow-lg @if($href) hover:-translate-y-0.5 hover:border-slate-300 dark:hover:border-slate-600 cursor-pointer @endif">
        <div class="absolute -top-8 -right-8 w-24 h-24 rounded-full {{ $approvalsGlow }} blur-2xl"></div>
        <div class="relative flex items-start gap-4">
            <div class="w-12 h-12 rounded-xl flex items-center justify-center bg-gradient-to-br {{ $approvalsGrad }} shadow-md shrink-0">
                <i class="bi bi-hourglass-split text-2xl text-white"></i>
            </div>
            <div class="min-w-0 flex-1">
                <div class="text-3xl font-bold text-slate-800 dark:text-slate-100 leading-none tracking-tight">{{ number_format($approvalsCount) }}</div>
                <div class="text-sm font-medium text-slate-700 dark:text-slate-200 mt-1.5">Pending Approvals</div>
                <div class="text-xs mt-1 font-medium @if($approvalsTone === 'green') text-emerald-600 dark:text-emerald-400 @elseif($approvalsTone === 'amber') text-amber-600 dark:text-amber-400 @else text-red-600 dark:text-red-400 @endif">
                    @if($approvalsCount === 0) nothing waiting
                    @elseif($approvalsCount === 1) needs review
                    @else need review
                    @endif
                </div>
            </div>
        </div>
    </{{ $href ? 'a' : 'div' }}>

</div>
