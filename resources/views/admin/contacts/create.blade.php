@extends('layouts.admin')

@section('content')
<h3>Add Contact</h3>

<form action="{{ route('admin.contacts.store') }}" method="POST">
    @csrf

    <div class="row">

        <div class="col-md-6 mb-3">
            <label class="form-label">First Name</label>
            <input type="text" name="first_name" class="form-control"
                   value="{{ old('first_name') }}" required>
            @error('first_name')
                <span class="text-danger small">{{ $message }}</span>
            @enderror
        </div>

        <div class="col-md-6 mb-3">
            <label class="form-label">Last Name (optional)</label>
            <input type="text" name="last_name" class="form-control"
                   value="{{ old('last_name') }}">
            @error('last_name')
                <span class="text-danger small">{{ $message }}</span>
            @enderror
        </div>
		<!-- Job Title -->
<div class="mb-3">
    <label for="job_title" class="form-label">Job Title</label>
    <input type="text" class="form-control @error('job_title') is-invalid @enderror" 
           id="job_title" name="job_title" value="{{ old('job_title', $contact->job_title ?? '') }}" 
           placeholder="e.g., Software Engineer, Sales Manager">
    @error('job_title')
        <div class="invalid-feedback">{{ $message }}</div>
    @enderror
</div>
        <div class="col-md-6 mb-3">
            <label class="form-label">Phone Number</label>
            <input type="text" name="phone" class="form-control"
                   value="{{ old('phone') }}" required>
            @error('phone')
                <span class="text-danger small">{{ $message }}</span>
            @enderror
        </div>

        <div class="col-md-6 mb-3">
            <label class="form-label">Email (optional)</label>
            <input type="email" name="email" class="form-control"
                   value="{{ old('email') }}">
            @error('email')
                <span class="text-danger small">{{ $message }}</span>
            @enderror
        </div>

        <div class="col-md-6 mb-3">
            <label class="form-label">Branch</label>
            <select name="branch_id" class="form-control" required>
                <option value="">Choose...</option>
                @foreach ($branches as $branch)
                    <option value="{{ $branch->id }}"
                        {{ old('branch_id') == $branch->id ? 'selected' : '' }}>
                        {{ $branch->name }}
                    </option>
                @endforeach
            </select>
            @error('branch_id')
                <span class="text-danger small">{{ $message }}</span>
            @enderror
        </div>

    </div>

    <button class="btn btn-success">Save</button>
    <a href="{{ route('admin.contacts.index') }}" class="btn btn-secondary">Cancel</a>

</form>
@endsection
