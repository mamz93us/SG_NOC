@extends('layouts.admin')

@section('content')
<h3>Edit Contact</h3>

<form action="{{ route('admin.contacts.update', $contact->id) }}" method="POST" data-contact-id="{{ $contact->id }}">
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

        <!-- Job Title Field - NEW -->
        <div class="col-md-6 mb-3">
            <label class="form-label">Job Title</label>
            <input type="text" name="job_title" class="form-control"
                   value="{{ old('job_title', $contact->job_title) }}" 
                   placeholder="e.g., Software Engineer, Sales Manager">
            @error('job_title')
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
            <input type="email" name="email" id="email" class="form-control"
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

    <button type="submit" class="btn btn-primary">Update</button>
    <a href="{{ route('admin.contacts.index') }}" class="btn btn-secondary">Cancel</a>

</form>

<!-- Duplicate Email Detection Script -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    const emailInput = document.getElementById('email');
    const form = document.querySelector('form');
    
    let duplicateWarningDiv = null;

    // Check for duplicates when email field loses focus
    if (emailInput) {
        emailInput.addEventListener('blur', function() {
            checkDuplicate();
        });
    }

    function checkDuplicate() {
        const email = emailInput.value.trim();
        
        // Remove existing warning if present
        if (duplicateWarningDiv) {
            duplicateWarningDiv.remove();
            duplicateWarningDiv = null;
        }

        if (!email) return;

        // Get contact ID for edit mode (to exclude current record)
        const contactId = form.dataset.contactId || null;

        fetch('{{ route('admin.contacts.check-duplicate') }}', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
            },
            body: JSON.stringify({
                email: email,
                exclude_id: contactId
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.exists) {
                showDuplicateWarning(data.contact);
            }
        })
        .catch(error => {
            console.error('Error checking duplicate:', error);
        });
    }

    function showDuplicateWarning(contact) {
        duplicateWarningDiv = document.createElement('div');
        duplicateWarningDiv.className = 'alert alert-warning mt-2';
        duplicateWarningDiv.innerHTML = `
            <strong>⚠️ Duplicate Email Detected!</strong><br>
            This email already exists for: <strong>${contact.name}</strong><br>
            Phone: ${contact.phone} | Branch: ${contact.branch}
        `;
        
        emailInput.parentNode.appendChild(duplicateWarningDiv);
    }

    // Add confirmation on form submit if duplicate exists
    form.addEventListener('submit', function(e) {
        if (duplicateWarningDiv) {
            if (!confirm('This email already exists for another contact. Do you want to continue anyway?')) {
                e.preventDefault();
            }
        }
    });
});
</script>

@endsection
