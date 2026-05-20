@extends('layouts.admin')

@section('title', 'Email Marketing — Sender allowlist')

@section('content')
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h3 class="mb-0"><i class="bi bi-person-badge me-2"></i>Email Marketing — Sender allowlist</h3>
        <a href="{{ route('admin.email-marketing.settings') }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Back to Settings
        </a>
    </div>

    @if (session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif
    @if ($errors->any())
        <div class="alert alert-danger"><ul class="mb-0">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
    @endif

    <div class="alert alert-info py-2">
        <i class="bi bi-info-circle me-1"></i>
        These are the only addresses marketing users will be able to pick as the
        campaign "From" address. Every entry must be a <strong>verified</strong> SES identity
        (domain or single address) in the configured region — otherwise SES will reject the send.
    </div>

    {{-- ── Add new sender ──────────────────────────────────────── --}}
    <form method="POST" action="{{ route('admin.email-marketing.senders.store') }}" class="card shadow-sm mb-4">
        @csrf
        <div class="card-header bg-light"><strong><i class="bi bi-plus-circle me-1"></i>Add allowed sender</strong></div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">From email</label>
                    <input type="email" name="email" class="form-control" required placeholder="market@samirgroup.com" value="{{ old('email') }}">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Display name</label>
                    <input type="text" name="name" class="form-control" required placeholder="Samir Group Marketing" value="{{ old('name') }}">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Reply-to (optional)</label>
                    <input type="email" name="reply_to" class="form-control" placeholder="info@samirgroup.com" value="{{ old('reply_to') }}">
                </div>
                <div class="col-md-9">
                    <label class="form-label">Notes (optional)</label>
                    <input type="text" name="notes" class="form-control" placeholder="e.g. DKIM verified 2026-05-19; intended for transactional only" value="{{ old('notes') }}">
                </div>
                <div class="col-md-3 d-flex align-items-end gap-3">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="is_default" name="is_default" value="1">
                        <label class="form-check-label" for="is_default">Set as default</label>
                    </div>
                    <button class="btn btn-primary ms-auto"><i class="bi bi-plus me-1"></i>Add</button>
                </div>
            </div>
        </div>
    </form>

    {{-- ── Existing senders ────────────────────────────────────── --}}
    <div class="card shadow-sm">
        <div class="card-header bg-light"><strong>Allowlist</strong></div>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>From email</th>
                        <th>Display name</th>
                        <th>Reply-to</th>
                        <th>Notes</th>
                        <th>Status</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                @forelse ($identities as $i)
                    <tr>
                        <td>
                            <code>{{ $i->email }}</code>
                            @if ($i->is_default)<span class="badge bg-primary ms-1">default</span>@endif
                        </td>
                        <td>{{ $i->name }}</td>
                        <td><small><code>{{ $i->reply_to ?: '—' }}</code></small></td>
                        <td><small class="text-muted">{{ $i->notes }}</small></td>
                        <td>
                            @if ($i->is_active)
                                <span class="badge bg-success">Active</span>
                            @else
                                <span class="badge bg-secondary">Disabled</span>
                            @endif
                        </td>
                        <td class="text-end">
                            @unless ($i->is_default)
                                <form method="POST" action="{{ route('admin.email-marketing.senders.default', $i) }}" class="d-inline">
                                    @csrf
                                    <button class="btn btn-sm btn-outline-primary" title="Make default"><i class="bi bi-star"></i></button>
                                </form>
                            @endunless
                            <form method="POST" action="{{ route('admin.email-marketing.senders.destroy', $i) }}" class="d-inline"
                                  onsubmit="return confirm('Remove {{ $i->email }} from the allowlist?')">
                                @csrf @method('DELETE')
                                <button class="btn btn-sm btn-outline-danger" title="Remove"><i class="bi bi-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-center text-muted py-4">No senders yet — add one above.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
