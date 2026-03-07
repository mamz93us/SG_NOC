@extends('layouts.admin')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <h3>Contacts</h3>
    <a href="{{ route('admin.contacts.create') }}" class="btn btn-primary">Add Contact</a>
</div>

<form method="GET" class="mb-3">
    <input type="text" name="search" class="form-control"
           placeholder="Search contacts..." value="{{ request('search') }}">
</form>

<table class="table table-bordered">
    <thead>
        <tr>
            <th>Name</th>
            <th>Phone</th>
            <th>Email</th>
            <th>Branch</th>
            <th width="200">Actions</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($contacts as $contact)
            <tr>
                <td>{{ $contact->first_name }} {{ $contact->last_name }}</td>
                <td>{{ $contact->phone }}</td>
                <td>{{ $contact->email }}</td>
                <td>{{ $contact->branch->name ?? '-' }}</td>
                <td>
                    <a href="{{ route('admin.contacts.edit', $contact->id) }}" class="btn btn-sm btn-primary">Edit</a>

                    <form action="{{ route('admin.contacts.destroy', $contact->id) }}"
                        method="POST" class="d-inline"
                        onsubmit="return confirm('Are you sure you want to delete this contact?');">
                        @csrf
                        @method('DELETE')
                        <button class="btn btn-sm btn-danger">Delete</button>
                    </form>

                </td>
            </tr>
        @empty
            <tr>
                <td colspan="5" class="text-center">No contacts found.</td>
            </tr>
        @endforelse
    </tbody>
</table>

<div class="mt-3">
    {{ $contacts->links('pagination::bootstrap-5') }}
</div>
@endsection
