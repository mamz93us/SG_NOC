<div x-show="paletteOpen" x-cloak
     x-transition.opacity.duration.100ms
     class="fixed inset-0 z-50 flex items-start justify-center pt-24 px-4 bg-slate-900/40 dark:bg-black/60"
     @click.self="paletteOpen = false">

    <div x-data="commandPalette()" x-init="init()"
         x-show="paletteOpen" x-cloak
         x-transition:enter="transition ease-out duration-100"
         x-transition:enter-start="opacity-0 scale-95"
         x-transition:enter-end="opacity-100 scale-100"
         class="w-full max-w-xl bg-white dark:bg-slate-800 rounded-lg shadow-2xl ring-1 ring-slate-200 dark:ring-slate-700 overflow-hidden">

        <div class="flex items-center px-4 border-b border-slate-200 dark:border-slate-700">
            <i class="bi bi-search text-slate-400 mr-3"></i>
            <input type="text"
                   x-ref="searchInput"
                   x-model="query"
                   @input.debounce.150ms="search()"
                   @keydown.arrow-down.prevent="moveCursor(1)"
                   @keydown.arrow-up.prevent="moveCursor(-1)"
                   @keydown.enter.prevent="activate()"
                   placeholder="Search pages, contacts, branches…"
                   class="flex-1 py-3 bg-transparent border-0 focus:ring-0 outline-none text-sm text-slate-700 dark:text-slate-100 placeholder-slate-400">
            <kbd class="text-[10px] font-sans text-slate-400 border border-slate-300 dark:border-slate-600 rounded px-1.5 py-0.5">ESC</kbd>
        </div>

        <ul class="max-h-96 overflow-y-auto py-1">
            <template x-for="(item, idx) in results" :key="item.id">
                <li @click="go(item)"
                    @mouseenter="cursor = idx"
                    :class="idx === cursor
                              ? 'bg-blue-50 dark:bg-slate-700'
                              : ''"
                    class="flex items-center gap-3 px-4 py-2 cursor-pointer">
                    <i :class="'bi ' + item.icon" class="text-slate-400 dark:text-slate-500 text-base"></i>
                    <span class="text-sm text-slate-700 dark:text-slate-200 truncate" x-text="item.label"></span>
                    <span class="ml-auto text-[10px] uppercase tracking-wider text-slate-400 dark:text-slate-500" x-text="item.group"></span>
                </li>
            </template>
            <li x-show="!results.length && query.length"
                class="px-4 py-6 text-center text-sm text-slate-400">No results</li>
            <li x-show="!results.length && !query.length"
                class="px-4 py-6 text-center text-sm text-slate-400">Type to search…</li>
        </ul>

        <div class="px-4 py-2 border-t border-slate-100 dark:border-slate-700 text-[11px] text-slate-400 flex items-center justify-between">
            <span>↑↓ navigate · ↵ open</span>
            <span x-text="results.length + ' results'"></span>
        </div>
    </div>
</div>

<script>
    function commandPalette() {
        return {
            query: '',
            results: [],
            cursor: 0,
            staticItems: [],
            entityCache: {},

            init() {
                // pull static items from the layout-root Alpine scope
                this.staticItems = (this.$root && this.$root.paletteItems) || window.__paletteStaticItems || [];
                this.$nextTick(() => this.$refs.searchInput?.focus());
                this.search();
            },

            async search() {
                const q = this.query.trim().toLowerCase();
                let items = this.staticItems.slice();

                if (q) {
                    items = items.filter(i =>
                        i.label.toLowerCase().includes(q) ||
                        i.group.toLowerCase().includes(q)
                    );
                }

                if (q.length >= 2) {
                    let live = this.entityCache[q];
                    if (!live) {
                        try {
                            const r = await fetch(`{{ route('admin.palette.search') }}?q=${encodeURIComponent(q)}`, {
                                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
                            });
                            live = await r.json();
                            this.entityCache[q] = live;
                        } catch { live = {}; }
                    }
                    if (live.contacts) {
                        live.contacts.forEach(c => items.push({
                            id: 'c' + c.id, label: c.name || c.email || ('Contact #' + c.id),
                            url: c.url, icon: 'bi-person', group: 'Contact', type: 'entity'
                        }));
                    }
                    if (live.branches) {
                        live.branches.forEach(b => items.push({
                            id: 'b' + b.id, label: b.name, url: b.url, icon: 'bi-building',
                            group: 'Branch', type: 'entity'
                        }));
                    }
                }

                this.results = items.slice(0, 30);
                this.cursor  = 0;
            },

            moveCursor(d) {
                if (!this.results.length) return;
                this.cursor = (this.cursor + d + this.results.length) % this.results.length;
            },

            activate() {
                if (this.results[this.cursor]) this.go(this.results[this.cursor]);
            },

            go(item) {
                if (item.url) window.location.href = item.url;
            },
        }
    }
</script>
