@php
    /** @var \Illuminate\Support\Collection $quickLinks */

    // Curated icon palette so the "Add" form gives the user a sane choice
    // without having to know Bootstrap Icons class names by heart.
    $iconChoices = [
        'bi-link-45deg'      => 'Link',
        'bi-globe'           => 'Web',
        'bi-house-door'      => 'Home',
        'bi-speedometer2'    => 'Dashboard',
        'bi-graph-up'        => 'Graph',
        'bi-shield-lock'     => 'Security',
        'bi-people'          => 'People',
        'bi-person-badge'    => 'User',
        'bi-cpu'             => 'Asset',
        'bi-printer'         => 'Printer',
        'bi-telephone'       => 'Phone',
        'bi-broadcast-pin'   => 'Alert',
        'bi-journal-text'    => 'Logs',
        'bi-gear'            => 'Settings',
        'bi-bookmark-star'   => 'Bookmark',
        'bi-rocket-takeoff'  => 'Launch',
        'bi-hdd-network'     => 'Network',
        'bi-cloud'           => 'Cloud',
    ];

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
@endphp

<div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-5 shadow-sm"
     x-data="{ editing: false, adding: false }">

    <div class="flex items-center justify-between mb-4">
        <div>
            <h2 class="text-base font-semibold text-slate-800 dark:text-slate-100">Quick Links</h2>
            <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">Your personal shortcuts — pin anything you visit a lot</p>
        </div>
        <div class="flex items-center gap-2">
            <button type="button" x-show="!adding && !editing" @click="adding = true"
                    class="text-xs font-medium text-blue-600 dark:text-blue-400 hover:underline flex items-center gap-1">
                <i class="bi bi-plus-lg"></i> Add link
            </button>
            <button type="button" x-show="!adding" @click="editing = !editing"
                    class="text-xs font-medium text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 flex items-center gap-1">
                <i class="bi" :class="editing ? 'bi-check-lg' : 'bi-pencil'"></i>
                <span x-text="editing ? 'Done' : 'Edit'"></span>
            </button>
        </div>
    </div>

    {{-- Add form --}}
    <form x-show="adding" x-cloak method="POST" action="{{ route('admin.quick-links.store') }}"
          class="mb-4 grid grid-cols-1 md:grid-cols-12 gap-2 items-start">
        @csrf
        <div class="md:col-span-3">
            <label class="block text-[11px] font-semibold uppercase tracking-wider text-slate-500 mb-1">Label</label>
            <input type="text" name="label" required maxlength="80" placeholder="e.g. Sophos Console"
                   class="w-full px-3 py-2 rounded-md border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900 text-sm text-slate-700 dark:text-slate-100">
        </div>
        <div class="md:col-span-5">
            <label class="block text-[11px] font-semibold uppercase tracking-wider text-slate-500 mb-1">URL</label>
            <input type="text" name="url" required maxlength="500" placeholder="https://… or /admin/…"
                   class="w-full px-3 py-2 rounded-md border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900 text-sm text-slate-700 dark:text-slate-100">
        </div>
        <div class="md:col-span-3">
            <label class="block text-[11px] font-semibold uppercase tracking-wider text-slate-500 mb-1">Icon</label>
            <select name="icon"
                    class="w-full px-3 py-2 rounded-md border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900 text-sm text-slate-700 dark:text-slate-100">
                @foreach($iconChoices as $cls => $label)
                    <option value="{{ $cls }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="md:col-span-1 flex gap-1 md:pt-[22px]">
            <button type="submit" class="px-3 py-2 rounded-md bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium" title="Save">
                <i class="bi bi-check-lg"></i>
            </button>
            <button type="button" @click="adding = false" class="px-3 py-2 rounded-md bg-slate-200 dark:bg-slate-700 hover:bg-slate-300 dark:hover:bg-slate-600 text-slate-700 dark:text-slate-200 text-sm" title="Cancel">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>
    </form>

    {{-- Links grid --}}
    @if($quickLinks->isEmpty())
        <div class="text-center py-8 px-4">
            <i class="bi bi-bookmark-star text-3xl text-slate-300 dark:text-slate-600"></i>
            <p class="text-sm text-slate-500 dark:text-slate-400 mt-2">No quick links yet.</p>
            <button type="button" @click="adding = true"
                    class="mt-3 inline-flex items-center gap-1 text-sm font-medium text-blue-600 dark:text-blue-400 hover:underline">
                <i class="bi bi-plus-lg"></i> Add your first link
            </button>
        </div>
    @else
        <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 gap-3">
            @foreach($quickLinks as $i => $link)
                @php $tone = $tones[$i % count($tones)]; @endphp
                <div class="relative group">
                    <a href="{{ $link->url }}"
                       class="flex flex-col items-center gap-2 p-3 rounded-lg border border-slate-200 dark:border-slate-700 hover:shadow-md hover:border-slate-300 dark:hover:border-slate-600 transition bg-white dark:bg-slate-800">
                        <div class="w-10 h-10 rounded-lg flex items-center justify-center bg-gradient-to-br {{ $tone }} shadow-sm">
                            <i class="bi {{ $link->icon ?? 'bi-link-45deg' }} text-base text-white"></i>
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
