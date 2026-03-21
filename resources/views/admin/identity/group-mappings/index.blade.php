@extends('layouts.admin')
@section('title', 'Branch / Department → Group Mappings')

@section('content')
<div class="container-fluid py-4">

  <div class="d-flex justify-content-between align-items-center mb-4">
    <div>
      <h4 class="mb-0 fw-bold"><i class="bi bi-diagram-3 me-2 text-primary"></i>Group Auto-Assignment Mappings</h4>
      <small class="text-muted">Azure AD groups assigned automatically based on branch and department during user onboarding.</small>
    </div>
    <a href="{{ route('admin.identity.group-mappings.create') }}" class="btn btn-primary">
      <i class="bi bi-plus-lg me-1"></i> Add Mapping
    </a>
  </div>

  @if(session('success'))
    <div class="alert alert-success alert-dismissible fade show shadow-sm">
      <i class="bi bi-check-circle me-1"></i>{{ session('success') }}
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  @endif

  <div class="card border-0 shadow-sm mb-4">
    <div class="card-body p-0">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th class="ps-4">Branch</th>
            <th>Department</th>
            <th>Azure Group</th>
            <th>Type</th>
            <th>Status</th>
            <th>Notes</th>
            <th class="text-end pe-4">Actions</th>
          </tr>
        </thead>
        <tbody>
          @forelse($mappings as $m)
          <tr>
            <td class="ps-4">
              @if($m->branch)
                <span class="badge bg-primary-subtle text-primary border border-primary-subtle">{{ $m->branch->name }}</span>
              @else
                <span class="badge bg-secondary-subtle text-secondary">Any Branch</span>
              @endif
            </td>
            <td>
              @if($m->department)
                <span class="badge bg-info-subtle text-info border border-info-subtle">{{ $m->department->name }}</span>
              @else
                <span class="badge bg-secondary-subtle text-secondary">Any Department</span>
              @endif
            </td>
            <td>
              @if($m->identityGroup)
                <strong>{{ $m->identityGroup->display_name }}</strong>
              @else
                <span class="text-danger small"><i class="bi bi-exclamation-triangle me-1"></i>Group deleted</span>
              @endif
            </td>
            <td>
              @if($m->identityGroup)
                <span class="badge {{ $m->identityGroup->typeBadgeClass() }} small">{{ $m->identityGroup->typeLabel() }}</span>
              @else
                —
              @endif
            </td>
            <td>
              @if($m->is_active)
                <span class="badge bg-success">Active</span>
              @else
                <span class="badge bg-secondary">Inactive</span>
              @endif
            </td>
            <td class="text-muted small">{{ $m->notes ?? '—' }}</td>
            <td class="text-end pe-4">
              <form method="POST" action="{{ route('admin.identity.group-mappings.destroy', $m) }}"
                    onsubmit="return confirm('Delete this mapping?')">
                @csrf @method('DELETE')
                <button class="btn btn-sm btn-outline-danger" title="Delete">
                  <i class="bi bi-trash"></i>
                </button>
              </form>
            </td>
          </tr>
          @empty
          <tr>
            <td colspan="7" class="text-center py-5 text-muted">
              <i class="bi bi-diagram-3 d-block mb-2" style="font-size:2rem;opacity:.3;"></i>
              No mappings configured yet.
              <a href="{{ route('admin.identity.group-mappings.create') }}" class="d-block mt-2">Add your first mapping →</a>
            </td>
          </tr>
          @endforelse
        </tbody>
      </table>
    </div>
    @if($mappings->hasPages())
    <div class="card-footer bg-white border-top-0 py-2">
      {{ $mappings->links() }}
    </div>
    @endif
  </div>

  <!-- Live preview widget -->
  <div class="card border-0 shadow-sm" x-data="groupPreview()">
    <div class="card-header bg-white fw-semibold">
      <i class="bi bi-search me-1"></i>Preview — What groups would be assigned?
    </div>
    <div class="card-body">
      <div class="row g-3 align-items-end">
        <div class="col-md-4">
          <label class="form-label small fw-semibold">Branch</label>
          <select class="form-select form-select-sm" x-model="branchId" @change="load()">
            <option value="">— All Branches —</option>
            @foreach(\App\Models\Branch::orderBy('name')->get() as $b)
            <option value="{{ $b->id }}">{{ $b->name }}</option>
            @endforeach
          </select>
        </div>
        <div class="col-md-4">
          <label class="form-label small fw-semibold">Department</label>
          <select class="form-select form-select-sm" x-model="deptId" @change="load()">
            <option value="">— All Departments —</option>
            @foreach(\App\Models\Department::orderBy('name')->get() as $d)
            <option value="{{ $d->id }}">{{ $d->name }}</option>
            @endforeach
          </select>
        </div>
      </div>
      <div class="mt-3" x-show="groups.length > 0" x-cloak>
        <p class="small text-muted mb-2">Groups that would be auto-assigned:</p>
        <div class="d-flex flex-wrap gap-2">
          <template x-for="g in groups" :key="g.id">
            <span class="badge bg-success-subtle text-success border border-success-subtle px-3 py-2">
              <i class="bi bi-people-fill me-1"></i><span x-text="g.name"></span>
            </span>
          </template>
        </div>
      </div>
      <p class="mt-3 small text-muted" x-show="groups.length === 0 && loaded" x-cloak>
        <i class="bi bi-info-circle me-1"></i>No groups match this combination.
      </p>
    </div>
  </div>

</div>

@push('scripts')
<script>
function groupPreview() {
    return {
        branchId: '',
        deptId:   '',
        groups:   [],
        loaded:   false,

        async load() {
            this.loaded = false;
            const params = new URLSearchParams();
            if (this.branchId) params.set('branch_id', this.branchId);
            if (this.deptId)   params.set('department_id', this.deptId);

            try {
                const r    = await fetch('{{ route("admin.identity.group-mappings.preview") }}?' + params);
                this.groups = await r.json();
            } catch (e) {
                this.groups = [];
            }
            this.loaded = true;
        }
    };
}
</script>
@endpush
@endsection
