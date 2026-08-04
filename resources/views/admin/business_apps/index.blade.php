@extends('layouts.admin')

@section('title', 'Business App Accounts')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-app-indicator me-2 text-primary"></i>Business App Accounts</h4>
        <small class="text-muted">Salesforce, Oracle and other systems NOC requests but does not create</small>
    </div>
    <a href="/admin/identity/group-mappings" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-diagram-3 me-1"></i>Group Auto-Assignments
    </a>
</div>

<div class="alert alert-info d-flex gap-2">
    <i class="bi bi-info-circle fs-5"></i>
    <div class="small">
        The manager is asked about these on the onboarding setup form. When one is requested, NOC
        adds the employee to the security group below and emails the addresses below with their
        details so the account can be created — NOC does not create it itself.
        <strong>Without at least one email address, a request has nowhere to go</strong> and the app
        is hidden from the manager's form.
    </div>
</div>

<form method="POST" action="{{ route('admin.business-apps.update') }}">
    @csrf
    <div class="card shadow-sm border-0">
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="min-width:190px">Application</th>
                        <th style="min-width:280px">Send account request to</th>
                        <th style="min-width:240px">Security group</th>
                        <th style="width:110px" class="text-center">Offered</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($apps as $app)
                    <tr>
                        <td>
                            <div class="fw-semibold">{{ $app->name }}</div>
                            <div class="text-muted small">{{ $app->description }}</div>
                            @if(! $app->isConfigured() && $app->is_active)
                                <span class="badge bg-warning text-dark mt-1">
                                    <i class="bi bi-exclamation-triangle me-1"></i>No recipients set
                                </span>
                            @endif
                        </td>
                        <td>
                            <input type="text" class="form-control form-control-sm font-monospace"
                                   name="apps[{{ $app->id }}][request_emails]"
                                   value="{{ old("apps.{$app->id}.request_emails", $app->request_emails) }}"
                                   placeholder="crm-admin@samirgroup.com, it@samirgroup.com">
                            <div class="form-text">Comma-separated. These people create the account.</div>
                        </td>
                        <td>
                            <select name="apps[{{ $app->id }}][identity_group_id]" class="form-select form-select-sm">
                                <option value="">— No group —</option>
                                @foreach($groups as $g)
                                    <option value="{{ $g->id }}"
                                        @selected(old("apps.{$app->id}.identity_group_id", $app->identity_group_id) == $g->id)>
                                        {{ $g->display_name }}
                                    </option>
                                @endforeach
                            </select>
                            <div class="form-text">Assigned automatically when requested.</div>
                        </td>
                        <td class="text-center">
                            <input type="hidden" name="apps[{{ $app->id }}][is_active]" value="0">
                            <div class="form-check form-switch d-inline-block">
                                <input class="form-check-input" type="checkbox" value="1"
                                       name="apps[{{ $app->id }}][is_active]"
                                       @checked(old("apps.{$app->id}.is_active", $app->is_active))>
                            </div>
                            <div class="form-text">On the manager form</div>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="card-footer bg-transparent d-flex justify-content-between align-items-center">
            <span class="text-muted small">Applies to the next onboarding request — existing ones are unaffected.</span>
            <button type="submit" class="btn btn-primary">
                <i class="bi bi-save me-1"></i>Save
            </button>
        </div>
    </div>
</form>
@endsection
