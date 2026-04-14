@extends('layouts.admin')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 mb-1">Print Manager <small class="text-muted fs-6">(CUPS IPP Proxy)</small></h1>
        @if($cupsRunning)
            <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>CUPS Running</span>
        @else
            <span class="badge bg-danger"><i class="bi bi-x-circle me-1"></i>CUPS Not Running</span>
        @endif
    </div>
    @can('manage-print-manager')
    <a href="{{ route('admin.print-manager.create') }}" class="btn btn-primary">
        <i class="bi bi-plus-circle me-1"></i>Add Printer
    </a>
    @endcan
</div>

{{-- Search --}}
<div class="card mb-4">
    <div class="card-body py-2">
        <form method="GET" action="{{ route('admin.print-manager.index') }}" class="row g-2 align-items-end">
            <div class="col-md-8">
                <input type="text" name="search" class="form-control form-control-sm"
                       placeholder="Search by name, queue name, or IP..." value="{{ request('search') }}">
            </div>
            <div class="col-md-4 d-flex gap-2">
                <button type="submit" class="btn btn-sm btn-outline-primary"><i class="bi bi-search me-1"></i>Search</button>
                @if(request('search'))
                    <a href="{{ route('admin.print-manager.index') }}" class="btn btn-sm btn-outline-secondary">Clear</a>
                @endif
            </div>
        </form>
    </div>
</div>

{{-- Printers Table --}}
<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Name</th>
                        <th>Queue</th>
                        <th>Target IP</th>
                        <th>Protocol</th>
                        <th>Branch</th>
                        <th>Status</th>
                        <th>Jobs</th>
                        <th>Last Checked</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($cupsPrinters as $cp)
                    <tr>
                        <td>
                            <a href="{{ route('admin.print-manager.show', $cp) }}" class="fw-semibold text-decoration-none">
                                {{ $cp->name }}
                            </a>
                            @unless($cp->is_active)
                                <span class="badge bg-warning text-dark ms-1">Disabled</span>
                            @endunless
                        </td>
                        <td><code>{{ $cp->queue_name }}</code></td>
                        <td>{{ $cp->ip_address }}:{{ $cp->port }}</td>
                        <td><span class="text-uppercase small">{{ $cp->protocol }}</span></td>
                        <td>{{ $cp->branch?->name ?? '—' }}</td>
                        <td><span class="badge {{ $cp->statusBadgeClass() }}">{{ ucfirst($cp->status) }}</span></td>
                        <td>{{ $cp->print_jobs_count }}</td>
                        <td>{{ $cp->last_checked_at?->diffForHumans() ?? '—' }}</td>
                        <td class="text-end">
                            <a href="{{ route('admin.print-manager.show', $cp) }}" class="btn btn-sm btn-outline-primary" title="View">
                                <i class="bi bi-eye"></i>
                            </a>
                            @can('manage-print-manager')
                            <a href="{{ route('admin.print-manager.edit', $cp) }}" class="btn btn-sm btn-outline-warning" title="Edit">
                                <i class="bi bi-pencil"></i>
                            </a>
                            <form action="{{ route('admin.print-manager.destroy', $cp) }}" method="POST" class="d-inline">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete"
                                        onclick="return confirm('Remove printer \'{{ $cp->name }}\' from CUPS and database?');">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>
                            @endcan
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="9" class="text-center text-muted py-4">
                            <i class="bi bi-printer fs-1 d-block mb-2"></i>
                            No CUPS printers configured yet.
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

{{ $cupsPrinters->links('pagination::bootstrap-5') }}
@endsection
