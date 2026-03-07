@extends('layouts.admin')
@section('content')

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-bell-fill me-2 text-primary"></i>Notification Preferences</h4>
        <small class="text-muted">Control how you receive notifications</small>
    </div>
    <a href="{{ route('admin.notifications.index') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Back</a>
</div>

@if(session('success'))
<div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle me-2"></i>{{ session('success') }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
@endif

<div class="row justify-content-center">
    <div class="col-12 col-md-6">
        <div class="card shadow-sm border-0">
            <div class="card-body p-4">
                <form method="POST" action="{{ route('admin.notifications.settings.update') }}">
                    @csrf @method('PUT')
                    <div class="mb-4">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="notify_in_app" id="notify_in_app" value="1" {{ $preferences->notify_in_app ? 'checked' : '' }}>
                            <label class="form-check-label fw-semibold" for="notify_in_app">
                                <i class="bi bi-bell me-1"></i>In-App Notifications
                            </label>
                            <div class="text-muted small mt-1">Show notification bell in the navbar with unread count</div>
                        </div>
                    </div>
                    <div class="mb-4">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="notify_email" id="notify_email" value="1" {{ $preferences->notify_email ? 'checked' : '' }}>
                            <label class="form-check-label fw-semibold" for="notify_email">
                                <i class="bi bi-envelope me-1"></i>Email Notifications
                            </label>
                            <div class="text-muted small mt-1">Receive email alerts for approvals, completions, and critical events</div>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Save Preferences</button>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
