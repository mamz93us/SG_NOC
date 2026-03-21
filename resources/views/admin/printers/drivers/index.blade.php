@extends('layouts.admin')
@section('content')

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold">
            <i class="bi bi-hdd-fill me-2 text-primary"></i>Printer Drivers
        </h4>
        <small class="text-muted">Manage driver packages for Windows and macOS printer installation</small>
    </div>
    <a href="/admin/printers/drivers/create" class="btn btn-primary btn-sm">
        <i class="bi bi-plus-lg me-1"></i>Add Driver
    </a>
</div>

@if(session('success'))
<div class="alert alert-success alert-dismissible fade show py-2 mb-3">
    <i class="bi bi-check-circle me-1"></i>{{ session('success') }}
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
@endif

<div class="card shadow-sm border-0">
    <div class="card-body p-0">
        @if($drivers->isEmpty())
        <div class="text-center py-5 text-muted">
            <i class="bi bi-hdd display-4 d-block mb-3"></i>
            <p class="mb-0">No drivers added yet.</p>
            <a href="/admin/printers/drivers/create" class="btn btn-outline-primary btn-sm mt-3">
                <i class="bi bi-plus me-1"></i>Add First Driver
            </a>
        </div>
        @else
        <div class="table-responsive">
            <table class="table table-hover align-middle small mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">Printer / Scope</th>
                        <th>Driver Name</th>
                        <th>OS</th>
                        <th>Version</th>
                        <th>File</th>
                        <th>Active</th>
                        <th class="text-end pe-3">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($drivers as $driver)
                    <tr>
                        <td class="ps-3">
                            @if($driver->printer_id && $driver->printer)
                                <span class="fw-semibold">{{ $driver->printer->printer_name }}</span>
                                <br><small class="text-muted">{{ $driver->printer->branch?->name ?? '—' }}</small>
                            @else
                                <span class="text-muted">
                                    @if($driver->manufacturer)
                                        <strong>Mfg:</strong> {{ $driver->manufacturer }}
                                    @endif
                                    @if($driver->model_pattern)
                                        <br><strong>Pattern:</strong> <code>{{ $driver->model_pattern }}</code>
                                    @endif
                                    @if(!$driver->manufacturer && !$driver->model_pattern)
                                        <em>Any printer</em>
                                    @endif
                                </span>
                            @endif
                        </td>
                        <td>
                            <span class="fw-semibold">{{ $driver->driver_name }}</span>
                            @if($driver->inf_path)
                            <br><small class="text-muted font-monospace">{{ $driver->inf_path }}</small>
                            @endif
                        </td>
                        <td>
                            <span class="badge {{ $driver->osBadgeClass() }}">{{ $driver->osBadgeLabel() }}</span>
                        </td>
                        <td class="text-muted">{{ $driver->version ?: '—' }}</td>
                        <td>
                            @if($driver->driver_file_path)
                                <a href="/admin/printers/drivers/{{ $driver->id }}/download"
                                   class="btn btn-xs btn-outline-secondary py-0 px-2" style="font-size:.75rem"
                                   title="Download driver zip">
                                    <i class="bi bi-download me-1"></i>{{ $driver->original_filename ?? 'driver.zip' }}
                                </a>
                            @else
                                <span class="text-muted">No file</span>
                            @endif
                        </td>
                        <td>
                            @if($driver->is_active)
                                <span class="badge bg-success">Active</span>
                            @else
                                <span class="badge bg-danger">Inactive</span>
                            @endif
                        </td>
                        <td class="text-end pe-3">
                            <a href="/admin/printers/drivers/{{ $driver->id }}/edit"
                               class="btn btn-sm btn-outline-secondary">
                                <i class="bi bi-pencil"></i>
                            </a>
                            <form method="POST" action="/admin/printers/drivers/{{ $driver->id }}"
                                  class="d-inline"
                                  onsubmit="return confirm('Delete driver \'{{ addslashes($driver->driver_name) }}\'?')">
                                @csrf @method('DELETE')
                                <button class="btn btn-sm btn-outline-danger">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if($drivers->hasPages())
        <div class="p-3 border-top">
            {{ $drivers->links() }}
        </div>
        @endif
        @endif
    </div>
</div>

@endsection
