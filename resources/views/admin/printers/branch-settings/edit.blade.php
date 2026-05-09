@extends('layouts.admin')

@section('content')
<div class="container-fluid py-4">
    <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
        <div>
            <h4 class="mb-0 fw-bold"><i class="bi bi-bell-fill me-2 text-warning"></i>{{ $branch->name }} — Printer Alerts</h4>
            <small class="text-muted">Recipients, manager CC, and per-branch threshold overrides.</small>
        </div>
        <div>
            <a href="{{ route('admin.printers.branch.index') }}" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i>Back to all branches
            </a>
        </div>
    </div>

    @if (session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif
    @if (session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
        </div>
    @endif

    <div class="row g-3">
        {{-- ─── Branch settings ──────────────────────────────── --}}
        <div class="col-lg-5">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h5 class="card-title mb-3"><i class="bi bi-gear-fill me-2 text-primary"></i>Branch Settings</h5>
                    <form method="POST" action="{{ route('admin.printers.branch.update', $branch) }}">
                        @csrf
                        @method('PUT')

                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" role="switch" id="alerts_enabled" name="alerts_enabled" value="1"
                                {{ ($setting->alerts_enabled ?? true) ? 'checked' : '' }}>
                            <label class="form-check-label" for="alerts_enabled">Alerts enabled for this branch</label>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Manager Email <small class="text-muted">(CC'd on every alert)</small></label>
                            <input type="email" class="form-control" name="manager_email" value="{{ old('manager_email', $setting->manager_email) }}" placeholder="manager@example.com">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Manager Name <small class="text-muted">(optional)</small></label>
                            <input type="text" class="form-control" name="manager_name" value="{{ old('manager_name', $setting->manager_name) }}">
                        </div>

                        <hr class="my-3">
                        <h6 class="text-muted small fw-bold text-uppercase mb-2">Threshold Overrides</h6>
                        <p class="small text-muted">Leave blank to use the printer's own setting or the global default.</p>

                        <div class="mb-2">
                            <label class="form-label">Toner Warning %</label>
                            <input type="number" min="1" max="100" class="form-control" name="toner_warning_threshold" value="{{ old('toner_warning_threshold', $setting->toner_warning_threshold) }}">
                        </div>
                        <div class="mb-2">
                            <label class="form-label">Toner Critical %</label>
                            <input type="number" min="1" max="100" class="form-control" name="toner_critical_threshold" value="{{ old('toner_critical_threshold', $setting->toner_critical_threshold) }}">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Waste Container Critical %</label>
                            <input type="number" min="1" max="100" class="form-control" name="waste_critical_threshold" value="{{ old('waste_critical_threshold', $setting->waste_critical_threshold) }}">
                            <small class="text-muted">Alerts when remaining capacity is at or below this %.</small>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Notes</label>
                            <textarea name="notes" class="form-control" rows="2">{{ old('notes', $setting->notes) }}</textarea>
                        </div>

                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check2 me-1"></i>Save Settings
                        </button>
                    </form>

                    @if ($setting->exists)
                        <hr class="my-3">
                        <form method="POST" action="{{ route('admin.printers.branch.test', $branch) }}">
                            @csrf
                            <button type="submit" class="btn btn-outline-info btn-sm">
                                <i class="bi bi-envelope-check me-1"></i>Send Test Email
                            </button>
                        </form>
                    @endif
                </div>
            </div>
        </div>

        {{-- ─── Recipients ──────────────────────────────────── --}}
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h5 class="card-title mb-3"><i class="bi bi-people-fill me-2 text-success"></i>Recipients</h5>

                    <form method="POST" action="{{ route('admin.printers.branch.recipients.add', $branch) }}" class="row g-2 mb-3">
                        @csrf
                        <div class="col-md-5">
                            <label class="form-label small mb-1">System User <small class="text-muted">(optional)</small></label>
                            <select name="user_id" class="form-select form-select-sm">
                                <option value="">— Pick a user —</option>
                                @foreach ($users as $u)
                                    <option value="{{ $u->id }}">{{ $u->name }} ({{ $u->email }})</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small mb-1">…or Email</label>
                            <input type="email" name="email" class="form-control form-control-sm" placeholder="vendor@…">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small mb-1">Display Name</label>
                            <input type="text" name="name" class="form-control form-control-sm" placeholder="Optional">
                        </div>
                        <div class="col-md-1 d-flex align-items-end">
                            <button type="submit" class="btn btn-success btn-sm w-100">
                                <i class="bi bi-plus-lg"></i>
                            </button>
                        </div>
                    </form>

                    <div class="table-responsive">
                        <table class="table table-sm mb-0 align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Recipient</th>
                                    <th>Email</th>
                                    <th>Source</th>
                                    <th>Status</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                            @forelse ($recipients as $rec)
                                <tr>
                                    <td>{{ $rec->effectiveName() ?? '—' }}</td>
                                    <td><code class="small">{{ $rec->effectiveEmail() ?? '—' }}</code></td>
                                    <td>
                                        @if ($rec->user_id)
                                            <span class="badge bg-info">User</span>
                                        @else
                                            <span class="badge bg-secondary">Email</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if ($rec->is_active)
                                            <span class="badge bg-success">Active</span>
                                        @else
                                            <span class="badge bg-warning text-dark">Disabled</span>
                                        @endif
                                    </td>
                                    <td class="text-end">
                                        <form method="POST" action="{{ route('admin.printers.branch.recipients.toggle', $rec) }}" class="d-inline">
                                            @csrf
                                            <button type="submit" class="btn btn-outline-secondary btn-sm" title="Toggle active">
                                                <i class="bi bi-{{ $rec->is_active ? 'pause' : 'play' }}"></i>
                                            </button>
                                        </form>
                                        <form method="POST" action="{{ route('admin.printers.branch.recipients.delete', $rec) }}" class="d-inline" onsubmit="return confirm('Remove this recipient?');">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-outline-danger btn-sm">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="text-center text-muted py-4">No recipients yet — add one above.</td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
