@php
    /** @var \Illuminate\Support\Collection $quickLinks */
    /** @var \Illuminate\Support\Collection $availableAdminLinks */  // {id, name, url, icon}
    /** @var \Illuminate\Support\Collection $availableTools */       // {key, label, route, icon, url}

    $tones = [
        'from-blue-500 to-indigo-600',
        'from-emerald-500 to-teal-600',
        'from-fuchsia-500 to-pink-600',
        'from-amber-500 to-orange-600',
        'from-rose-500 to-red-600',
        'from-cyan-500 to-blue-600',
        'from-violet-500 to-purple-600',
        'from-lime-500 to-green-600',
    ];

    $iconForLink = function ($icon) {
        $icon = trim((string) $icon);
        if ($icon === '') return 'bi-link-45deg';
        return str_starts_with($icon, 'bi-') ? $icon : 'bi-' . $icon;
    };

    $hasOptions = $availableAdminLinks->isNotEmpty() || $availableTools->isNotEmpty();
@endphp

<div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-5 shadow-sm"
     x-data="{ editing: false, adding: false }">

    <div class="flex items-center justify-between mb-4">
        <div>
            <h2 class="text-base font-semibold text-slate-800 dark:text-slate-100">Quick Links</h2>
            <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                Pin any system link or admin tool to your dashboard
            </p>
        </div>
        <div class="flex items-center gap-2">
            @if($hasOptions)
                <button type="button" x-show="!adding && !editing" @click="adding = true"
                        class="text-xs font-medium text-blue-600 dark:text-blue-400 hover:underline flex items-center gap-1">
                    <i class="bi bi-plus-lg"></i> Pin link
                </button>
            @endif
            @if($quickLinks->isNotEmpty())
                <button type="button" x-show="!adding" @click="editing = !editing"
                        class="text-xs font-medium text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 flex items-center gap-1">
                    <i class="bi" :class="editing ? 'bi-check-lg' : 'bi-pencil'"></i>
                    <span x-text="editing ? 'Done' : 'Edit'"></span>
                </button>
            @endif
        </div>
    </div>

    {{-- ─── Pin form (picker, not free-text) ─── --}}
    @if($hasOptions)
        <form x-show="adding" x-cloak method="POST" action="{{ route('admin.quick-links.store') }}"
              x-data="{ source: 'tool' }"
              class="mb-4 grid grid-cols-1 md:grid-cols-12 gap-2 items-end p-3 rounded-lg bg-slate-50 dark:bg-slate-900/40 border border-slate-200 dark:border-slate-700">
            @csrf

            <div class="md:col-span-3">
                <label class="block text-[11px] font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400 mb-1">Source</label>
                <select name="source" x-model="source"
                        class="w-full px-3 py-2 rounded-md border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900 text-sm text-slate-700 dark:text-slate-100">
                    @if($availableTools->isNotEmpty())
                        <option value="tool">Admin Tools</option>
                    @endif
                    @if($availableAdminLinks->isNotEmpty())
                        <option value="admin_link">System Links</option>
                    @endif
                </select>
            </div>

            {{-- Tools picker --}}
            @if($availableTools->isNotEmpty())
                <div class="md:col-span-7" x-show="source === 'tool'">
                    <label class="block text-[11px] font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400 mb-1">Choose a tool</label>
                    <select name="source_id" x-bind:disabled="source !== 'tool'"
                            class="w-full px-3 py-2 rounded-md border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900 text-sm text-slate-700 dark:text-slate-100">
                        @foreach($availableTools as $tool)
                            <option value="{{ $tool['key'] }}">{{ $tool['label'] }}</option>
                        @endforeach
                    </select>
                </div>
            @endif

            {{-- Admin links picker --}}
            @if($availableAdminLinks->isNotEmpty())
                <div class="md:col-span-7" x-show="source === 'admin_link'" x-cloak>
                    <label class="block text-[11px] font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400 mb-1">Choose a system link</label>
                    <select name="source_id" x-bind:disabled="source !== 'admin_link'"
                            class="w-full px-3 py-2 rounded-md border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900 text-sm text-slate-700 dark:text-slate-100">
                        @foreach($availableAdminLinks as $link)
                            <option value="{{ $link->id }}">{{ $link->name }}</option>
                        @endforeach
                    </select>
                </div>
            @endif

            <div class="md:col-span-2 flex gap-1 justify-end">
                <button type="submit" class="px-3 py-2 rounded-md bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium flex items-center gap-1">
                    <i class="bi bi-pin-angle"></i> Pin
                </button>
                <button type="button" @click="adding = false"
                        class="px-3 py-2 rounded-md bg-slate-200 dark:bg-slate-700 hover:bg-slate-300 dark:hover:bg-slate-600 text-slate-700 dark:text-slate-200 text-sm">
                    <i class="bi bi-x-lg"></i>
                </button>
            </div>
        </form>
    @endif

    {{-- ─── Pinned links grid ─── --}}
    @if($quickLinks->isEmpty())
        <div class="text-center py-8 px-4">
            <i class="bi bi-bookmark-star text-3xl text-slate-300 dark:text-slate-600"></i>
            <p class="text-sm text-slate-500 dark:text-slate-400 mt-2">No quick links yet.</p>
            @if($hasOptions)
                <button type="button" @click="adding = true"
                        class="mt-3 inline-flex items-center gap-1 text-sm font-medium text-blue-600 dark:text-blue-400 hover:underline">
                    <i class="bi bi-plus-lg"></i> Pin your first link
                </button>
            @else
                <p class="text-xs text-slate-400 dark:text-slate-500 mt-2">No system links or admin tools available to your role.</p>
            @endif
        </div>
    @else
        <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 gap-3">
            @foreach($quickLinks as $i => $link)
                @php $tone = $tones[$i % count($tones)]; @endphp
                <div class="relative group">
                    <a href="{{ $link->url }}"
                       class="flex flex-col items-center gap-2 p-3 rounded-lg border border-slate-200 dark:border-slate-700 hover:shadow-md hover:border-slate-300 dark:hover:border-slate-600 transition bg-white dark:bg-slate-800">
                        <div class="w-10 h-10 rounded-lg flex items-center justify-center bg-gradient-to-br {{ $tone }} shadow-sm">
                            <i class="bi {{ $iconForLink($link->icon) }} text-base text-white"></i>
                        </div>
                        <div class="text-xs font-medium text-slate-700 dark:text-slate-200 text-center leading-tight truncate w-full">
                            {{ $link->label }}
                        </div>
                    </a>
                    <form x-show="editing" x-cloak method="POST"
                          action="{{ route('admin.quick-links.destroy', $link) }}"
                          onsubmit="return confirm('Remove this quick link?');"
                          class="absolute -top-2 -right-2">
                        @csrf @method('DELETE')
                        <button type="submit"
                                class="w-6 h-6 rounded-full bg-red-500 hover:bg-red-600 text-white flex items-center justify-center shadow-md text-[11px]"
                                title="Remove">
                            <i class="bi bi-x-lg"></i>
                        </button>
                    </form>
                </div>
            @endforeach
        </div>
    @endif
</div>
