@extends('layouts.admin')

@section('content')
<div class="container-fluid py-4">
    <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
        <div>
            <h4 class="mb-0 fw-bold"><i class="bi bi-bell-fill me-2 text-warning"></i>Printer Alert Settings</h4>
            <small class="text-muted">Per-branch recipient list and manager CC for low-toner / waste-full alerts</small>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('admin.printers.dashboard') }}" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-speedometer2 me-1"></i>Printer Dashboard
            </a>
            <a href="{{ route('admin.printers.unified.index') }}" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-collection me-1"></i>Unified Printers
            </a>
        </div>
    </div>

    @if (session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif
    @if (session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Branch</th>
                        <th>Alerts</th>
                        <th>Manager Email</th>
                        <th>Active Recipients</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                @foreach ($branches as $branch)
                    @php
                        $setting = $branch->printerSetting;
                        $rcount  = $branch->printerAlertRecipients->count();
                    @endphp
                    <tr>
                        <td class="fw-semibold">{{ $branch->name }}</td>
                        <td>
                            @if ($setting && $setting->alerts_enabled)
                                <span class="badge bg-success">Enabled</span>
                            @elseif ($setting)
                                <span class="badge bg-secondary">Disabled</span>
                            @else
                                <span class="badge bg-light text-muted">Not configured</span>
                            @endif
                        </td>
                        <td>
                            @if ($setting?->manager_email)
                                {{ $setting->manager_email }}
                                @if ($setting->manager_name)<small class="text-muted d-block">{{ $setting->manager_name }}</small>@endif
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td>
                            <span class="badge {{ $rcount > 0 ? 'bg-info' : 'bg-warning text-dark' }}">{{ $rcount }}</span>
                        </td>
                        <td class="text-end">
                            <a href="{{ route('admin.printers.branch.edit', $branch) }}" class="btn btn-sm btn-primary">
                                <i class="bi bi-pencil me-1"></i>Edit
                            </a>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
