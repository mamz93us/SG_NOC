@extends('layouts.hr')

@section('title', 'Request #'.$workflow->id)

@section('content')
@php
    // NOTE: never render $payload wholesale here — it carries initial_password
    // and ucm_extension_secret. Everything below reads named keys only.
    $isOnboarding = $workflow->type === 'create_user';
    $stuckDays = $managerForm['waiting_since']?->diffInDays(now());
@endphp

<div class="d-flex justify-content-between align-items-start mb-4">
    <div>
        <h4 class="mb-0 fw-bold">{{ $workflow->title }}</h4>
        <small class="text-muted">
            Request #{{ $workflow->id }} · {{ $workflow->typeLabel() }} ·
            raised {{ $workflow->created_at->diffForHumans() }}
        </small>
    </div>
    <div class="d-flex gap-2 align-items-center">
        <span class="badge {{ $workflow->statusBadgeClass() }}">
            {{ ucfirst(str_replace('_', ' ', $workflow->status)) }}
        </span>
        <a href="{{ route('portal.hr.requests') }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Back
        </a>
    </div>
</div>

{{-- Waiting-on-manager banner: the single thing HR most often chases --}}
@if($workflow->status === 'awaiting_manager_form')
    <div class="alert {{ $stuckDays >= 3 ? 'alert-warning' : 'alert-info' }} d-flex gap-2">
        <i class="bi bi-hourglass-split fs-5"></i>
        <div>
            <strong>Waiting on the manager.</strong>
            The setup form was sent to <strong>{{ $managerForm['sent_to'] }}</strong>
            @if($managerForm['sent_at'])
                {{ $managerForm['sent_at']->diffForHumans() }}
                @if($stuckDays >= 1)
                    — that is <strong>{{ $stuckDays }} {{ \Illuminate\Support\Str::plural('day', $stuckDays) }}</strong> with no reply.
                @endif
            @endif
            <div class="small mt-1">
                The account, licences and groups are already done. Only the phone extension
                and the manager’s own choices are outstanding.
                @if($managerForm['expires_at'])
                    Link expires {{ $managerForm['expires_at']->format('d M Y') }}.
                @endif
            </div>
        </div>
    </div>
@endif

<div class="row g-4">
    <div class="col-12 col-lg-7">

        {{-- Progress --}}
        @if($stages)
        <div class="card shadow-sm border-0 mb-4">
            <div class="card-header bg-transparent fw-semibold">
                <i class="bi bi-list-check me-2"></i>Progress
            </div>
            <div class="card-body">
                @foreach($stages as $stage)
                    <div class="d-flex gap-3 {{ ! $loop->last ? 'mb-3 pb-3 border-bottom' : '' }}">
                        <div>
                            @if($stage['state'] === 'done')
                                <i class="bi bi-check-circle-fill text-success fs-5"></i>
                            @elseif($stage['state'] === 'current')
                                <i class="bi bi-arrow-right-circle-fill text-primary fs-5"></i>
                            @else
                                <i class="bi bi-circle text-muted fs-5"></i>
                            @endif
                        </div>
                        <div>
                            <div class="fw-semibold {{ $stage['state'] === 'pending' ? 'text-muted' : '' }}">
                                {{ $stage['label'] }}
                            </div>
                            <div class="text-muted small">{{ $stage['detail'] }}</div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
        @endif

        {{-- The new hire --}}
        @if($isOnboarding)
        <div class="card shadow-sm border-0 mb-4">
            <div class="card-header bg-transparent fw-semibold">
                <i class="bi bi-person-vcard me-2"></i>Employee details
            </div>
            <div class="card-body">
                @php
                    $rows = [
                        'Full name'   => $payload['display_name'] ?? trim(($payload['first_name'] ?? '').' '.($payload['last_name'] ?? '')),
                        'Work email'  => $payload['upn'] ?? null,
                        'Extension'   => $payload['extension'] ?? null,
                        'Job title'   => $payload['job_title'] ?? $employee?->job_title,
                        'Department'  => $employee?->department?->name,
                        'Branch'      => $employee?->branch?->name ?? $workflow->branch?->name,
                        'Gender'      => isset($payload['gender']) ? ucfirst($payload['gender']) : null,
                        'Mobile'      => $payload['mobile_phone'] ?? null,
                        'Manager'     => $payload['manager_name'] ?? $employee?->manager?->name,
                        'Supervisor'  => $payload['supervisor_name'] ?? $employee?->supervisor?->name,
                        'Start date'  => isset($payload['start_date']) ? \Carbon\Carbon::parse($payload['start_date'])->format('d M Y') : null,
                        'HR reference'=> $payload['hr_reference'] ?? null,
                    ];
                @endphp
                <div class="row g-3 small">
                    @foreach($rows as $label => $value)
                        <div class="col-6 col-md-4">
                            <div class="text-muted">{{ $label }}</div>
                            <div class="fw-semibold {{ $label === 'Work email' || $label === 'Extension' ? 'font-monospace' : '' }}">
                                {{ $value ?: '—' }}
                            </div>
                        </div>
                    @endforeach
                </div>

                @if(! empty($payload['assigned_licenses']))
                    <hr>
                    <div class="text-muted small mb-1">Licences assigned</div>
                    @foreach($payload['assigned_licenses'] as $lic)
                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle">
                            {{ $lic['name'] ?? $lic['sku'] }}
                        </span>
                    @endforeach
                @endif

                <div class="alert alert-light border small mt-3 mb-0">
                    <i class="bi bi-shield-lock me-1"></i>
                    Passwords are never shown here. IT shares the sign-in details with the
                    new employee directly.
                </div>

                @php $appAccounts = $employee?->appAccounts()->with('app')->get() ?? collect(); @endphp
                @if($appAccounts->isNotEmpty())
                    <hr>
                    <div class="text-muted small mb-2">Business applications</div>
                    @foreach($appAccounts as $acct)
                        <div class="d-flex justify-content-between align-items-center small {{ ! $loop->last ? 'mb-2' : '' }}">
                            <span class="fw-semibold">{{ $acct->app?->name }}</span>
                            <span class="badge {{ $acct->statusBadgeClass() }}">{{ $acct->statusLabel() }}</span>
                        </div>
                    @endforeach
                    <div class="form-text">
                        These accounts are created by the team that runs each system, not by IT.
                    </div>
                @endif

                @if($employee)
                    <div class="mt-3">
                        <span class="text-muted small">Employee record #{{ $employee->id }} created.</span>
                    </div>
                @endif
            </div>
        </div>
        @endif

        {{-- Rendered signature --}}
        @if($isOnboarding && ($signatureHtml || $signatureError))
        <div class="card shadow-sm border-0 mb-4">
            <div class="card-header bg-transparent fw-semibold">
                <i class="bi bi-pen me-2"></i>Email signature
            </div>
            <div class="card-body">
                @if($signatureHtml)
                    <div class="border rounded p-3 bg-white">
                        {{-- Rendered from the NOC template for this mailbox; this is
                             exactly what the employee's outgoing mail will carry. --}}
                        {!! $signatureHtml !!}
                    </div>
                @else
                    <div class="text-muted small">
                        <i class="bi bi-info-circle me-1"></i>{{ $signatureError }}
                    </div>
                @endif
            </div>
        </div>
        @endif
    </div>

    <div class="col-12 col-lg-5">

        {{-- Manager form status --}}
        @if($isOnboarding)
        <div class="card shadow-sm border-0 mb-4">
            <div class="card-header bg-transparent d-flex justify-content-between align-items-center">
                <span class="fw-semibold"><i class="bi bi-envelope-check me-2"></i>Manager setup form</span>
                @if($managerForm['answered'])
                    <span class="badge bg-success">Answered</span>
                @elseif($workflow->status === 'awaiting_manager_form')
                    <span class="badge bg-warning text-dark">Awaiting reply</span>
                @else
                    <span class="badge bg-secondary">Not sent yet</span>
                @endif
            </div>
            <div class="card-body small">
                <div class="mb-2">
                    <span class="text-muted">Sent to</span><br>
                    <span class="fw-semibold">{{ $managerForm['sent_to'] ?? '—' }}</span>
                </div>
                @if($managerForm['sent_at'])
                    <div class="mb-2">
                        <span class="text-muted">Sent</span><br>
                        {{ $managerForm['sent_at']->format('d M Y, H:i') }}
                        ({{ $managerForm['sent_at']->diffForHumans() }})
                    </div>
                @endif
                @if($managerForm['answered'])
                    <div class="mb-2">
                        <span class="text-muted">Answered</span><br>
                        {{ $managerForm['responded_at']->format('d M Y, H:i') }}
                        ({{ $managerForm['responded_at']->diffForHumans() }})
                    </div>
                    <hr>
                    <div class="row g-2">
                        <div class="col-6">
                            <div class="text-muted">IP phone</div>
                            <div class="fw-semibold">{{ ($payload['needs_extension'] ?? false) ? 'Yes' : 'No' }}</div>
                        </div>
                        <div class="col-6">
                            <div class="text-muted">Laptop</div>
                            <div class="fw-semibold">{{ ucfirst($payload['laptop_status'] ?? '—') }}</div>
                        </div>
                        <div class="col-6">
                            <div class="text-muted">Internet level</div>
                            <div class="fw-semibold">{{ $payload['internet_level'] ?? '—' }}</div>
                        </div>
                        <div class="col-6">
                            <div class="text-muted">Groups chosen</div>
                            <div class="fw-semibold">{{ count($payload['manager_groups'] ?? []) }}</div>
                        </div>
                    </div>
                    @if(! empty($payload['manager_comments']))
                        <hr>
                        <div class="text-muted">Manager comments</div>
                        <div>{{ $payload['manager_comments'] }}</div>
                    @endif
                @endif
            </div>
        </div>
        @endif

        {{-- Activity --}}
        <div class="card shadow-sm border-0">
            <div class="card-header bg-transparent fw-semibold">
                <i class="bi bi-clock-history me-2"></i>Activity
            </div>
            <div class="card-body p-0" style="max-height:420px; overflow-y:auto;">
                <ul class="list-group list-group-flush small">
                    @forelse($workflow->logs as $log)
                        <li class="list-group-item d-flex gap-2">
                            <span class="badge bg-{{ match($log->level) {
                                'success' => 'success', 'error' => 'danger',
                                'warning' => 'warning text-dark', default => 'secondary'
                            } }}" style="height:fit-content">{{ strtoupper($log->level) }}</span>
                            <div>
                                <div>{{ $log->message }}</div>
                                <div class="text-muted">{{ $log->created_at?->format('d M, H:i') }}</div>
                            </div>
                        </li>
                    @empty
                        <li class="list-group-item text-muted">Nothing logged yet.</li>
                    @endforelse
                </ul>
            </div>
        </div>
    </div>
</div>
@endsection
