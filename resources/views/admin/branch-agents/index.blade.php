@extends('layouts.admin')

@section('title', 'Branch Agents')

@php
    $statusBadge = function ($status) {
        return match ($status) {
            'healthy' => '<span class="badge bg-success">healthy</span>',
            'stale'   => '<span class="badge bg-warning text-dark">stale</span>',
            'down'    => '<span class="badge bg-danger">down</span>',
            default   => '<span class="badge bg-secondary">pending</span>',
        };
    };
@endphp

@section('content')
<div class="container-fluid py-3">

    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h4 class="mb-0">Branch Agents</h4>
            <small class="text-muted">
                One <code>sg-branch-agent</code> per branch VM — log collection, device
                monitoring and DDNS. Status reflects the last heartbeat.
            </small>
        </div>
        <a href="{{ route('admin.branch-agents.create') }}" class="btn btn-sm btn-primary">
            <i class="bi bi-plus-lg me-1"></i>Add branch agent
        </a>
    </div>

    @if(session('success'))
        <div class="alert alert-success alert-dismissible fade show py-2">
            {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive">
            <table class="table table-sm table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th style="width:60px;">Code</th>
                        <th>Name</th>
                        <th style="width:150px;">FQDN / WAN IP</th>
                        <th style="width:90px;">Version</th>
                        <th style="width:90px;">Status</th>
                        <th style="width:140px;">Last heartbeat</th>
                        <th style="width:160px;">Enrollment</th>
                        <th style="width:120px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                @forelse($agents as $a)
                    <tr>
                        <td><code>{{ $a->code }}</code></td>
                        <td>
                            <a href="{{ route('admin.branch-agents.show', $a) }}">{{ $a->name }}</a>
                            @unless($a->enabled)<span class="badge bg-secondary ms-1">off</span>@endunless
                        </td>
                        <td class="small">
                            <div class="font-monospace">{{ $a->fqdn() ?? '—' }}</div>
                            <div class="text-muted font-monospace">{{ $a->wan_ip ?? '—' }}</div>
                        </td>
                        <td class="small font-monospace">{{ $a->agent_version ?? '—' }}</td>
                        <td>{!! $statusBadge($a->computeStatus()) !!}</td>
                        <td class="small text-muted">{{ $a->last_heartbeat_at?->diffForHumans() ?? 'never' }}</td>
                        <td class="small">
                            @if($a->enrollmentPending())
                                <span class="badge bg-info text-dark font-monospace">{{ $a->enrollment_code }}</span>
                                <div class="text-muted">expires {{ $a->enrollment_expires_at->diffForHumans() }}</div>
                            @elseif($a->api_token)
                                <span class="text-success"><i class="bi bi-check-circle"></i> linked</span>
                            @else
                                <span class="text-muted">not linked</span>
                            @endif
                        </td>
                        <td>
                            <a class="btn btn-sm btn-outline-secondary" href="{{ route('admin.branch-agents.show', $a) }}">
                                <i class="bi bi-eye"></i>
                            </a>
                            <a class="btn btn-sm btn-outline-secondary" href="{{ route('admin.branch-agents.edit', $a) }}">
                                <i class="bi bi-pencil"></i>
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="text-center text-muted py-5">
                            No branch agents yet.
                            <a href="{{ route('admin.branch-agents.create') }}">Add the first one</a>.
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
            </div>
        </div>
    </div>
</div>
@endsection
