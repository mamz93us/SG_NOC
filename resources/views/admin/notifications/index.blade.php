@extends('layouts.admin')
@section('content')

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-bell-fill me-2 text-primary"></i>Notifications</h4>
        <small class="text-muted">Your notification history</small>
    </div>
    <div class="d-flex gap-2">
        <a href="{{ route('admin.notifications.settings') }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-gear me-1"></i>Preferences
        </a>
        <form method="POST" action="{{ route('admin.notifications.read-all') }}">
            @csrf
            <button type="submit" class="btn btn-outline-primary btn-sm">
                <i class="bi bi-check-all me-1"></i>Mark All Read
            </button>
        </form>
    </div>
</div>

@if(session('success'))
<div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle me-2"></i>{{ session('success') }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
@endif

<div class="card shadow-sm border-0">
    <div class="card-body p-0">
        @if($notifications->isEmpty())
        <div class="text-center py-5 text-muted">
            <i class="bi bi-bell-slash-fill display-4 d-block mb-2"></i>No notifications yet.
        </div>
        @else
        @foreach($notifications as $notif)
        <div class="d-flex align-items-start gap-3 px-3 py-3 border-bottom {{ !$notif->is_read ? 'bg-light' : '' }}">
            <div class="mt-1 flex-shrink-0">
                <i class="{{ $notif->severityIcon() }} fs-5"></i>
            </div>
            <div class="flex-grow-1">
                <div class="d-flex align-items-center gap-2 mb-1">
                    <span class="fw-semibold small">{{ $notif->title }}</span>
                    @if(!$notif->is_read)
                    <span class="badge bg-primary" style="font-size:.65rem">New</span>
                    @endif
                    <span class="ms-auto text-muted" style="font-size:.75rem">{{ $notif->created_at->diffForHumans() }}</span>
                </div>
                <p class="text-muted small mb-1">{{ $notif->message }}</p>
                @if($notif->link)
                <a href="{{ $notif->link }}" class="small text-primary"><i class="bi bi-arrow-right me-1"></i>View details</a>
                @endif
            </div>
        </div>
        @endforeach
        <div class="px-3 py-2 border-top">{{ $notifications->links() }}</div>
        @endif
    </div>
</div>
@endsection
