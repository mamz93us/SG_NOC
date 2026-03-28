@extends('layouts.admin')
@section('content')

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-collection me-2 text-primary"></i>New Intune Group</h4>
        <small class="text-muted">Create a new Azure AD security group, or link an existing one</small>
    </div>
    <a href="{{ route('admin.intune-groups.index') }}" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i>Back
    </a>
</div>

@if($errors->any())
<div class="alert alert-danger alert-dismissible fade show py-2">
    <i class="bi bi-exclamation-triangle me-1"></i>{{ $errors->first() }}
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
@endif

<div class="card shadow-sm" style="max-width:680px;"
     x-data="intuneGroupCreate('{{ route('admin.intune-groups.groups.search') }}')">
    <div class="card-body">

        {{-- Mode toggle --}}
        <div class="mb-4">
            <div class="btn-group w-100" role="group" aria-label="Group mode">
                <input type="radio" class="btn-check" name="_mode_ui" id="mode-create" value="create"
                       x-model="mode">
                <label class="btn btn-outline-primary" for="mode-create">
                    <i class="bi bi-plus-circle me-1"></i>Create New Azure AD Group
                </label>
                <input type="radio" class="btn-check" name="_mode_ui" id="mode-link" value="link"
                       x-model="mode">
                <label class="btn btn-outline-secondary" for="mode-link">
                    <i class="bi bi-link-45deg me-1"></i>Link Existing Azure AD Group
                </label>
            </div>
        </div>

        <form method="POST" action="{{ route('admin.intune-groups.store') }}" @submit.prevent="submit">
            @csrf
            {{-- Actual mode value sent to controller --}}
            <input type="hidden" name="mode" :value="mode">

            {{-- ── CREATE mode: plain name field ───────────────────────────── --}}
            <div x-show="mode === 'create'">
                <div class="mb-3">
                    <label class="form-label fw-semibold">Group Name <span class="text-danger">*</span></label>
                    <input type="text" name="name" class="form-control @error('name') is-invalid @enderror"
                           :required="mode === 'create'"
                           value="{{ old('name') }}"
                           placeholder="e.g. SG-Printers-MainBranch" maxlength="150">
                    @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    <div class="form-text">This will become the Azure AD group display name.</div>
                </div>
            </div>

            {{-- ── LINK mode: search + select existing Azure group ─────────── --}}
            <div x-show="mode === 'link'">
                <div class="mb-3">
                    <label class="form-label fw-semibold">Search Azure AD Group <span class="text-danger">*</span></label>
                    <div class="position-relative">
                        <input type="text" class="form-control @error('azure_group_id') is-invalid @enderror"
                               placeholder="Type at least 2 characters…"
                               x-model="q"
                               @input.debounce.350ms="search()"
                               autocomplete="off">
                        {{-- Spinner --}}
                        <div class="position-absolute top-50 end-0 translate-middle-y pe-3"
                             x-show="loading" x-cloak>
                            <div class="spinner-border spinner-border-sm text-secondary"></div>
                        </div>
                    </div>

                    {{-- Dropdown results --}}
                    <ul class="list-group mt-1 shadow-sm" x-show="results.length > 0 && !selectedId" x-cloak>
                        <template x-for="g in results" :key="g.id">
                            <li class="list-group-item list-group-item-action py-2 d-flex justify-content-between align-items-center"
                                @click="pick(g)" style="cursor:pointer;">
                                <div>
                                    <div class="fw-semibold small" x-text="g.displayName"></div>
                                    <div class="text-muted" style="font-size:.75rem" x-text="g.description || ''"></div>
                                </div>
                                <span class="badge bg-light text-dark border font-monospace"
                                      style="font-size:.7rem" x-text="g.id.substring(0,8)+'…'"></span>
                            </li>
                        </template>
                    </ul>

                    {{-- Selected confirmation --}}
                    <div class="mt-2 d-flex align-items-center gap-2" x-show="selectedId" x-cloak>
                        <i class="bi bi-check-circle-fill text-success"></i>
                        <span class="small"><strong x-text="selectedName"></strong>
                            <span class="text-muted font-monospace ms-1" style="font-size:.75rem"
                                  x-text="selectedId"></span>
                        </span>
                        <button type="button" class="btn btn-sm btn-link p-0 text-muted ms-auto"
                                @click="clearPick()" title="Clear selection">
                            <i class="bi bi-x-circle"></i> change
                        </button>
                    </div>

                    @error('azure_group_id')
                    <div class="text-danger small mt-1">{{ $message }}</div>
                    @enderror

                    {{-- Group name mirrors the selected group name for the controller --}}
                    <input type="hidden" name="name" :value="selectedName || '{{ old('name') }}'">
                </div>

                {{-- Hidden field carrying the Azure group ID --}}
                <input type="hidden" name="azure_group_id" :value="selectedId">
            </div>

            {{-- ── Shared fields ──────────────────────────────────────────── --}}
            <div class="mb-3">
                <label class="form-label fw-semibold">Description</label>
                <textarea name="description" class="form-control @error('description') is-invalid @enderror"
                    rows="2" placeholder="Optional description" maxlength="500">{{ old('description') }}</textarea>
                @error('description')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="mb-3">
                <label class="form-label fw-semibold">Group Type <span class="text-danger">*</span></label>
                <select name="group_type" class="form-select @error('group_type') is-invalid @enderror" required>
                    <option value="">Select type…</option>
                    @foreach(['printer' => 'Printer', 'policy' => 'Policy', 'device' => 'Device', 'compliance' => 'Compliance'] as $val => $label)
                    <option value="{{ $val }}" {{ old('group_type') === $val ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>
                @error('group_type')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="row g-3 mb-4">
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Branch <small class="text-muted">(optional)</small></label>
                    <select name="branch_id" class="form-select form-select-sm">
                        <option value="">— Any Branch —</option>
                        @foreach($branches as $b)
                        <option value="{{ $b->id }}" {{ old('branch_id') == $b->id ? 'selected' : '' }}>{{ $b->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Department <small class="text-muted">(optional)</small></label>
                    <select name="department_id" class="form-select form-select-sm">
                        <option value="">— Any Department —</option>
                        @foreach($departments as $d)
                        <option value="{{ $d->id }}" {{ old('department_id') == $d->id ? 'selected' : '' }}>{{ $d->name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="d-flex justify-content-end gap-2">
                <a href="{{ route('admin.intune-groups.index') }}" class="btn btn-secondary">Cancel</a>
                <button type="submit" class="btn btn-primary" :disabled="mode === 'link' && !selectedId">
                    <span x-show="mode === 'create'"><i class="bi bi-cloud-check me-1"></i>Create Group in Azure AD</span>
                    <span x-show="mode === 'link'"  x-cloak><i class="bi bi-link-45deg me-1"></i>Link Group</span>
                </button>
            </div>
        </form>
    </div>
</div>

@push('scripts')
<script>
function intuneGroupCreate(searchUrl) {
    return {
        mode:         '{{ old('mode', 'create') }}',
        q:            '',
        results:      [],
        loading:      false,
        selectedId:   '{{ old('azure_group_id', '') }}',
        selectedName: '',

        async search() {
            if (this.q.length < 2) { this.results = []; return; }
            this.loading = true;
            try {
                const r    = await fetch(`${searchUrl}?q=${encodeURIComponent(this.q)}`);
                this.results = await r.json();
            } catch (e) {
                this.results = [];
            } finally {
                this.loading = false;
            }
        },

        pick(g) {
            this.selectedId   = g.id;
            this.selectedName = g.displayName;
            this.results      = [];
            this.q            = g.displayName;
        },

        clearPick() {
            this.selectedId   = '';
            this.selectedName = '';
            this.q            = '';
            this.results      = [];
        },

        submit() {
            if (this.mode === 'link' && !this.selectedId) {
                alert('Please search for and select an existing Azure AD group first.');
                return;
            }
            this.$el.submit();
        },
    };
}
</script>
@endpush

@endsection
