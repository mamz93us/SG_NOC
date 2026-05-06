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

    // ── Onboarding progress checklist (create_user only) ────────────
    // Each entry: ['label' => ..., 'icon' => ..., 'state' => 'done|current|pending|failed']
    $onboardingSteps = [];
    if ($isCreateUser) {
        $status         = $workflow->status;
        $approvalsDone  = !in_array($status, ['draft', 'pending']);
        $managerDone    = $managerToken && $managerToken->hasResponse();
        $managerSkipped = empty($payload['manager_email']);
        $isExecuting    = $status === 'executing';
        $isFailed       = in_array($status, ['failed', 'rejected', 'cancelled']);

        // 1. Submitted — always done once workflow exists
        $onboardingSteps[] = [
            'label' => 'Submitted',
            'icon'  => 'bi-send-fill',
            'state' => 'done',
        ];

        // 2. Approvals
        $onboardingSteps[] = [
            'label' => 'Approvals',
            'icon'  => 'bi-check2-circle',
            'state' => $status === 'rejected' ? 'failed'
                     : ($approvalsDone ? 'done' : ($status === 'pending' ? 'current' : 'pending')),
        ];

        // 3. Manager Form (skipped marker when no manager email)
        if (!$managerSkipped) {
            $onboardingSteps[] = [
                'label' => 'Manager Form',
                'icon'  => 'bi-clipboard-check',
                'state' => $managerDone ? 'done'
                         : ($status === 'awaiting_manager_form' ? 'current'
                             : ($approvalsDone ? 'current' : 'pending')),
            ];
        }

        // 4. Provisioning
        $onboardingSteps[] = [
            'label' => 'Provisioning',
            'icon'  => 'bi-gear-fill',
            'state' => $status === 'completed' ? 'done'
                     : ($isFailed && $status === 'failed' ? 'failed'
                         : ($isExecuting ? 'current' : 'pending')),
        ];

        // 5. Completed
        $onboardingSteps[] = [
            'label' => 'Completed',
            'icon'  => 'bi-flag-fill',
            'state' => $status === 'completed' ? 'done'
                     : ($isFailed ? 'failed' : 'pending'),
        ];
    }

    // ── Provisioning sub-steps (create_user only — vertical detailed list) ─
    // State derived from payload evidence: presence of azure_id, assigned_licenses,
    // group ids, extension, employee_id, ticketing tickets, etc.
    $provisioningSteps = [];
    if ($isCreateUser) {
        $st = $workflow->status;
        $reachedProvisioning = in_array($st, ['executing', 'completed', 'failed']);
        $isComplete = $st === 'completed';
        $isExecutingNow = $st === 'executing';
        $isProvFailed = $st === 'failed';

        $hasAzure       = !empty($payload['azure_id']) && !empty($payload['upn']);
        $hasLicenses    = !empty($payload['assigned_licenses']);
        $hasGroups      = !empty($payload['auto_assigned_groups'])
                          || !empty($payload['manager_groups'])
                          || !empty($payload['internet_access_group_id']);
        $hasExtension   = !empty($payload['extension']);
        $needsExtension = $payload['needs_extension'] ?? null; // null = manager form not filled yet
        $hasEmployee    = !empty($payload['employee_id']);
        $hasTickets     = !empty($payload['ticketing']['laptop_ticket_id'])
                          || !empty($payload['ticketing']['phone_ticket_id']);

        // Helper: when provisioning has run (or is running) but no evidence is present,
        // we mark as 'skipped' if completed (e.g. nothing to assign), 'current' if executing,
        // else 'pending' (waiting for provisioning to start).
        $stateFor = function ($evidence, $isOptional = false) use ($isComplete, $isExecutingNow, $isProvFailed, $reachedProvisioning) {
            if ($evidence) return 'done';
            if ($isProvFailed && $reachedProvisioning) return 'failed';
            if ($isComplete) return $isOptional ? 'skipped' : 'failed';
            if ($isExecutingNow) return 'current';
            return 'pending';
        };

        // 1. Azure account (UPN + user creation)
        $provisioningSteps[] = [
            'label'  => 'Azure Account / UPN',
            'detail' => $hasAzure ? ($payload['upn'] ?? '') : 'Create Azure AD user with UPN',
            'icon'   => 'bi-microsoft',
            'state'  => $stateFor($hasAzure, false),
        ];

        // 2. Licenses
        $licenseDetail = $hasLicenses
            ? collect($payload['assigned_licenses'])->pluck('name')->filter()->implode(', ')
            : 'Assign Microsoft 365 license(s)';
        $provisioningSteps[] = [
            'label'  => 'License Assignment',
            'detail' => $licenseDetail ?: 'Assign Microsoft 365 license(s)',
            'icon'   => 'bi-key-fill',
            'state'  => $stateFor($hasLicenses, true),
        ];

        // 3. Groups
        $groupCount = collect([
                $payload['auto_assigned_groups'] ?? [],
                $payload['manager_groups']        ?? [],
            ])->map(fn($g) => is_array($g) ? count($g) : 0)->sum()
            + (!empty($payload['internet_access_group_id']) ? 1 : 0);
        $provisioningSteps[] = [
            'label'  => 'Group Membership',
            'detail' => $hasGroups ? "{$groupCount} group(s) assigned" : 'Assign Azure groups (branch + manager + internet)',
            'icon'   => 'bi-people-fill',
            'state'  => $stateFor($hasGroups, true),
        ];

        // 4. Extension (UCM)
        if ($needsExtension === false) {
            $provisioningSteps[] = [
                'label'  => 'IP Phone Extension',
                'detail' => 'Skipped — manager said no extension needed',
                'icon'   => 'bi-telephone-x',
                'state'  => 'skipped',
            ];
        } else {
            $provisioningSteps[] = [
                'label'  => 'IP Phone Extension',
                'detail' => $hasExtension ? "Extension {$payload['extension']} on UCM" : 'Provision UCM extension',
                'icon'   => 'bi-telephone-fill',
                'state'  => $stateFor($hasExtension, true),
            ];
        }

        // 5. Employee record
        $provisioningSteps[] = [
            'label'  => 'Employee Record',
            'detail' => $hasEmployee ? "Created (ID #{$payload['employee_id']})" : 'Create internal employee record',
            'icon'   => 'bi-person-badge-fill',
            'state'  => $stateFor($hasEmployee, false),
        ];

        // 6. External tickets (laptop / phone)
        $ticketDetail = $hasTickets
            ? trim(
                (!empty($payload['ticketing']['laptop_ticket_id']) ? 'Laptop #' . $payload['ticketing']['laptop_ticket_id'] : '')
                . (!empty($payload['ticketing']['phone_ticket_id']) ? '  ·  Phone #' . $payload['ticketing']['phone_ticket_id'] : '')
            )
            : 'Create laptop & phone tickets in helpdesk';
        $provisioningSteps[] = [
            'label'  => 'External Tickets',
            'detail' => $ticketDetail,
            'icon'   => 'bi-ticket-detailed-fill',
            'state'  => $stateFor($hasTickets, true),
        ];

        // 7. Notification emails (welcome + IT summary) — assumed sent on completion
        $provisioningSteps[] = [
            'label'  => 'Notification Emails',
            'detail' => $isComplete ? 'Welcome email + IT summary sent' : 'Send welcome + IT onboarding summary',
            'icon'   => 'bi-envelope-paper-fill',
            'state'  => $stateFor($isComplete, true),
        ];
    }
@endphp

@if($isCreateUser && !empty($onboardingSteps))
<style>
    .onb-stepper {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 0;
        padding: 8px 4px 0;
    }
    .onb-step {
        flex: 1 1 0;
        text-align: center;
        position: relative;
        min-width: 0;
    }
    .onb-step + .onb-step::before {
        content: '';
        position: absolute;
        top: 22px;
        left: -50%;
        right: 50%;
        height: 3px;
        background: #e9ecef;
        z-index: 0;
    }
    .onb-step.done + .onb-step::before { background: #198754; }
    .onb-step.current::before { background: linear-gradient(90deg, #198754 0%, #e9ecef 100%); }
    .onb-step + .onb-step.failed::before { background: #dc3545; }
    .onb-circle {
        position: relative;
        z-index: 1;
        width: 46px; height: 46px;
        border-radius: 50%;
        background: #fff;
        border: 3px solid #e9ecef;
        color: #adb5bd;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 20px;
        margin: 0 auto 6px;
        transition: all .2s;
    }
    .onb-step.done .onb-circle {
        background: #198754; border-color: #198754; color: #fff;
        box-shadow: 0 2px 6px rgba(25,135,84,.3);
    }
    .onb-step.current .onb-circle {
        background: #fff; border-color: #0d6efd; color: #0d6efd;
        box-shadow: 0 0 0 4px rgba(13,110,253,.15);
        animation: onb-pulse 1.6s ease-in-out infinite;
    }
    .onb-step.failed .onb-circle {
        background: #dc3545; border-color: #dc3545; color: #fff;
    }
    @keyframes onb-pulse {
        0%, 100% { box-shadow: 0 0 0 4px rgba(13,110,253,.15); }
        50%      { box-shadow: 0 0 0 8px rgba(13,110,253,.05); }
    }
    .onb-label {
        font-size: .8rem; font-weight: 600; color: #6c757d;
        line-height: 1.2;
    }
    .onb-step.done .onb-label    { color: #198754; }
    .onb-step.current .onb-label { color: #0d6efd; }
    .onb-step.failed .onb-label  { color: #dc3545; }
    .onb-sub {
        font-size: .68rem; color: #adb5bd; margin-top: 2px; display: block;
    }

    /* ── Vertical provisioning checklist ─────────────────────── */
    .prov-list { position: relative; padding-left: 8px; }
    .prov-item {
        position: relative;
        padding: 10px 0 10px 38px;
        min-height: 44px;
    }
    .prov-item:not(:last-child)::after {
        content: '';
        position: absolute;
        left: 14px;
        top: 36px;
        bottom: -4px;
        width: 2px;
        background: #e9ecef;
    }
    .prov-item.done:not(:last-child)::after { background: #198754; }
    .prov-item.failed:not(:last-child)::after { background: #dc3545; }
    .prov-dot {
        position: absolute;
        left: 0; top: 10px;
        width: 28px; height: 28px;
        border-radius: 50%;
        background: #fff;
        border: 2px solid #e9ecef;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        color: #adb5bd;
        font-size: 13px;
        z-index: 1;
    }
    .prov-item.done .prov-dot {
        background: #198754; border-color: #198754; color: #fff;
    }
    .prov-item.current .prov-dot {
        background: #fff; border-color: #0d6efd; color: #0d6efd;
        box-shadow: 0 0 0 3px rgba(13,110,253,.15);
        animation: onb-pulse 1.6s ease-in-out infinite;
    }
    .prov-item.failed .prov-dot {
        background: #dc3545; border-color: #dc3545; color: #fff;
    }
    .prov-item.skipped .prov-dot {
        background: #f8f9fa; border-color: #ced4da; color: #adb5bd;
        border-style: dashed;
    }
    .prov-title {
        font-size: .82rem; font-weight: 600; color: #212529;
        line-height: 1.2;
    }
    .prov-item.skipped .prov-title { color: #6c757d; }
    .prov-detail {
        font-size: .72rem; color: #6c757d; margin-top: 2px;
        word-break: break-word;
    }
    .prov-status-pill {
        font-size: .62rem; font-weight: 700;
        text-transform: uppercase; letter-spacing: .04em;
        padding: 2px 7px; border-radius: 10px;
        margin-left: 6px; vertical-align: middle;
    }
    .prov-item.done .prov-status-pill    { background: #d1e7dd; color: #0a6e3a; }
    .prov-item.current .prov-status-pill { background: #cfe2ff; color: #0a4ea8; }
    .prov-item.failed .prov-status-pill  { background: #f8d7da; color: #842029; }
    .prov-item.skipped .prov-status-pill { background: #e9ecef; color: #6c757d; }
    .prov-item.pending .prov-status-pill { background: #fff3cd; color: #664d03; }
</style>
@endif

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

        {{-- Cancel: any non-terminal workflow (approvers + owner-on-draft) --}}
        @if(! in_array($workflow->status, ['completed', 'rejected', 'failed', 'cancelled']))
        @can('manage-workflows')
        <button type="button" class="btn btn-outline-warning btn-sm"
                data-bs-toggle="modal" data-bs-target="#cancelWorkflowModal">
            <i class="bi bi-x-circle me-1"></i>Cancel
        </button>
        @endcan
        @endif

        {{-- Delete: terminal states only, approver permission --}}
        @if(in_array($workflow->status, ['completed', 'rejected', 'failed', 'cancelled']))
        @can('approve-workflows')
        <button type="button" class="btn btn-outline-danger btn-sm"
                data-bs-toggle="modal" data-bs-target="#deleteWorkflowModal">
            <i class="bi bi-trash me-1"></i>Delete
        </button>
        @endcan
        @endif

        <a href="{{ route('admin.workflows.index') }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Back
        </a>
    </div>
</div>


{{-- Awaiting-manager-form banner (create_user only) --}}
@if($isCreateUser && $workflow->status === 'awaiting_manager_form' && $managerToken)
<div class="alert alert-warning border-warning shadow-sm mb-4 d-flex align-items-start gap-3">
    <i class="bi bi-hourglass-split fs-4 text-warning"></i>
    <div class="flex-grow-1">
        <strong class="d-block mb-1">Waiting for Manager to Fill Setup Form</strong>
        <div class="small text-muted">
            IT approval is complete. Provisioning will start automatically once
            <strong>{{ $managerToken->manager_email }}</strong> submits the form.
            @if($managerToken->reminded_at)
                Last reminder sent {{ $managerToken->reminded_at->diffForHumans() }}
                ({{ $managerToken->reminder_count }} total).
            @else
                Daily reminders will be sent until the form is filled.
            @endif
        </div>
    </div>
    @if($managerToken->isValid())
    @can('approve-workflows')
    <a href="{{ route('onboarding.form', $managerToken->token) }}" target="_blank"
       class="btn btn-warning btn-sm">
        <i class="bi bi-clipboard-check me-1"></i>Fill on Behalf
    </a>
    @endcan
    @endif
</div>
@endif

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

{{-- ── Onboarding progress checklist (create_user only) ── --}}
@if($isCreateUser && !empty($onboardingSteps))
<div class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-transparent d-flex align-items-center gap-2">
        <i class="bi bi-list-check text-primary"></i>
        <strong>Onboarding Progress</strong>
        @php
            $doneCount  = collect($onboardingSteps)->where('state', 'done')->count();
            $totalCount = count($onboardingSteps);
        @endphp
        <span class="badge bg-light text-dark border ms-auto">{{ $doneCount }}/{{ $totalCount }} steps</span>
    </div>
    <div class="card-body pb-3">
        <div class="onb-stepper">
            @foreach($onboardingSteps as $step)
            <div class="onb-step {{ $step['state'] }}">
                <div class="onb-circle">
                    @if($step['state'] === 'done')
                        <i class="bi bi-check-lg"></i>
                    @elseif($step['state'] === 'failed')
                        <i class="bi bi-x-lg"></i>
                    @else
                        <i class="bi {{ $step['icon'] }}"></i>
                    @endif
                </div>
                <div class="onb-label">{{ $step['label'] }}</div>
                @if($step['state'] === 'current')
                    <span class="onb-sub">In progress…</span>
                @elseif($step['state'] === 'failed')
                    <span class="onb-sub text-danger">{{ ucfirst($workflow->status) }}</span>
                @elseif($step['state'] === 'done')
                    <span class="onb-sub text-success">Done</span>
                @else
                    <span class="onb-sub">Pending</span>
                @endif
            </div>
            @endforeach
        </div>
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
                    <dd class="col-7"><span class="badge {{ $workflow->statusBadgeClass() }}">{{ ucwords(str_replace('_', ' ', $workflow->status)) }}</span></dd>
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

        {{-- ── Vertical provisioning sub-step checklist (create_user only) ── --}}
        @if($isCreateUser && !empty($provisioningSteps))
        @php
            $provDone    = collect($provisioningSteps)->where('state', 'done')->count();
            $provSkipped = collect($provisioningSteps)->where('state', 'skipped')->count();
            $provTotal   = count($provisioningSteps);
        @endphp
        <div class="card shadow-sm border-0 mb-3">
            <div class="card-header bg-transparent d-flex align-items-center gap-2">
                <i class="bi bi-diagram-3 text-primary"></i>
                <strong>Provisioning Steps</strong>
                <span class="badge bg-light text-dark border ms-auto"
                      title="{{ $provDone }} done, {{ $provSkipped }} skipped, {{ $provTotal }} total">
                    {{ $provDone }}/{{ $provTotal }}
                </span>
            </div>
            <div class="card-body py-2">
                <div class="prov-list">
                    @foreach($provisioningSteps as $ps)
                    <div class="prov-item {{ $ps['state'] }}">
                        <span class="prov-dot">
                            @if($ps['state'] === 'done')
                                <i class="bi bi-check-lg"></i>
                            @elseif($ps['state'] === 'failed')
                                <i class="bi bi-x-lg"></i>
                            @elseif($ps['state'] === 'skipped')
                                <i class="bi bi-dash-lg"></i>
                            @else
                                <i class="bi {{ $ps['icon'] }}"></i>
                            @endif
                        </span>
                        <div class="prov-title">
                            {{ $ps['label'] }}
                            <span class="prov-status-pill">
                                @switch($ps['state'])
                                    @case('done')    Done    @break
                                    @case('current') Running @break
                                    @case('failed')  Failed  @break
                                    @case('skipped') Skipped @break
                                    @default        Pending
                                @endswitch
                            </span>
                        </div>
                        <div class="prov-detail">{{ $ps['detail'] }}</div>
                    </div>
                    @endforeach
                </div>
            </div>
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

{{-- ── Device / Asset Assignment (create_user only, post-provisioning) ── --}}
@if($isCreateUser && $employee)
<div class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-transparent d-flex align-items-center gap-2">
        <i class="bi bi-laptop text-primary"></i>
        <strong>Assigned Devices</strong>
        <span class="text-muted small ms-2">— for {{ $employee->name }}</span>
        <span class="badge bg-light text-dark ms-auto">{{ $currentAssignments->count() }} active</span>
    </div>
    <div class="card-body small">

        {{-- Current assignments --}}
        @if($currentAssignments->isNotEmpty())
        <div class="table-responsive mb-3">
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Device</th>
                        <th>Type</th>
                        <th>Asset Code</th>
                        <th>Serial</th>
                        <th>Assigned</th>
                        <th>Condition</th>
                        <th class="text-end">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($currentAssignments as $a)
                    <tr>
                        <td class="fw-semibold">{{ $a->device?->name ?? '—' }}</td>
                        <td><span class="badge bg-secondary">{{ $a->device?->type ?? '—' }}</span></td>
                        <td><code>{{ $a->device?->asset_code ?? '—' }}</code></td>
                        <td class="text-muted">{{ $a->device?->serial_number ?? '—' }}</td>
                        <td>{{ $a->assigned_date?->format('d M Y') ?? '—' }}</td>
                        <td><span class="badge {{ $a->conditionBadgeClass() }}">{{ ucfirst($a->condition ?? '—') }}</span></td>
                        <td class="text-end">
                            @can('approve-workflows')
                            <form method="POST" action="{{ route('admin.workflows.return-device', ['workflow' => $workflow->id, 'assignment' => $a->id]) }}"
                                  class="d-inline"
                                  onsubmit="return confirm('Return this device? It will be marked as available.');">
                                @csrf
                                <button type="submit" class="btn btn-sm btn-outline-secondary">
                                    <i class="bi bi-arrow-return-left me-1"></i>Return
                                </button>
                            </form>
                            @endcan
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @else
        <p class="text-muted mb-3"><i class="bi bi-info-circle me-1"></i>No devices are currently assigned to this employee.</p>
        @endif

        {{-- Assign a new device form --}}
        @can('approve-workflows')
        @if($availableDevices->isNotEmpty())
        <form method="POST" action="{{ route('admin.workflows.assign-device', $workflow->id) }}" class="row g-2 align-items-end">
            @csrf
            <div class="col-md-5">
                <label class="form-label small fw-semibold mb-1">Select Device</label>
                <select name="asset_id" class="form-select form-select-sm" required>
                    <option value="">— Choose a device —</option>
                    @foreach($availableDevices as $dev)
                    <option value="{{ $dev->id }}">
                        {{ $dev->type ? strtoupper($dev->type) . ' · ' : '' }}{{ $dev->name }}
                        @if($dev->asset_code) ({{ $dev->asset_code }}) @endif
                        @if($dev->serial_number) — SN: {{ $dev->serial_number }}@endif
                        @if($workflow->branch_id === null && $dev->branch)
                            — {{ $dev->branch->name }}
                        @endif
                    </option>
                    @endforeach
                </select>
                @if($workflow->branch_id)
                <div class="form-text">Showing available devices in this workflow's branch.</div>
                @else
                <div class="form-text">No branch on workflow — showing all available devices.</div>
                @endif
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold mb-1">Condition</label>
                <select name="condition" class="form-select form-select-sm" required>
                    <option value="good" selected>Good</option>
                    <option value="fair">Fair</option>
                    <option value="poor">Poor</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small fw-semibold mb-1">Notes <span class="text-muted">(optional)</span></label>
                <input type="text" name="notes" class="form-control form-control-sm" maxlength="500" placeholder="e.g. handed at desk">
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary btn-sm w-100">
                    <i class="bi bi-plus-circle me-1"></i>Assign
                </button>
            </div>
        </form>
        @else
        <div class="alert alert-light border small mb-0">
            <i class="bi bi-info-circle me-1"></i>
            No available (unassigned) devices found{{ $workflow->branch_id ? ' in this branch' : '' }}.
            <a href="{{ route('admin.devices.index') }}" class="ms-1">Manage devices →</a>
        </div>
        @endif
        @endcan

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

{{-- Cancel Workflow modal --}}
@if(! in_array($workflow->status, ['completed', 'rejected', 'failed', 'cancelled']))
<div class="modal fade" id="cancelWorkflowModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="{{ route('admin.workflows.cancel', $workflow->id) }}">
                @csrf
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title"><i class="bi bi-x-circle me-2"></i>Cancel Workflow</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="small text-muted mb-3">
                        This workflow will be marked as <strong>cancelled</strong>. Pending approval steps
                        will be skipped, and any unfilled manager setup tokens will be expired.
                        Provisioning will <strong>not</strong> run.
                    </p>
                    <label class="form-label small">Reason (optional)</label>
                    <textarea name="reason" class="form-control form-control-sm" rows="3"
                              placeholder="Why is this being cancelled? Visible in the workflow event log."></textarea>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Keep Workflow</button>
                    <button type="submit" class="btn btn-sm btn-warning"><i class="bi bi-x-lg me-1"></i>Cancel Workflow</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endif

{{-- Delete Workflow modal --}}
@if(in_array($workflow->status, ['completed', 'rejected', 'failed', 'cancelled']))
<div class="modal fade" id="deleteWorkflowModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="{{ route('admin.workflows.destroy', $workflow->id) }}">
                @csrf
                @method('DELETE')
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="bi bi-trash me-2"></i>Delete Workflow</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-2">
                        Permanently delete <strong>{{ $workflow->title }}</strong>?
                    </p>
                    <p class="small text-muted mb-0">
                        This removes the workflow and all related steps, event logs, tasks, and manager
                        setup tokens. <strong>This action cannot be undone.</strong>
                    </p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Keep</button>
                    <button type="submit" class="btn btn-sm btn-danger"><i class="bi bi-trash me-1"></i>Delete Permanently</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endif
@endsection
