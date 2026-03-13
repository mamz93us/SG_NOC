@extends('layouts.admin')
@section('title', 'Suppliers')

@section('content')
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0"><i class="bi bi-building me-2"></i>Suppliers</h4>
        <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addSupplierModal">
            <i class="bi bi-plus-lg me-1"></i>Add Supplier
        </button>
    </div>

    @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
    @if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif

    {{-- Search --}}
    <form method="GET" class="mb-3">
        <div class="input-group" style="max-width:400px">
            <input type="text" name="search" class="form-control" placeholder="Search suppliers..." value="{{ request('search') }}">
            <button type="submit" class="btn btn-outline-secondary"><i class="bi bi-search"></i></button>
            @if(request('search'))<a href="{{ route('admin.itam.suppliers.index') }}" class="btn btn-outline-secondary">Clear</a>@endif
        </div>
    </form>

    <div class="card shadow-sm border-0">
        <div class="card-body p-0">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Name</th>
                        <th>Contact</th>
                        <th>Email</th>
                        <th>Phone</th>
                        <th class="text-center">Assets</th>
                        <th class="text-end">Total Spend</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($suppliers as $supplier)
                    <tr>
                        <td class="fw-semibold">{{ $supplier->name }}</td>
                        <td>{{ $supplier->contact_person ?: '—' }}</td>
                        <td>{{ $supplier->email ? "<a href='mailto:{$supplier->email}'>{$supplier->email}</a>" : '—' }}</td>
                        <td class="font-monospace">{{ $supplier->phone ?: '—' }}</td>
                        <td class="text-center">
                            <span class="badge bg-primary">{{ $supplier->devices_count }}</span>
                        </td>
                        <td class="text-end font-monospace">${{ number_format($supplier->totalSpend(), 0) }}</td>
                        <td class="text-end">
                            <button class="btn btn-sm btn-outline-secondary"
                                onclick="editSupplier({{ json_encode($supplier) }})"
                                data-bs-toggle="modal" data-bs-target="#editSupplierModal">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <form action="{{ route('admin.itam.suppliers.destroy', $supplier) }}" method="POST" class="d-inline"
                                  onsubmit="return confirm('Delete supplier {{ addslashes($supplier->name) }}?')">
                                @csrf @method('DELETE')
                                <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="7" class="text-center text-muted py-4">No suppliers found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <div class="mt-3">{{ $suppliers->links() }}</div>
</div>

{{-- Add Modal --}}
<div class="modal fade" id="addSupplierModal" tabindex="-1">
    <div class="modal-dialog">
        <form action="{{ route('admin.itam.suppliers.store') }}" method="POST">
            @csrf
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title fw-semibold"><i class="bi bi-plus-circle me-1"></i>Add Supplier</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    @include('admin.itam.suppliers._form')
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save</button>
                </div>
            </div>
        </form>
    </div>
</div>

{{-- Edit Modal --}}
<div class="modal fade" id="editSupplierModal" tabindex="-1">
    <div class="modal-dialog">
        <form id="editSupplierForm" method="POST">
            @csrf @method('PUT')
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title fw-semibold"><i class="bi bi-pencil me-1"></i>Edit Supplier</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    @include('admin.itam.suppliers._form')
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update</button>
                </div>
            </div>
        </form>
    </div>
</div>
@endsection

@push('scripts')
<script>
function editSupplier(s) {
    const form = document.getElementById('editSupplierForm');
    form.action = `/admin/itam/suppliers/${s.id}`;
    form.querySelector('[name=name]').value = s.name || '';
    form.querySelector('[name=contact_person]').value = s.contact_person || '';
    form.querySelector('[name=email]').value = s.email || '';
    form.querySelector('[name=phone]').value = s.phone || '';
    form.querySelector('[name=address]').value = s.address || '';
    form.querySelector('[name=notes]').value = s.notes || '';
}
</script>
@endpush
