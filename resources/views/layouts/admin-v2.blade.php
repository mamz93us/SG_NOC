@php
    $__user = auth()->user();
    $__dark = $__user?->dark_mode ?? false;
    $__settings = \App\Models\Setting::get();
    $__paletteItems = app(\App\Services\AdminPaletteService::class)->staticItems();
@endphp
<!DOCTYPE html>
<html lang="en"
      class="{{ $__dark ? 'dark' : '' }}"
      data-bs-theme="{{ $__dark ? 'dark' : 'light' }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>SG NOC</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    <style>
        [x-cloak]{display:none!important}
        /* Tailwind preflight is disabled globally so it doesn't reset Bootstrap on classic pages.
           v2 pages have no Bootstrap reset, so re-add the minimum button/input normalisation here. */
        button{background:transparent;border:0;padding:0;font:inherit;color:inherit;cursor:pointer}
        button:focus{outline:none}
        input,select,textarea{font:inherit}
        kbd{font-family:inherit}
        /* Sidebar custom scrollbar */
        .v2-scroll::-webkit-scrollbar{width:6px}
        .v2-scroll::-webkit-scrollbar-track{background:transparent}
        .v2-scroll::-webkit-scrollbar-thumb{background:#334155;border-radius:3px}
        .v2-scroll::-webkit-scrollbar-thumb:hover{background:#475569}
    </style>
</head>

<body class="bg-slate-100 dark:bg-slate-900 text-slate-800 dark:text-slate-100 antialiased min-h-screen"
      x-data="adminV2Layout()"
      x-init="init()">

    {{-- ─── Sidebar (DARK) ─── --}}
    <aside :class="sidebarCollapsed ? 'w-16' : 'w-64'"
           class="fixed inset-y-0 left-0 z-40 bg-slate-900 text-slate-200 border-r border-slate-800 flex flex-col transition-[width] duration-200 shadow-xl">

        {{-- Brand --}}
        <div class="h-16 flex items-center gap-2.5 px-4 border-b border-slate-800"
             style="background: linear-gradient(135deg, rgba(30,64,175,0.25) 0%, rgba(109,40,217,0.18) 100%);">
            <a href="{{ route('admin.dashboard') }}" class="flex items-center gap-2.5 min-w-0">
                @if($__settings->company_logo ?? false)
                    <img src="{{ \Illuminate\Support\Facades\Storage::url($__settings->company_logo) }}"
                         alt="Logo" class="h-8 w-auto object-contain shrink-0">
                @else
                    <span class="w-9 h-9 rounded-lg flex items-center justify-center text-white font-extrabold text-sm shrink-0 shadow-lg"
                          style="background:linear-gradient(135deg,#1e40af 0%,#6d28d9 50%,#be185d 100%);">SG</span>
                @endif
                <div x-show="!sidebarCollapsed" class="min-w-0">
                    <div class="font-bold text-white leading-tight">SG NOC</div>
                    <div class="text-[10px] uppercase tracking-wider text-slate-400">Admin Console</div>
                </div>
            </a>
        </div>

        <nav class="flex-1 overflow-y-auto py-2 v2-scroll">
            @include('layouts.partials.sidebar-nav')
        </nav>

        <button type="button" @click="toggleSidebar()"
                class="h-10 border-t border-slate-800 flex items-center justify-center text-slate-400 hover:text-white hover:bg-slate-800 transition"
                title="Toggle sidebar">
            <i class="bi" :class="sidebarCollapsed ? 'bi-chevron-double-right' : 'bi-chevron-double-left'"></i>
        </button>
    </aside>

    {{-- ─── Topbar (DARK) ─── --}}
    <header :class="sidebarCollapsed ? 'left-16' : 'left-64'"
            class="fixed top-0 right-0 h-16 bg-slate-900/95 text-slate-200 backdrop-blur border-b border-slate-800 z-30 flex items-center px-5 gap-3 transition-[left] duration-200">

        <div class="flex-1 min-w-0">
            <button type="button" @click="paletteOpen = true"
                    class="w-full max-w-md flex items-center gap-2 px-3 py-1.5 rounded-md border border-slate-700 bg-slate-800/60 text-sm text-slate-400 hover:border-slate-500 hover:text-slate-200 transition">
                <i class="bi bi-search"></i>
                <span>Search pages, contacts, branches…</span>
                <kbd class="ml-auto text-[10px] font-sans border border-slate-600 rounded px-1 py-0.5">Ctrl+K</kbd>
            </button>
        </div>

        {{-- Notification bell --}}
        <div class="relative" x-data="{ open: false }" @click.outside="open = false">
            <button type="button" @click="open = !open"
                    class="relative w-9 h-9 rounded-md flex items-center justify-center text-slate-300 hover:bg-slate-800 hover:text-white"
                    id="notifBell">
                <i class="bi bi-bell text-lg"></i>
                <span id="notifBadge"
                      class="absolute -top-0.5 -right-0.5 min-w-[16px] h-4 px-1 rounded-full bg-red-500 text-white text-[10px] font-semibold leading-4 text-center hidden"></span>
            </button>
            <div x-show="open" x-cloak x-transition.opacity
                 class="absolute right-0 mt-2 w-80 max-w-sm bg-slate-800 text-slate-200 border border-slate-700 rounded-lg shadow-xl overflow-hidden">
                <div class="px-4 py-2 flex items-center justify-between border-b border-slate-700">
                    <strong class="text-sm">Notifications</strong>
                    <form method="POST" action="{{ route('admin.notifications.read-all') }}">
                        @csrf
                        <button type="submit" class="text-xs text-slate-400 hover:text-white">Mark all read</button>
                    </form>
                </div>
                <div id="notifItems" class="max-h-80 overflow-y-auto">
                    <div id="notifEmpty" class="px-4 py-6 text-center text-sm text-slate-500">
                        <i class="bi bi-bell-slash mr-1"></i>No new notifications
                    </div>
                </div>
                <a href="{{ route('admin.notifications.index') }}"
                   class="block px-4 py-2 text-center text-xs text-blue-400 hover:bg-slate-700 border-t border-slate-700">
                    View all notifications →
                </a>
            </div>
        </div>

        {{-- Dark mode toggle --}}
        <button type="button" @click="toggleDark()"
                class="w-9 h-9 rounded-md flex items-center justify-center text-slate-300 hover:bg-slate-800 hover:text-white"
                title="Toggle dark mode">
            <i class="bi" :class="dark ? 'bi-sun' : 'bi-moon-stars'"></i>
        </button>

        {{-- Profile --}}
        <div class="relative" x-data="{ open: false }" @click.outside="open = false">
            <button type="button" @click="open = !open"
                    class="flex items-center gap-2 pl-1 pr-2 py-1 rounded-md hover:bg-slate-800">
                <span class="w-8 h-8 rounded-full text-white text-xs font-bold flex items-center justify-center shadow"
                      style="background:linear-gradient(135deg,#667eea,#764ba2);">
                    {{ strtoupper(substr($__user->name ?? 'U', 0, 1)) }}
                </span>
                <span class="hidden sm:inline text-sm text-slate-200 truncate max-w-[120px]">{{ $__user->name }}</span>
                <i class="bi bi-chevron-down text-xs text-slate-400"></i>
            </button>
            <div x-show="open" x-cloak x-transition.opacity
                 class="absolute right-0 mt-2 w-64 bg-slate-800 text-slate-200 border border-slate-700 rounded-lg shadow-xl overflow-hidden">
                <div class="px-4 py-3 border-b border-slate-700">
                    <div class="font-semibold text-sm text-white truncate">{{ $__user->name }}</div>
                    <div class="text-xs text-slate-400 truncate">{{ $__user->email }}</div>
                    <span class="inline-block mt-1.5 px-2 py-0.5 rounded text-[10px] font-semibold bg-slate-700 text-slate-300">
                        {{ \App\Models\User::roleLabel($__user->role ?? 'admin') }}
                    </span>
                </div>
                <a href="{{ route('admin.two-factor.setup') }}"
                   class="flex items-center gap-2 px-4 py-2 text-sm text-slate-200 hover:bg-slate-700">
                    <i class="bi bi-shield-lock text-slate-400"></i>
                    Two-Factor Auth
                    @if($__user->hasTwoFactorEnabled())
                        <span class="ml-auto text-[10px] px-1.5 py-0.5 rounded bg-green-900/50 text-green-300 font-semibold">ON</span>
                    @endif
                </a>
                <button type="button" @click="switchToClassic()"
                        class="w-full flex items-center gap-2 px-4 py-2 text-sm text-slate-200 hover:bg-slate-700 text-left">
                    <i class="bi bi-arrow-counterclockwise text-slate-400"></i>
                    Switch to classic layout
                </button>
                <form method="POST" action="/logout" class="border-t border-slate-700">
                    @csrf
                    <button type="submit"
                            class="w-full flex items-center gap-2 px-4 py-2 text-sm text-red-400 hover:bg-red-900/30 text-left">
                        <i class="bi bi-box-arrow-right"></i>
                        Logout
                    </button>
                </form>
            </div>
        </div>
    </header>

    {{-- ─── Command palette overlay ─── --}}
    @include('layouts.partials.command-palette')

    {{-- ─── Page content ─── --}}
    <main :class="sidebarCollapsed ? 'pl-16' : 'pl-64'"
          class="pt-16 transition-[padding] duration-200">
        <div class="px-6 py-6 max-w-[1600px] mx-auto">
            @include('layouts.partials.flash-messages')
            @yield('content')
        </div>
    </main>

    @stack('scripts')

    {{-- ─── Layout-root Alpine state ─── --}}
    <script>
        function adminV2Layout() {
            return {
                sidebarCollapsed: localStorage.getItem('adminV2.sidebar') === 'collapsed',
                paletteOpen: false,
                dark: {{ $__dark ? 'true' : 'false' }},
                paletteItems: @json($__paletteItems),

                init() {
                    window.addEventListener('keydown', (e) => {
                        if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
                            e.preventDefault();
                            this.paletteOpen = true;
                        }
                        if (e.key === 'Escape') this.paletteOpen = false;
                    });
                },

                toggleSidebar() {
                    this.sidebarCollapsed = !this.sidebarCollapsed;
                    localStorage.setItem('adminV2.sidebar', this.sidebarCollapsed ? 'collapsed' : 'expanded');
                },

                toggleDark() {
                    this.dark = !this.dark;
                    document.documentElement.classList.toggle('dark', this.dark);
                    document.documentElement.setAttribute('data-bs-theme', this.dark ? 'dark' : 'light');
                    fetch('{{ route('admin.toggle-dark-mode') }}', {
                        method: 'POST',
                        headers: {
                            'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                            'Accept': 'application/json'
                        }
                    });
                },

                switchToClassic() {
                    fetch('{{ route('admin.toggle-layout') }}', {
                        method: 'POST',
                        headers: {
                            'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                            'Accept': 'application/json',
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify({ version: 'classic' }),
                    }).then(() => window.location.reload());
                },
            }
        }
    </script>

    {{-- Notification bell polling — same endpoint as classic layout --}}
    <script>
    (function () {
        const badge = document.getElementById('notifBadge');
        const items = document.getElementById('notifItems');

        function severityBorder(s) {
            return s === 'critical' ? '#dc3545' : (s === 'warning' ? '#ffc107' : '#0dcaf0');
        }
        function load() {
            fetch('{{ route('admin.notifications.unread-count') }}', {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(r => r.json())
            .then(data => {
                const count = data.count ?? 0;
                if (count > 0) {
                    badge.textContent = count > 99 ? '99+' : count;
                    badge.classList.remove('hidden');
                } else {
                    badge.classList.add('hidden');
                }
                if (data.items && data.items.length > 0) {
                    let html = '';
                    data.items.forEach(n => {
                        html += `<a href="${n.link || '#'}" class="block px-4 py-2 text-sm hover:bg-slate-700 border-l-2" style="border-left-color:${severityBorder(n.severity)}">
                            <div class="font-semibold text-white">${n.title}</div>
                            <div class="text-xs text-slate-400 mt-0.5">${n.created_at}</div>
                        </a>`;
                    });
                    items.innerHTML = html;
                } else {
                    items.innerHTML = '<div class="px-4 py-6 text-center text-sm text-slate-500"><i class="bi bi-bell-slash mr-1"></i>No new notifications</div>';
                }
            })
            .catch(() => {});
        }
        load();
        setInterval(load, 60000);
    })();
    </script>

</body>
</html>
