@extends('layouts.admin')

@section('content')
<h3>Edit Contact</h3>

<form action="{{ route('admin.contacts.update', $contact->id) }}" method="POST">
    @csrf
    @method('PUT')

    <div class="row">

        <div class="col-md-6 mb-3">
            <label class="form-label">First Name</label>
            <input type="text" name="first_name" class="form-control"
                   value="{{ old('first_name', $contact->first_name) }}" required>
            @error('first_name')
                <span class="text-danger small">{{ $message }}</span>
            @enderror
        </div>

        <div class="col-md-6 mb-3">
            <label class="form-label">Last Name</label>
            <input type="text" name="last_name" class="form-control"
                   value="{{ old('last_name', $contact->last_name) }}">
            @error('last_name')
                <span class="text-danger small">{{ $message }}</span>
            @enderror
        </div>

        <div class="col-md-6 mb-3">
            <label class="form-label">Phone Number</label>
            <input type="text" name="phone" class="form-control"
                   value="{{ old('phone', $contact->phone) }}" required>
            @error('phone')
                <span class="text-danger small">{{ $message }}</span>
            @enderror
        </div>

        <div class="col-md-6 mb-3">
            <label class="form-label">Email</label>
            <input type="email" name="email" class="form-control"
                   value="{{ old('email', $contact->email) }}">
            @error('email')
                <span class="text-danger small">{{ $message }}</span>
            @enderror
        </div>

        <div class="col-md-6 mb-3">
            <label class="form-label">Branch</label>
            <select name="branch_id" class="form-control" required>
                @foreach ($branches as $branch)
                    <option value="{{ $branch->id }}"
                        {{ $contact->branch_id == $branch->id ? 'selected' : '' }}>
                        {{ $branch->name }}
                    </option>
                @endforeach
            </select>
            @error('branch_id')
                <span class="text-danger small">{{ $message }}</span>
            @enderror
        </div>

    </div>

    <button class="btn btn-primary">Update</button>
    <a href="{{ route('admin.contacts.index') }}" class="btn btn-secondary">Cancel</a>

</form>
@endsection