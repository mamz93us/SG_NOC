@php
    /** @var \Illuminate\Support\Collection $pendingApprovals */

    $statusBadge = function (string $status) {
        return match ($status) {
            'pending'                 => ['bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-200', 'Pending'],
            'manager_input_pending'   => ['bg-orange-100 text-orange-800 dark:bg-orange-900/30 dark:text-orange-200', 'Manager Input'],
            'awaiting_manager_form'   => ['bg-orange-100 text-orange-800 dark:bg-orange-900/30 dark:text-orange-200', 'Awaiting Form'],
            default                   => ['bg-slate-100 text-slate-700 dark:bg-slate-700 dark:text-slate-200', ucfirst($status)],
        };
    };

    $typeIcon = fn (string $type) => match (true) {
        str_contains($type, 'user')      => 'bi-person-plus',
        str_contains($type, 'asset')     => 'bi-cpu',
        str_contains($type, 'extension') => 'bi-telephone',
        str_contains($type, 'license')   => 'bi-card-checklist',
        str_contains($type, 'group')     => 'bi-people',
        str_contains($type, 'profile')   => 'bi-person-badge',
        str_contains($type, 'offboard')  => 'bi-box-arrow-right',
        default                          => 'bi-diagram-2',
    };
@endphp

<div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-6 shadow-sm">
    <div class="flex items-center justify-between mb-4">
        <div>
            <h2 class="text-base font-semibold text-slate-800 dark:text-slate-100 flex items-center gap-2">
                <span>Pending Approvals</span>
                @if($pendingApprovals->count() > 0)
                    <span class="px-2 py-0.5 rounded-full bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-200 text-[11px] font-bold">
                        {{ $pendingApprovals->count() }}
                    </span>
                @endif
            </h2>
            <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">Workflow requests waiting on a decision</p>
        </div>
        @if(\Route::has('admin.workflows.pending'))
            <a href="{{ route('admin.workflows.pending') }}"
               class="text-xs font-medium text-blue-600 dark:text-blue-400 hover:underline whitespace-nowrap">
                Review queue →
            </a>
        @endif
    </div>

    @if($pendingApprovals->isEmpty())
        <div class="text-center py-8">
            <i class="bi bi-check2-circle text-3xl text-emerald-500"></i>
            <p class="text-sm text-slate-500 dark:text-slate-400 mt-2">No requests waiting for approval. You're all caught up.</p>
        </div>
    @else
        <ul class="divide-y divide-slate-100 dark:divide-slate-700 -mx-2">
            @foreach($pendingApprovals as $req)
                @php
                    [$badgeCls, $badgeLabel] = $statusBadge($req->status);
                    $href = \Route::has('admin.workflows.show') ? route('admin.workflows.show', $req) : '#';
                @endphp
                <li>
                    <a href="{{ $href }}"
                       class="flex items-start gap-3 px-2 py-3 rounded-lg hover:bg-slate-50 dark:hover:bg-slate-700/50 transition">
                        <div class="w-9 h-9 rounded-lg bg-gradient-to-br from-amber-500 to-orange-600 flex items-center justify-center shadow-sm shrink-0">
                            <i class="bi {{ $typeIcon($req->type) }} text-white text-base"></i>
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="text-sm font-semibold text-slate-800 dark:text-slate-100 truncate">
                                    {{ $req->title ?: $req->typeLabel() }}
                                </span>
                                <span class="px-1.5 py-0.5 rounded text-[10px] font-bold uppercase tracking-wider {{ $badgeCls }}">
                                    {{ $badgeLabel }}
                                </span>
                            </div>
                            <div class="text-xs text-slate-500 dark:text-slate-400 mt-0.5 truncate">
                                {{ $req->typeLabel() }}
                                @if($req->requester) · by <span class="font-medium text-slate-600 dark:text-slate-300">{{ $req->requester->name }}</span> @endif
                                @if($req->branch) · {{ $req->branch->name }} @endif
                                @if($req->total_steps > 0) · step {{ $req->current_step }}/{{ $req->total_steps }} @endif
                                · {{ $req->created_at?->diffForHumans() }}
                            </div>
                        </div>
                        <i class="bi bi-chevron-right text-slate-300 dark:text-slate-600 text-sm mt-1 shrink-0"></i>
                    </a>
                </li>
            @endforeach
        </ul>
    @endif
</div>
