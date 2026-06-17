@extends('layouts.marketing')

@section('title', $list->name)

@section('content')
<div class="container-fluid py-4">
    <h3 class="mb-3"><i class="bi bi-envelope-paper me-2"></i>Email Marketing</h3>
    @include('portal.email-marketing._nav')

    @if (session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif
    @if (session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif

    @if ($list->isDynamic())
        <div class="alert alert-info d-flex align-items-start mb-3">
            <i class="bi bi-arrow-repeat me-2 mt-1"></i>
            <div>
                <strong>Dynamic list.</strong>
                Membership is auto-synced from active employees whose email ends with
                <code>&#64;{{ $list->auto_domain }}</code>. New employees are added automatically;
                terminated employees are removed. Manual subscriber edits made here will be
                overwritten by the next sync.
                <div class="mt-2">
                    <form method="POST" action="{{ route('portal.marketing.lists.sync', $list) }}" class="d-inline">
                        @csrf
                        <button class="btn btn-sm btn-info">
                            <i class="bi bi-arrow-repeat me-1"></i>Sync now
                        </button>
                    </form>
                    <small class="text-muted ms-1">Reconcile members from employees on <code>&#64;{{ $list->auto_domain }}</code> right now.</small>
                </div>
            </div>
        </div>
    @endif

    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="mb-0">{{ $list->name }} <small class="text-muted">({{ $list->subscribers_count }} subscribers)</small></h4>
        <div>
            @unless ($list->isDynamic())
                <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="collapse" data-bs-target="#addSubscriber">
                    <i class="bi bi-person-plus me-1"></i>Add subscriber
                </button>
                <a href="{{ route('portal.marketing.subscribers.import.form') }}" class="btn btn-outline-primary btn-sm">
                    <i class="bi bi-upload me-1"></i>Import CSV
                </a>
            @endunless
            <a href="{{ route('portal.marketing.lists.export', $list) }}" class="btn btn-outline-success btn-sm">
                <i class="bi bi-download me-1"></i>Export CSV
            </a>
            <a href="{{ route('portal.marketing.lists.edit', $list) }}" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-pencil me-1"></i>Edit
            </a>
            @unless ($list->isDynamic())
                <form method="POST" action="{{ route('portal.marketing.lists.destroy', $list) }}" class="d-inline"
                      onsubmit="return confirm('Delete this list? Subscribers are kept.')">
                    @csrf @method('DELETE')
                    <button class="btn btn-outline-danger btn-sm"><i class="bi bi-trash"></i></button>
                </form>
            @endunless
        </div>
    </div>

    @unless ($list->isDynamic())
    <div class="collapse mb-3 {{ $errors->any() ? 'show' : '' }}" id="addSubscriber">
        <div class="card card-body shadow-sm">
            <ul class="nav nav-pills nav-sm mb-3" role="tablist">
                <li class="nav-item"><button class="nav-link active py-1 px-3" data-bs-toggle="tab" data-bs-target="#tab-new" type="button"><i class="bi bi-person-plus me-1"></i>New</button></li>
                <li class="nav-item"><button class="nav-link py-1 px-3" data-bs-toggle="tab" data-bs-target="#tab-existing" type="button"><i class="bi bi-people me-1"></i>From existing</button></li>
            </ul>
            <div class="tab-content">
                {{-- New subscriber --}}
                <div class="tab-pane fade show active" id="tab-new">
                    <form method="POST" action="{{ route('portal.marketing.lists.add-subscriber', $list) }}" class="row g-2 align-items-end">
                        @csrf
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold mb-1">First name</label>
                            <input type="text" name="first_name" class="form-control form-control-sm" value="{{ old('first_name') }}">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold mb-1">Last name</label>
                            <input type="text" name="last_name" class="form-control form-control-sm" value="{{ old('last_name') }}">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold mb-1">Email <span class="text-danger">*</span></label>
                            <input type="email" name="email" class="form-control form-control-sm @error('email') is-invalid @enderror"
                                   value="{{ old('email') }}" required>
                            @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-success btn-sm w-100"><i class="bi bi-plus-lg me-1"></i>Add</button>
                        </div>
                    </form>
                </div>

                {{-- Existing subscribers --}}
                <div class="tab-pane fade" id="tab-existing">
                    @if($candidates->isEmpty())
                        <p class="text-muted small mb-0">All existing subscribers are already on this list (or none exist yet).</p>
                    @else
                    <form method="POST" action="{{ route('portal.marketing.lists.attach-existing', $list) }}">
                        @csrf
                        <label class="form-label small fw-semibold mb-1">Pick existing subscribers <small class="text-muted">(Ctrl/Cmd-click for multiple)</small></label>
                        <input type="text" class="form-control form-control-sm mb-2" placeholder="Filter by name or email…"
                               onkeyup="filterSubs(this.value)">
                        <select name="subscriber_ids[]" id="subCandidates" class="form-select" multiple size="8">
                            @foreach($candidates as $c)
                            @php $nm = trim(($c->first_name ?? '').' '.($c->last_name ?? '')); @endphp
                            <option value="{{ $c->id }}" data-search="{{ strtolower($nm.' '.$c->email) }}">
                                {{ $c->email }}{{ $nm ? ' — '.$nm : '' }}
                            </option>
                            @endforeach
                        </select>
                        <div class="form-text mb-2">Showing up to {{ $candidates->count() }} subscriber(s) not on this list.</div>
                        <button type="submit" class="btn btn-success btn-sm"><i class="bi bi-plus-lg me-1"></i>Add selected</button>
                    </form>
                    <script>
                      function filterSubs(q){ q=q.toLowerCase(); document.querySelectorAll('#subCandidates option').forEach(function(o){ o.hidden = q && o.dataset.search.indexOf(q)===-1; }); }
                    </script>
                    @endif
                </div>
            </div>
        </div>
    </div>
    @endunless

    <div class="card shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Email</th><th>Name</th><th>Status</th><th>Joined</th>
                    </tr>
                </thead>
                <tbody>
                @forelse ($subscribers as $s)
                    <tr>
                        <td><a href="{{ route('portal.marketing.subscribers.edit', $s) }}">{{ $s->email }}</a></td>
                        <td>{{ trim(($s->first_name ?? '').' '.($s->last_name ?? '')) }}</td>
                        <td>
                            <span class="badge bg-{{ $s->status === 'subscribed' ? 'success' : ($s->status === 'pending' ? 'warning' : 'secondary') }}">
                                {{ $s->status }}
                            </span>
                        </td>
                        <td><small>{{ optional($s->pivot->subscribed_at)?->diffForHumans() ?: 'pending' }}</small></td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="text-center text-muted py-4">No subscribers in this list yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer">{{ $subscribers->links() }}</div>
    </div>
</div>
@endsection
