@extends('layouts.admin')

@section('content')

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3">Contacts Management</h1>
    <div class="d-flex gap-2">
        <a href="{{ route('admin.contacts.create') }}" class="btn btn-primary">
            <i class="bi bi-plus-circle"></i> Add Contact
        </a>
        <a href="{{ route('admin.contacts.export', request()->query()) }}" class="btn btn-success">
            <i class="bi bi-download"></i> Export Excel
        </a>
    </div>
</div>


<!-- Search Form -->
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" action="{{ route('admin.contacts.index') }}" class="row g-3">
            <div class="col-md-10">
                <input 
                    type="text" 
                    name="search" 
                    class="form-control" 
                    placeholder="Search by name, phone, or email..."
                    value="{{ request('search') }}"
                >
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-primary flex-fill">Search</button>
                @if(request('search'))
                    <a href="{{ route('admin.contacts.index') }}" class="btn btn-secondary">Clear</a>
                @endif
            </div>
        </form>
    </div>
</div>

<!-- Contacts Table -->
@if($contacts->count() > 0)
    <div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead class="table-light">
                        <tr>
                            <th>Name</th>
                            <th>Job Title</th>
                            <th>Phone</th>
                            <th>Email</th>
                            <th>Branch</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($contacts as $contact)
                            <tr>
                                <td>{{ $contact->first_name }} {{ $contact->last_name }}</td>
                                <td>{{ $contact->job_title ?? '-' }}</td>
                                <td>{{ $contact->phone }}</td>
                                <td>{{ $contact->email }}</td>
                                <td>{{ $contact->branch->name ?? '-' }}</td>
                                <td class="text-end">
                                    <a href="{{ route('admin.contacts.edit', $contact) }}" class="btn btn-sm btn-outline-primary">Edit</a>
                                    <form action="{{ route('admin.contacts.destroy', $contact) }}" method="POST" class="d-inline">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('Delete this contact?')">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Pagination -->
    <div class="mt-4 d-flex justify-content-center">
     {{ $contacts->withQueryString()->links('pagination::bootstrap-5') }}
    </div>
@else
    <div class="alert alert-info text-center">
        <p class="mb-0">No contacts found.</p>
    </div>
@endif

@endsection
