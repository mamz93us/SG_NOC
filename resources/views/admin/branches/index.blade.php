@extends('layouts.admin')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <h3>Branches</h3>
    <a href="{{ route('admin.branches.create') }}" class="btn btn-primary">Add Branch</a>
</div>

<table class="table table-bordered">
    <thead>
        <tr>
            <th width="80">ID</th>
            <th>Name</th>
            <th width="200">Actions</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($branches as $branch)
            <tr>
                <td>{{ $branch->id }}</td>
                <td>{{ $branch->name }}</td>
                <td>
                    <a href="{{ route('admin.branches.edit', $branch) }}" class="btn btn-sm btn-warning">Edit</a>

                    <form action="{{ route('admin.branches.destroy', $branch) }}" method="POST"
                          class="d-inline"
                          onsubmit="return confirm('Delete this branch? This will remove all contacts under it.');">
                        @csrf
                        @method('DELETE')
                        <button class="btn btn-sm btn-danger">Delete</button>
                    </form>
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="3" class="text-center">No branches found.</td>
            </tr>
        @endforelse
    </tbody>
</table>

{{ $branches->links() }}
@endsection
