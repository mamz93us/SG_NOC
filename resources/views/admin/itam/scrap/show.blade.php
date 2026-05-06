@extends('layouts.admin')
@section('title', 'Scrap Request #' . $workflow->id)

@section('content')
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1"><i class="bi bi-trash3 me-2"></i>Scrap Request #{{ $workflow->id }}</h4>
            <span class="badge {{ $workflow->statusBadgeClass() }}">{{ ucfirst($workflow->status) }}</span>
            <span class="text-muted small ms-2">Step {{ $workflow->current_step }} of {{ $workflow->total_steps }}</span>
        </div>
        <div class="d-flex gap-2">
            @if($workflow->status === 'approved')
                <a href="{{ route('admin.itam.scrap.print', $workflow->id) }}" target="_blank" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-printer me-1"></i>Disposal Certificate
                </a>
            @endif
            <a href="{{ route('admin.itam.scrap.index') }}" class="btn btn-sm btn-outline-secondary">Back</a>
        </div>
    </div>

    @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
    @if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif

    <div class="row g-4">
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white"><strong>Request Details</strong></div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-sm-3 text-muted">Title</dt><dd class="col-sm-9">{{ $workflow->title }}</dd>
                        <dt class="col-sm-3 text-muted">Requested By</dt><dd class="col-sm-9">{{ $workflow->requester?->name ?? '—' }}</dd>
                        <dt class="col-sm-3 text-muted">Requested On</dt><dd class="col-sm-9">{{ $workflow->created_at->format('d M Y H:i') }}</dd>
                        <dt class="col-sm-3 text-muted">Branch</dt><dd class="col-sm-9">{{ $workflow->branch?->name ?? '—' }}</dd>
                        <dt class="col-sm-3 text-muted">Disposal Method</dt><dd class="col-sm-9"><span class="badge bg-info">{{ ucwords(str_replace('_',' ',$workflow->payload['disposal_method'] ?? '—')) }}</span></dd>
                        <dt class="col-sm-3 text-muted">Reason</dt><dd class="col-sm-9">{{ $workflow->payload['reason'] ?? $workflow->description }}</dd>
                    </dl>
                </div>
            </div>

            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white"><strong>Assets ({{ $devices->count() }})</strong></div>
                <div class="card-body p-0">
                    <table class="table table-sm mb-0">
                        <thead class="table-light">
                            <tr><th>Asset Code</th><th>Name</th><th>Type</th><th>Status</th><th>Serial</th></tr>
                        </thead>
                        <tbody>
                            @foreach($devices as $d)
                                <tr>
                                    <td><code>{{ $d->asset_code }}</code></td>
                                    <td>{{ $d->name }}</td>
                                    <td><span class="badge bg-secondary">{{ $d->type }}</span></td>
                                    <td><span class="badge {{ $d->statusBadgeClass() }}">{{ $d->status }}</span></td>
                                    <td>{{ $d->serial_number ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            @if(!empty($workflow->payload['photos']))
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header bg-white"><strong>Photos</strong></div>
                    <div class="card-body">
                        <div class="row g-2">
                            @foreach($workflow->payload['photos'] as $photo)
                                <div class="col-md-3">
                                    <a href="{{ asset('storage/' . $photo) }}" target="_blank">
                                        <img src="{{ asset('storage/' . $photo) }}" class="img-fluid rounded border" alt="">
                                    </a>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            @endif
        </div>

        <div class="col-lg-4">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white"><strong>Approval Steps</strong></div>
                <ul class="list-group list-group-flush">
                    @foreach($workflow->steps as $step)
                        <li class="list-group-item d-flex justify-content-between align-items-start">
                            <div>
                                <i class="bi {{ $step->statusIcon() }} me-2"></i>
                                <strong>Step {{ $step->step_number }}:</strong> {{ $step->approverRoleLabel() }}
                                @if($step->actor)<div class="small text-muted ms-4">By {{ $step->actor->name }} on {{ $step->acted_at?->format('d M Y H:i') }}</div>@endif
                                @if($step->comments)<div class="small fst-italic ms-4">"{{ $step->comments }}"</div>@endif
                            </div>
                            <span class="badge {{ $step->statusBadgeClass() }}">{{ ucfirst($step->status) }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>

            @if($canApprove)
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white"><strong>Action Required</strong></div>
                    <div class="card-body">
                        <form method="POST" action="{{ route('admin.itam.scrap.approve', $workflow->id) }}" class="mb-2">
                            @csrf
                            <textarea name="comments" class="form-control mb-2" rows="2" placeholder="Optional comments..."></textarea>
                            <button type="submit" class="btn btn-success w-100" onclick="return confirm('Approve this scrap request step?')">
                                <i class="bi bi-check-circle me-1"></i>Approve Step
                            </button>
                        </form>
                        <form method="POST" action="{{ route('admin.itam.scrap.reject', $workflow->id) }}">
                            @csrf
                            <textarea name="comments" class="form-control mb-2" rows="2" placeholder="Reason for rejection (required)..." required></textarea>
                            <button type="submit" class="btn btn-outline-danger w-100" onclick="return confirm('Reject this scrap request? This cannot be undone.')">
                                <i class="bi bi-x-circle me-1"></i>Reject Request
                            </button>
                        </form>
                    </div>
                </div>
            @endif
        </div>
    </div>
</div>
@endsection
