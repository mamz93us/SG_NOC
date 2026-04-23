@extends('layouts.admin')
@section('content')

@php
    $payload = $workflow->payload ?? [];
    $isCreateUser = $workflow->type === 'create_user';
    $isCompleted  = $workflow->status === 'completed';

    // Manager form token (onboarding)
    $managerToken = $isCreateUser
        ? \App\Models\OnboardingManagerToken::where('workflow_id', $workflow->id)->latest()->first()
        : null;

    // Workflow tasks
    $workflowTasks = \App\Models\WorkflowTask::where('workflow_id', $workflow->id)->orderBy('created_at')->get();
@endphp

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-diagram-2-fill me-2 text-primary"></i>{{ $workflow->title }}</h4>
        <small class="text-muted">Workflow #{{ $workflow->id }} &bull; {{ $workflow->typeLabel() }}</small>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        {{-- Manager form button (IT team can fill if manager unavailable) --}}
        @if($isCreateUser && $managerToken && $managerToken->isValid() && ! $managerToken->hasResponse())
        @can('approve-workflows')
        <a href="{{ route('onboarding.form', $managerToken->token) }}" target="_blank"
           class="btn btn-outline-info btn-sm">
            <i class="bi bi-clipboard-check me-1"></i>Fill Manager Form
        </a>
        @endcan
        @endif

        {{-- Send / resend manager form (always available for create_user with a manager_email) --}}
        @if($isCreateUser && !empty($payload['manager_email']))
        @can('approve-workflows')
        <form method="POST" action="{{ route('admin.workflows.resend-manager-form', $workflow->id) }}" class="d-inline">
            @csrf
            <button type="submit" class="btn btn-outline-secondary btn-sm"
                    title="(Re)send setup form link to {{ $payload['manager_email'] }}"
                    onclick="return confirm('Send the manager setup form to {{ addslashes($payload['manager_email']) }}?')">
                <i class="bi bi-envelope me-1"></i>{{ $managerToken ? 'Resend' : 'Send' }} Manager Form
            </button>
        </form>
        @endcan
        @endif

        @if(in_array($workflow->status, ['failed', 'completed']))
        @can('approve-workflows')
        <form method="POST" action="{{ route('admin.workflows.retry', $workflow->id) }}" class="d-inline">
            @csrf
            <button type="submit" class="btn btn-warning btn-sm"
                    onclick="return confirm('Retry execution of this workflow? The provisioning steps will run again.')">
                <i class="bi bi-arrow-clockwise me-1"></i>Retry Execution
            </button>
        </form>
        @endcan
        @endif
        <a href="{{ route('admin.workflows.index') }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Back
        </a>
    </div>
</div>


{{-- ✅ Provisioned Account card (create_user completed only) --}}
@if($isCreateUser && $isCompleted)
@php
    $ucmServerName = null;
    if (!empty($payload['ucm_server_id'])) {
        $ucmServerName = \App\Models\UcmServer::find($payload['ucm_server_id'])?->name;
    }
@endphp
<div class="alert alert-success border-success shadow-sm mb-4 p-0 overflow-hidden">
    <div class="d-flex align-items-center gap-2 px-4 py-3 bg-success bg-opacity-10 border-bottom border-success border-opacity-25">
        <i class="bi bi-check-circle-fill text-success fs-5"></i>
        <strong class="text-success fs-6">Account Successfully Provisioned</strong>
    </div>
    <div class="px-4 py-3">
        <div class="row g-3">
            <div class="col-12 col-md-6">
                <dl class="row mb-0 small">
                    <dt class="col-5 text-muted">Full Name</dt>
                    <dd class="col-7 fw-semibold">{{ $payload['display_name'] ?? ($payload['first_name'] ?? '') . ' ' . ($payload['last_name'] ?? '') }}</dd>

                    <dt class="col-5 text-muted">Email (UPN)</dt>
                    <dd class="col-7">
                        @if(!empty($payload['upn']))
                        <span class="fw-semibold text-primary">{{ $payload['upn'] }}</span>
                        @else <span class="text-muted">—</span> @endif
                    </dd>

                    <dt class="col-5 text-muted">Initial Password</dt>
                    <dd class="col-7">
                        @if(!empty($payload['initial_password']))
                        <code class="small">{{ $payload['initial_password'] }}</code>
                        <span class="text-muted small ms-1">(user must change)</span>
                        @else <span class="text-muted small">Auto-generated</span> @endif
                    </dd>

                    <dt class="col-5 text-muted">Branch</dt>
                    <dd class="col-7">{{ $workflow->branch?->name ?? '—' }}</dd>
                </dl>
            </div>
            <div class="col-12 col-md-6">
                <dl class="row mb-0 small">
                    <dt class="col-5 text-muted">Extension</dt>
                    <dd class="col-7">
                        @if(!empty($payload['extension']))
                        <span class="badge bg-primary fs-6 px-2">{{ $payload['extension'] }}</span>
                        @if($ucmServerName) <span class="text-muted ms-1 small">({{ $ucmServerName }})</span> @endif
                        @else <span class="text-muted">Not assigned</span> @endif
                    </dd>

                    <dt class="col-5 text-muted">Azure ID</dt>
                    <dd class="col-7">
                        @if(!empty($payload['azure_id']))
                        <code class="small" style="font-size:.7rem">{{ Str::limit($payload['azure_id'], 20) }}</code>
                        @else <span class="text-muted">—</span> @endif
                    </dd>

                    <dt class="col-5 text-muted">Job Title</dt>
                    <dd class="col-7">{{ $payload['job_title'] ?? '—' }}</dd>

                    <dt class="col-5 text-muted">Department</dt>
                    <dd class="col-7">{{ $payload['department'] ?? '—' }}</dd>

                    <dt class="col-5 text-muted">Licenses</dt>
                    <dd class="col-7">
                        @if(!empty($payload['assigned_licenses']))
                            <ul class="list-unstyled mb-0">
                            @foreach($payload['assigned_licenses'] as $lic)
                                <li class="fw-semibold">{{ $lic['name'] ?? $lic['sku'] ?? $lic }}</li>
                            @endforeach
                            </ul>
                        @elseif(!empty($payload['license_sku']))
                            <code class="small" style="font-size:.7rem">{{ $payload['license_sku'] }}</code>
                        @else
                            <span class="text-muted">None assigned</span>
                        @endif
                    </dd>
                </dl>
            </div>
        </div>

        @if(!empty($payload['employee_id']))
        <div class="mt-3 pt-3 border-top border-success border-opacity-25">
            <a href="{{ route('admin.employees.show', $payload['employee_id']) }}"
               class="btn btn-success btn-sm">
                <i class="bi bi-person-badge-fill me-1"></i>View Employee Profile
            </a>
        </div>
        @endif
    </div>
</div>
@endif

<div class="row g-4">
    {{-- Left: Metadata --}}
    <div class="col-12 col-lg-4">
        <div class="card shadow-sm border-0 mb-3">
            <div class="card-header bg-transparent">
                <strong><i class="bi bi-info-circle me-1"></i>Request Details</strong>
            </div>
            <div class="card-body small">
                <dl class="row mb-0">
                    <dt class="col-5 text-muted">Status</dt>
                    <dd class="col-7"><span class="badge {{ $workflow->statusBadgeClass() }}">{{ ucfirst($workflow->status) }}</span></dd>
                    <dt class="col-5 text-muted">Type</dt>
                    <dd class="col-7"><span class="badge {{ $workflow->typeBadgeClass() }}">{{ $workflow->typeLabel() }}</span></dd>
                    <dt class="col-5 text-muted">Requested by</dt>
                    <dd class="col-7 fw-semibold">{{ $workflow->requester?->name ?? '—' }}</dd>
                    <dt class="col-5 text-muted">Branch</dt>
                    <dd class="col-7">{{ $workflow->branch?->name ?? '—' }}</dd>
                    <dt class="col-5 text-muted">Created</dt>
                    <dd class="col-7">{{ $workflow->created_at->format('d M Y H:i') }}</dd>
                    <dt class="col-5 text-muted">Updated</dt>
                    <dd class="col-7">{{ $workflow->updated_at->format('d M Y H:i') }}</dd>
                </dl>
            </div>
        </div>

        @if($workflow->description)
        <div class="card shadow-sm border-0 mb-3">
            <div class="card-header bg-transparent"><strong><i class="bi bi-chat-text me-1"></i>Description</strong></div>
            <div class="card-body small">{{ $workflow->description }}</div>
        </div>
        @endif

        {{-- Show raw payload only for non-completed create_user, or for other types always --}}
        @if($workflow->payload && !($isCreateUser && $isCompleted))
        <div class="card shadow-sm border-0">
            <div class="card-header bg-transparent"><strong><i class="bi bi-code me-1"></i>Request Data</strong></div>
            <div class="card-body p-0">
                <pre class="m-0 p-3 small" style="background:#f8f9fa;border-radius:0 0 .375rem .375rem;font-size:.75rem;overflow-x:auto">{{ json_encode($workflow->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
            </div>
        </div>
        @endif
    </div>

    {{-- Right: Timeline + Logs --}}
    <div class="col-12 col-lg-8">
        {{-- Timeline --}}
        <div class="card shadow-sm border-0 mb-3">
            <div class="card-header bg-transparent d-flex align-items-center justify-content-between">
                <strong><i class="bi bi-signpost-split me-1"></i>Approval Timeline</strong>
                <span class="badge bg-secondary">{{ $workflow->current_step }}/{{ $workflow->total_steps }} steps</span>
            </div>
            <div class="card-body">
                @foreach($workflow->steps as $step)
                <div class="d-flex gap-3 mb-3">
                    <div class="d-flex flex-column align-items-center">
                        <div class="rounded-circle d-flex align-items-center justify-content-center text-white fw-bold"
                             style="width:36px;height:36px;background:{{ $step->status === 'approved' ? '#198754' : ($step->status === 'rejected' ? '#dc3545' : ($step->status === 'pending' ? '#ffc107' : '#6c757d')) }}">
                            {{ $step->step_number }}
                        </div>
                        @if(!$loop->last)<div style="width:2px;height:32px;background:#dee2e6;margin:4px auto"></div>@endif
                    </div>
                    <div class="flex-grow-1 pb-1">
                        <div class="d-flex align-items-center gap-2 mb-1">
                            <strong class="small">{{ $step->approverRoleLabel() }}</strong>
                            <span class="badge {{ $step->statusBadgeClass() }} small">{{ ucfirst($step->status) }}</span>
                        </div>
                        @if($step->actor)
                        <div class="text-muted small">
                            By: {{ $step->actor->name }}
                            @if($step->acted_at) &bull; {{ $step->acted_at->format('d M Y H:i') }}@endif
                        </div>
                        @endif
                        @if($step->comments)
                        <div class="alert alert-light py-1 px-2 mt-1 small mb-0">
                            <i class="bi bi-chat-text me-1"></i>{{ $step->comments }}
                        </div>
                        @endif
                    </div>
                </div>
                @endforeach

                {{-- Approve/Reject buttons --}}
                @if($canApprove)
                <div class="border-top pt-3 mt-2 d-flex gap-2 flex-wrap">
                    <form method="POST" action="{{ route('admin.workflows.approve', $workflow->id) }}" class="d-flex gap-2 flex-grow-1">
                        @csrf
                        <input type="text" name="comments" class="form-control form-control-sm" placeholder="Optional comment...">
                        <button type="submit" class="btn btn-sm btn-success text-nowrap">
                            <i class="bi bi-check-lg me-1"></i>Approve
                        </button>
                    </form>
                    <button type="button" class="btn btn-sm btn-outline-danger text-nowrap" data-bs-toggle="modal" data-bs-target="#rejectModal">
                        <i class="bi bi-x-lg me-1"></i>Reject
                    </button>
                </div>
                @endif
            </div>
        </div>

        {{-- Execution Logs --}}
        @if($workflow->logs->isNotEmpty())
        <div class="card shadow-sm border-0">
            <div class="card-header bg-transparent">
                <strong><i class="bi bi-terminal me-1"></i>Execution Log</strong>
                <span class="badge bg-secondary ms-2">{{ $workflow->logs->count() }}</span>
            </div>
            <div class="card-body p-0" style="max-height:300px;overflow-y:auto">
                <table class="table table-sm mb-0 small">
                    <tbody>
                        @foreach($workflow->logs as $log)
                        <tr>
                            <td class="text-muted ps-3" style="width:140px;white-space:nowrap">{{ $log->created_at->format('H:i:s') }}</td>
                            <td style="width:90px"><span class="badge {{ $log->levelBadgeClass() }} small">{{ strtoupper($log->level) }}</span></td>
                            <td class="pe-3">{{ $log->message }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
        @endif
    </div>
</div>

{{-- ── Manager Form Status (create_user only) ── --}}
@if($isCreateUser)
@php $managerEmail = $payload['manager_email'] ?? null; @endphp
<div class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-transparent d-flex align-items-center gap-2">
        <i class="bi bi-clipboard-check text-info"></i>
        <strong>Manager Setup Form</strong>
        @if(! $managerToken)
            <span class="badge bg-secondary ms-auto">Not Sent Yet</span>
        @elseif($managerToken->hasResponse())
            <span class="badge bg-success ms-auto">Submitted</span>
        @elseif($managerToken->isValid())
            <span class="badge bg-warning text-dark ms-auto">Awaiting Manager</span>
        @else
            <span class="badge bg-secondary ms-auto">Expired</span>
        @endif
    </div>
    <div class="card-body small">
        @if($managerToken && $managerToken->hasResponse())
        <div class="row g-3">
            <div class="col-md-6">
                <dl class="row mb-0">
                    <dt class="col-6 text-muted">Laptop</dt>
                    <dd class="col-6">{{ ucfirst($managerToken->laptop_status ?? '—') }}</dd>
                    <dt class="col-6 text-muted">Needs Extension</dt>
                    <dd class="col-6">{{ $managerToken->needs_extension ? 'Yes' : 'No' }}</dd>
                    <dt class="col-6 text-muted">Internet Level</dt>
                    <dd class="col-6"><span class="badge bg-secondary">{{ strtoupper($managerToken->internet_level ?? '—') }}</span></dd>
                    <dt class="col-6 text-muted">Floor</dt>
                    <dd class="col-6">{{ $managerToken->floor?->name ?? '—' }}</dd>
                </dl>
            </div>
            <div class="col-md-6">
                <dl class="row mb-0">
                    <dt class="col-6 text-muted">Groups Selected</dt>
                    <dd class="col-6">{{ count($managerToken->selected_group_ids ?? []) }}</dd>
                    <dt class="col-6 text-muted">Responded At</dt>
                    <dd class="col-6">{{ $managerToken->responded_at?->format('d M Y H:i') ?? '—' }}</dd>
                    @if($managerToken->manager_comments)
                    <dt class="col-6 text-muted">Comments</dt>
                    <dd class="col-6">{{ $managerToken->manager_comments }}</dd>
                    @endif
                </dl>
            </div>
        </div>
        @elseif(! $managerToken)
        {{-- Token not created yet --}}
        <p class="mb-2 text-muted">
            The manager setup form has not been sent yet.
            @if($managerEmail) Manager email: <strong>{{ $managerEmail }}</strong>. @endif
        </p>
        @can('approve-workflows')
        @if($managerEmail)
        <form method="POST" action="{{ route('admin.workflows.resend-manager-form', $workflow->id) }}" class="d-inline">
            @csrf
            <button type="submit" class="btn btn-sm btn-primary">
                <i class="bi bi-send me-1"></i>Send Manager Form Now
            </button>
        </form>
        @else
        <span class="text-warning small"><i class="bi bi-exclamation-triangle me-1"></i>No manager email in payload — cannot send form.</span>
        @endif
        @endcan
        @else
        {{-- Token exists but not responded --}}
        <p class="mb-2 text-muted">
            The manager setup form was sent to <strong>{{ $managerToken->manager_email }}</strong>.
            @if($managerToken->isValid())
                Expires: <strong>{{ $managerToken->expires_at?->format('d M Y, H:i') }}</strong>.
            @else
                <span class="text-danger">This link has expired.</span>
            @endif
        </p>
        @can('approve-workflows')
        @if($managerToken->isValid())
        <div class="d-flex gap-2 flex-wrap">
            <a href="{{ route('onboarding.form', $managerToken->token) }}" target="_blank"
               class="btn btn-sm btn-info text-white">
                <i class="bi bi-clipboard-check me-1"></i>Open Form (fill on behalf of manager)
            </a>
        </div>
        @else
        <form method="POST" action="{{ route('admin.workflows.resend-manager-form', $workflow->id) }}" class="d-inline">
            @csrf
            <button type="submit" class="btn btn-sm btn-outline-warning">
                <i class="bi bi-arrow-repeat me-1"></i>Resend with New Link
            </button>
        </form>
        @endif
        @endcan
        @endif
    </div>
</div>
@endif

{{-- ── Workflow Tasks ── --}}
@if($workflowTasks->isNotEmpty())
<div class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-transparent d-flex align-items-center gap-2">
        <i class="bi bi-check2-square text-primary"></i>
        <strong>Setup Tasks</strong>
        <span class="badge bg-secondary ms-auto">{{ $workflowTasks->count() }}</span>
    </div>
    <div class="card-body p-0">
        <table class="table table-hover align-middle small mb-0">
            <thead class="table-light">
                <tr>
                    <th class="ps-3">Task</th>
                    <th>Type</th>
                    <th>Status</th>
                    <th>Details</th>
                    @can('approve-workflows')<th class="text-end pe-3">Action</th>@endcan
                </tr>
            </thead>
            <tbody>
                @foreach($workflowTasks as $task)
                <tr>
                    <td class="ps-3 fw-semibold">
                        <i class="bi {{ $task->typeIcon() }} me-1 text-primary"></i>
                        {{ $task->title }}
                    </td>
                    <td><span class="badge bg-light text-dark border">{{ $task->typeLabel() }}</span></td>
                    <td><span class="badge {{ $task->statusBadgeClass() }}">{{ ucfirst(str_replace('_', ' ', $task->status)) }}</span></td>
                    <td class="text-muted">
                        @if($task->type === 'ip_phone_assign' && ! empty($task->payload))
                            <span title="UCM: {{ $task->payload['ucm_ip'] ?? '—' }}  |  User: {{ $task->payload['ucm_username'] ?? '—' }}  |  Pass: {{ $task->payload['ucm_password'] ?? '—' }}">
                                Ext. <strong>{{ $task->payload['extension'] ?? '—' }}</strong>
                                &bull; UCM: <code>{{ $task->payload['ucm_ip'] ?? '—' }}</code>
                                &bull; Pass: <code>{{ $task->payload['ucm_password'] ?? '—' }}</code>
                            </span>
                        @elseif($task->type === 'laptop_assign' && ! empty($task->payload))
                            {{ ucfirst($task->payload['laptop_type'] ?? '') }} laptop
                        @else
                            {{ $task->description ? Str::limit($task->description, 60) : '—' }}
                        @endif
                    </td>
                    @can('approve-workflows')
                    <td class="text-end pe-3">
                        @if($task->status === 'pending')
                        <form method="POST" action="{{ route('admin.workflows.tasks.complete', $task->id) }}" class="d-inline">
                            @csrf
                            @method('PATCH')
                            <button type="submit" class="btn btn-sm btn-outline-success py-0 px-2" style="font-size:11px"
                                    onclick="return confirm('Mark this task as completed?')">
                                <i class="bi bi-check2 me-1"></i>Complete
                            </button>
                        </form>
                        @elseif($task->status === 'completed')
                        <span class="text-success small"><i class="bi bi-check-circle-fill me-1"></i>Done</span>
                        @endif
                    </td>
                    @endcan
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endif

{{-- ── External Tickets (from ticketing API) ── --}}
@if($isCreateUser && !empty($payload['ticketing']))
@php $tk = $payload['ticketing']; @endphp
<div class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-transparent d-flex align-items-center gap-2">
        <i class="bi bi-ticket-detailed-fill text-primary"></i>
        <strong>External Tickets</strong>
        <span class="badge bg-success ms-auto">Created</span>
    </div>
    <div class="card-body p-0">
        <table class="table table-sm align-middle small mb-0">
            <thead class="table-light">
                <tr>
                    <th class="ps-3">Ticket Type</th>
                    <th>Ticket #</th>
                    <th>Assigned Engineer</th>
                </tr>
            </thead>
            <tbody>
                @if(!empty($tk['laptop_ticket_id']))
                <tr>
                    <td class="ps-3 fw-semibold"><i class="bi bi-laptop me-1 text-primary"></i>Laptop</td>
                    <td><span class="badge bg-primary fs-6 px-2">#{{ $tk['laptop_ticket_id'] }}</span></td>
                    <td>
                        @if(!empty($tk['laptop_engineer_email']))
                            <a href="mailto:{{ $tk['laptop_engineer_email'] }}">{{ $tk['laptop_engineer_email'] }}</a>
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </td>
                </tr>
                @endif
                @if(!empty($tk['phone_ticket_id']))
                <tr>
                    <td class="ps-3 fw-semibold"><i class="bi bi-telephone-fill me-1 text-primary"></i>IP Phone</td>
                    <td><span class="badge bg-primary fs-6 px-2">#{{ $tk['phone_ticket_id'] }}</span></td>
                    <td>
                        @if(!empty($tk['phone_engineer_email']))
                            <a href="mailto:{{ $tk['phone_engineer_email'] }}">{{ $tk['phone_engineer_email'] }}</a>
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </td>
                </tr>
                @endif
            </tbody>
        </table>
    </div>
</div>
@endif

{{-- Reject modal --}}
<div class="modal fade" id="rejectModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <form method="POST" action="{{ route('admin.workflows.reject', $workflow->id) }}">
                @csrf
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="bi bi-x-circle me-2"></i>Reject Request</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <label class="form-label small">Reason for rejection</label>
                    <textarea name="comments" class="form-control form-control-sm" rows="3" placeholder="Required..."></textarea>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-sm btn-danger"><i class="bi bi-x-lg me-1"></i>Reject</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
