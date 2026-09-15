@extends('layouts.admin')

@section('title', 'AI Access')

@section('content')
<div class="mb-3">
    <h4 class="mb-0 fw-bold"><i class="bi bi-shield-check me-2 text-primary"></i>AI access</h4>
    <small class="text-muted">
        Who may use the AI features that read restricted data. Access here is the same permission shown under
        Users → Permissions: giving it adds a grant, and removing it takes the grant away, or blocks it when the
        person's role includes it. Every change is logged as a security event.
    </small>
</div>

<div class="card shadow-sm border-0 mb-3">
    <div class="card-body d-flex gap-3 align-items-start">
        <i class="bi bi-chat-dots fs-4 text-primary"></i>
        <div>
            <div class="fw-semibold">Samir AI Assistant <span class="badge bg-light text-body border ms-1">Every employee</span></div>
            <div class="small text-muted">
                Everyone who signs in to the home portal can ask the assistant about IT, HR, their own data and company
                information. The features below add tools to it only for the people listed.
            </div>
        </div>
    </div>
</div>

@foreach ($features as $feature)
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-header bg-transparent">
            <div class="fw-semibold"><i class="bi bi-person-check me-1"></i>{{ $feature['name'] }}</div>
            <div class="small text-muted">{{ $feature['description'] }}</div>
        </div>
        <div class="card-body border-bottom">
            <form method="POST" action="{{ route('admin.ai-assistant.access.store') }}" class="row g-2 align-items-end">
                @csrf
                <input type="hidden" name="feature" value="{{ $feature['key'] }}">
                <div class="col-md-6">
                    <label class="form-label small mb-1">Give access to</label>
                    <input name="user" list="ai-access-users" class="form-control form-control-sm" autocomplete="off" required
                           placeholder="Type a name or email…">
                </div>
                <div class="col-md-3">
                    <button class="btn btn-sm btn-primary"><i class="bi bi-plus-lg me-1"></i>Give access</button>
                </div>
            </form>
            <div class="small text-muted mt-2">Someone who has never signed in is not in the list: add them first under Users → Add User → From Entra.</div>
        </div>
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">Person</th>
                        <th>Access through</th>
                        <th class="pe-3" style="width:150px"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($feature['holders'] as $row)
                        @php $person = $row['user']; @endphp
                        <tr class="{{ $row['blocked'] ? 'opacity-75' : '' }}">
                            <td class="ps-3">
                                <div class="fw-semibold">{{ $person->name }}</div>
                                <div class="small text-muted">{{ $person->email }}</div>
                            </td>
                            <td>
                                @if ($row['blocked'])
                                    <span class="badge bg-danger-subtle text-danger border border-danger-subtle">Blocked</span>
                                    <span class="small text-muted">their role, {{ \App\Models\User::roleLabel($person->role) }}, includes it</span>
                                @elseif ($row['through'] === 'super_admin')
                                    <span class="badge bg-dark">Super Admin</span>
                                @elseif ($row['through'] === 'grant')
                                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle">Given individually</span>
                                @else
                                    <span class="badge bg-light text-body border">Role: {{ \App\Models\User::roleLabel($person->role) }}</span>
                                @endif
                            </td>
                            <td class="pe-3 text-end">
                                @if ($row['blocked'])
                                    <form method="POST" action="{{ route('admin.ai-assistant.access.store') }}" class="d-inline">
                                        @csrf
                                        <input type="hidden" name="feature" value="{{ $feature['key'] }}">
                                        <input type="hidden" name="user" value="{{ $person->id }}">
                                        <button class="btn btn-sm btn-outline-primary">Give back</button>
                                    </form>
                                @elseif ($row['through'] !== 'super_admin')
                                    <form method="POST" action="{{ route('admin.ai-assistant.access.destroy', ['feature' => $feature['key'], 'user' => $person->id]) }}"
                                          class="d-inline" onsubmit="return confirm('Take away this person\'s access?')">
                                        @csrf
                                        @method('DELETE')
                                        <button class="btn btn-sm btn-outline-danger"><i class="bi bi-x-lg me-1"></i>Remove</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3" class="text-center text-muted py-4">Nobody has access yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endforeach

<datalist id="ai-access-users">
    @foreach ($userOptions as $option)
        <option value="{{ $option->id }} · {{ $option->name }} · {{ $option->email }}"></option>
    @endforeach
</datalist>
@endsection
