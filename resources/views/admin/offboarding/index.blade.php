@extends('layouts.admin')

@section('content')

<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0"><i class="bi bi-person-x-fill text-danger me-2"></i>Offboarding</h1>
    <div class="btn-group">
        <a href="{{ route('admin.offboarding.index') }}"
           class="btn btn-sm {{ ! $status ? 'btn-dark' : 'btn-outline-secondary' }}">All</a>
        @foreach(['manager_input_pending' => 'Awaiting Manager',
                  'processing'            => 'Processing',
                  'active'                => 'Active',
                  'escalated'             => 'Escalated',
                  'retention'             => 'Retention',
                  'completed'             => 'Completed',
                  'failed'                => 'Failed',
                  'cancelled'             => 'Cancelled'] as $key => $label)
            <a href="{{ route('admin.offboarding.index', ['status' => $key]) }}"
               class="btn btn-sm {{ $status === $key ? 'btn-dark' : 'btn-outline-secondary' }}">{{ $label }}</a>
        @endforeach
    </div>
</div>

@if(session('success'))<div class="alert alert-success py-2">{{ session('success') }}</div>@endif
@if(session('error'))<div class="alert alert-danger py-2">{{ session('error') }}</div>@endif

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead class="table-light">
                <tr>
                    <th>#</th>
                    <th>Employee</th>
                    <th>Last Day</th>
                    <th>Status</th>
                    <th>Manager Decisions</th>
                    <th>Backups</th>
                    <th class="text-end">Created</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($rows as $ow)
                <tr>
                    <td><code>#{{ $ow->id }}</code></td>
                    <td>
                        @if($ow->employee)
                            <strong>{{ $ow->employee->name }}</strong><br>
                            <small class="text-muted font-monospace">{{ $ow->employee->email }}</small>
                        @else
                            <span class="text-muted">(unknown employee)</span>
                        @endif
                    </td>
                    <td>
                        {{ $ow->expected_last_day?->format('Y-m-d') }}
                        @if($ow->azure_disabled_at)
                            <i class="bi bi-lock-fill text-danger ms-1" title="Disabled at {{ $ow->azure_disabled_at }}"></i>
                        @endif
                    </td>
                    <td><span class="badge {{ $ow->statusBadgeClass() }}">{{ str_replace('_', ' ', $ow->status) }}</span></td>
                    <td>
                        @if($ow->email_action)
                            <span class="badge bg-light text-dark border">
                                Mail: <strong>{{ $ow->email_action }}</strong>
                                @if($ow->email_action === 'forward' && $ow->forward_until)
                                    until {{ $ow->forward_until->format('M d') }}
                                @endif
                            </span>
                        @endif
                        @if($ow->laptop_action)
                            <span class="badge bg-light text-dark border">Laptop: <strong>{{ $ow->laptop_action }}</strong></span>
                        @endif
                        @if($ow->asset_action)
                            <span class="badge bg-light text-dark border">
                                Assets: <strong>{{ $ow->asset_action === 'transfer' ? 'transfer' : 'IT' }}</strong>
                            </span>
                        @endif
                        @if(! $ow->email_action && $ow->status === 'manager_input_pending')
                            <em class="text-muted small">Waiting for manager…</em>
                        @endif
                    </td>
                    <td>
                        @foreach($ow->backups as $b)
                            <span class="badge
                                @if($b->status === 'completed') bg-success
                                @elseif($b->status === 'failed') bg-danger
                                @elseif($b->status === 'manual_upload_required') bg-warning text-dark
                                @else bg-secondary
                                @endif">
                                {{ $b->type }}: {{ $b->status }}
                            </span>
                        @endforeach
                    </td>
                    <td class="text-end text-muted small">{{ $ow->created_at->diffForHumans() }}</td>
                    <td class="text-end">
                        <a href="{{ route('admin.offboarding.show', $ow) }}" class="btn btn-sm btn-outline-primary">
                            <i class="bi bi-eye"></i> View
                        </a>
                    </td>
                </tr>
                @empty
                <tr><td colspan="8" class="text-center text-muted py-4">No offboarding workflows yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="mt-3">{{ $rows->links() }}</div>

@endsection
