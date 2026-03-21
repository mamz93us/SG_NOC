@extends('layouts.admin')
@section('title', 'Branch / Department → Group Mappings')

@section('content')
<div class="container-fluid py-4">

  <div class="d-flex justify-content-between align-items-center mb-4">
    <div>
      <h4 class="mb-0 fw-bold">Group Auto-Assignment Mappings</h4>
      <small class="text-muted">Azure AD groups assigned automatically based on branch and department during user onboarding.</small>
    </div>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addMappingModal">
      <i class="bi bi-plus-lg me-1"></i> Add Mapping
    </button>
  </div>

  @if(session('success'))
    <div class="alert alert-success alert-dismissible fade show">{{ session('success') }} <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
  @endif

  <div class="card border-0 shadow-sm">
    <div class="card-body p-0">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th>Branch</th>
            <th>Department</th>
            <th>Azure Group</th>
            <th>Group ID</th>
            <th>Notes</th>
            <th class="text-end">Actions</th>
          </tr>
        </thead>
        <tbody>
          @forelse($mappings as $m)
          <tr>
            <td>
              @if($m->branch)
                <span class="badge bg-primary-subtle text-primary">{{ $m->branch->name }}</span>
              @else
                <span class="badge bg-secondary-subtle text-secondary">All Branches</span>
              @endif
            </td>
            <td>
              @if($m->department)
                <span class="badge bg-info-subtle text-info">{{ $m->department->name }}</span>
              @else
                <span class="badge bg-secondary-subtle text-secondary">All Departments</span>
              @endif
            </td>
            <td><strong>{{ $m->azure_group_name }}</strong></td>
            <td><code class="small">{{ Str::limit($m->azure_group_id, 20) }}</code></td>
            <td class="text-muted small">{{ $m->notes ?? '—' }}</td>
            <td class="text-end">
              <form method="POST" action="{{ route('admin.identity.group-mappings.destroy', $m) }}"
                    onsubmit="return confirm('Delete this mapping?')">
                @csrf @method('DELETE')
                <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
              </form>
            </td>
          </tr>
          @empty
          <tr>
            <td colspan="6" class="text-center py-5 text-muted">No mappings configured yet.</td>
          </tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </div>

  <!-- Preview widget -->
  <div class="card border-0 shadow-sm mt-4" x-data="groupPreview()">
    <div class="card-header bg-white fw-semibold">Group Preview</div>
    <div class="card-body">
      <div class="row g-3 align-items-end">
        <div class="col-md-4">
          <label class="form-label small">Branch</label>
          <select class="form-select form-select-sm" x-model="branchId" @change="load()">
            <option value="">— All Branches —</option>
            @foreach($branches as $b)
            <option value="{{ $b->id }}">{{ $b->name }}</option>
            @endforeach
          </select>
        </div>
        <div class="col-md-4">
          <label class="form-label small">Department</label>
          <select class="form-select form-select-sm" x-model="deptId" @change="load()">
            <option value="">— All Departments —</option>
            @foreach($departments as $d)
            <option value="{{ $d->id }}">{{ $d->name }}</option>
            @endforeach
          </select>
        </div>
      </div>
      <div class="mt-3" x-show="groups.length > 0">
        <p class="small text-muted mb-2">Groups that would be assigned:</p>
        <div class="d-flex flex-wrap gap-2">
          <template x-for="g in groups" :key="g.id">
            <span class="badge bg-success-subtle text-success border border-success-subtle" x-text="g.group_name"></span>
          </template>
        </div>
      </div>
      <p class="mt-3 small text-muted" x-show="groups.length === 0 && loaded">No groups match this combination.</p>
    </div>
  </div>

</div>

<!-- Add Mapping Modal -->
<div class="modal fade" id="addMappingModal" tabindex="-1">
  <div class="modal-dialog">
    <form method="POST" action="{{ route('admin.identity.group-mappings.store') }}" class="modal-content">
      @csrf
      <div class="modal-header">
        <h5 class="modal-title">Add Group Mapping</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label">Branch <span class="text-muted small">(leave blank = all branches)</span></label>
          <select name="branch_id" class="form-select">
            <option value="">All Branches</option>
            @foreach($branches as $b)
            <option value="{{ $b->id }}">{{ $b->name }}</option>
            @endforeach
          </select>
        </div>
        <div class="mb-3">
          <label class="form-label">Department <span class="text-muted small">(leave blank = all departments)</span></label>
          <select name="department_id" class="form-select">
            <option value="">All Departments</option>
            @foreach($departments as $d)
            <option value="{{ $d->id }}">{{ $d->name }}</option>
            @endforeach
          </select>
        </div>
        <div class="mb-3">
          <label class="form-label fw-semibold">Azure Group Name <span class="text-danger">*</span></label>
          <input type="text" name="azure_group_name" class="form-control" required placeholder="e.g. All-Sales-Staff">
        </div>
        <div class="mb-3">
          <label class="form-label fw-semibold">Azure Group ID (Object ID) <span class="text-danger">*</span></label>
          <input type="text" name="azure_group_id" class="form-control font-monospace" required
                 placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx">
          <div class="form-text">Find in Azure Portal → Groups → Group → Object ID</div>
        </div>
        <div class="mb-3">
          <label class="form-label">Notes</label>
          <input type="text" name="notes" class="form-control" placeholder="Optional description">
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary">Save Mapping</button>
      </div>
    </form>
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
            const params = new URLSearchParams();
            if (this.branchId) params.set('branch_id', this.branchId);
            if (this.deptId)   params.set('department_id', this.deptId);

            const r = await fetch('{{ route("admin.identity.group-mappings.preview") }}?' + params);
            this.groups = await r.json();
            this.loaded = true;
        }
    };
}
</script>
@endpush
@endsection
