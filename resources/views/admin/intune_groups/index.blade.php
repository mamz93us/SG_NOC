@extends('layouts.admin')
@section('content')

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-collection me-2 text-primary"></i>Intune Groups</h4>
        <small class="text-muted">Azure AD security groups for Intune printer deployment</small>
    </div>
    <a href="{{ route('admin.intune-groups.create') }}" class="btn btn-primary btn-sm">
        <i class="bi bi-plus-lg me-1"></i>New Group
    </a>
</div>

@if(session('success'))
<div class="alert alert-success alert-dismissible fade show py-2"><i class="bi bi-check-circle me-1"></i>{{ session('success') }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
@endif
@if($errors->any())
<div class="alert alert-danger alert-dismissible fade show py-2"><i class="bi bi-exclamation-triangle me-1"></i>{{ $errors->first() }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
@endif

<div class="card shadow-sm">
    <div class="card-body p-0">
        @if($groups->isEmpty())
        <div class="text-center py-5 text-muted">
            <i class="bi bi-collection display-4 d-block mb-2"></i>
            No Intune groups yet.
            <a href="{{ route('admin.intune-groups.create') }}">Create your first group</a>.
        </div>
        @else
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 small">
                <thead class="table-light">
                    <tr>
                        <th>Name</th>
                        <th>Type</th>
                        <th>Branch</th>
                        <th>Department</th>
                        <th class="text-center">Members</th>
                        <th class="text-center">Policies</th>
                        <th>Azure Group ID</th>
                        <th>Sync</th>
                        <th>Last Synced</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($groups as $g)
                    <tr>
                        <td class="fw-semibold">{{ $g->name }}</td>
                        <td><span class="badge {{ $g->groupTypeBadgeClass() }}">{{ ucfirst($g->group_type) }}</span></td>
                        <td>{{ $g->branch?->name ?? '—' }}</td>
                        <td>{{ $g->department?->name ?? '—' }}</td>
                        <td class="text-center"><span class="badge bg-light text-dark border">{{ $g->members_count }}</span></td>
                        <td class="text-center"><span class="badge bg-light text-dark border">{{ $g->policies_count }}</span></td>
                        <td class="font-monospace text-muted small">{{ $g->azure_group_id ? substr($g->azure_group_id, 0, 8).'…' : '—' }}</td>
                        <td><span class="badge {{ $g->syncStatusBadgeClass() }}">{{ ucfirst($g->sync_status) }}</span></td>
                        <td class="text-muted">{{ $g->last_synced_at?->diffForHumans() ?? '—' }}</td>
                        <td class="text-nowrap">
                            <a href="{{ route('admin.intune-groups.show', $g) }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye"></i></a>
                            <form action="{{ route('admin.intune-groups.destroy', $g) }}" method="POST" class="d-inline"
                                  onsubmit="return confirm('Delete group \'{{ addslashes($g->name) }}\'? This will also delete the Azure AD group.');">
                                @csrf @method('DELETE')
                                <button class="btn btn-sm btn-outline-danger" title="Delete"><i class="bi bi-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>
</div>

@endsection
