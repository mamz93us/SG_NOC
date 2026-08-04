<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SendBusinessAppAccountMailJob;
use App\Mail\BusinessAppAccountMail;
use App\Models\ActivityLog;
use App\Models\BusinessApp;
use App\Models\Employee;
use App\Models\EmployeeAppAccount;
use App\Services\Identity\GraphService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Business app access for an existing employee — the add/remove controls on
 * their profile, as opposed to the onboarding path where the manager chooses.
 *
 * Each action does the two things NOC actually can: move the Azure security
 * group membership, and email the team who administers the system. The account
 * itself is created or disabled by them.
 */
class EmployeeAppAccountController extends Controller
{
    /**
     * POST /admin/employees/{employee}/app-accounts
     * Grant access to a business app.
     */
    public function store(Request $request, Employee $employee): RedirectResponse
    {
        $validated = $request->validate([
            'business_app_id' => 'required|integer|exists:business_apps,id',
            'notes' => 'nullable|string|max:500',
        ]);

        $app = BusinessApp::with('identityGroup')->findOrFail($validated['business_app_id']);

        if (! $app->isConfigured()) {
            return back()->with('error',
                "{$app->name} has no request recipients configured, so nobody would be told to create the account. Set one in Admin → Business App Accounts.");
        }

        $existing = EmployeeAppAccount::where('employee_id', $employee->id)
            ->where('business_app_id', $app->id)
            ->first();

        if ($existing && $existing->status !== EmployeeAppAccount::STATUS_REVOKED) {
            return back()->with('error', "{$employee->name} already has {$app->name} access recorded.");
        }

        $this->moveGroupMembership($employee, $app, add: true);

        // Re-granting after a revoke reuses the row so the profile keeps one
        // entry per app rather than accumulating history rows.
        $account = $existing ?: new EmployeeAppAccount([
            'employee_id' => $employee->id,
            'business_app_id' => $app->id,
        ]);

        $account->fill([
            'status' => EmployeeAppAccount::STATUS_REQUESTED,
            'requested_at' => now(),
            'activated_at' => null,
            'revoked_at' => null,
            'notes' => $validated['notes'] ?? null,
        ])->save();

        SendBusinessAppAccountMailJob::dispatch(
            $employee->id,
            $app->id,
            BusinessAppAccountMail::ACTIVATE,
            null,
            $validated['notes'] ?? null,
        );

        $this->audit($employee, $app, 'business_app_access_granted', $validated['notes'] ?? null);

        return back()->with('success',
            "{$app->name} access requested for {$employee->name}. The {$app->name} team has been emailed.");
    }

    /**
     * PATCH /admin/employees/{employee}/app-accounts/{account}/activate
     * Mark an account as confirmed created. No email — this records a fact.
     */
    public function activate(Request $request, Employee $employee, EmployeeAppAccount $account): RedirectResponse
    {
        abort_unless($account->employee_id === $employee->id, 404);

        $validated = $request->validate([
            'account_identifier' => 'nullable|string|max:191',
        ]);

        $account->update([
            'status' => EmployeeAppAccount::STATUS_ACTIVE,
            'activated_at' => now(),
            'account_identifier' => $validated['account_identifier'] ?: $account->account_identifier,
        ]);

        $this->audit($employee, $account->app, 'business_app_access_activated');

        return back()->with('success', "{$account->app?->name} marked active for {$employee->name}.");
    }

    /**
     * DELETE /admin/employees/{employee}/app-accounts/{account}
     * Revoke access: drop the security group and ask for the account to be disabled.
     */
    public function destroy(Request $request, Employee $employee, EmployeeAppAccount $account): RedirectResponse
    {
        abort_unless($account->employee_id === $employee->id, 404);

        $validated = $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        $app = $account->app;
        if (! $app) {
            $account->delete();

            return back()->with('success', 'Orphaned app access record removed.');
        }

        $this->moveGroupMembership($employee, $app, add: false);

        // Kept, not deleted: the record of having had access — and when it was
        // withdrawn — is the audit trail.
        $account->update([
            'status' => EmployeeAppAccount::STATUS_REVOKED,
            'revoked_at' => now(),
            'notes' => $validated['reason'] ?? $account->notes,
        ]);

        SendBusinessAppAccountMailJob::dispatch(
            $employee->id,
            $app->id,
            BusinessAppAccountMail::DEACTIVATE,
            null,
            $validated['reason'] ?? null,
        );

        $this->audit($employee, $app, 'business_app_access_revoked', $validated['reason'] ?? null);

        return back()->with('success',
            "{$app->name} access revoked for {$employee->name}. The {$app->name} team has been asked to disable the account.");
    }

    /**
     * Add or remove the app's Azure security group. Non-fatal: the email is the
     * part that actually gets the account changed, so a Graph failure must not
     * block it — but it is surfaced so nobody assumes access moved.
     */
    private function moveGroupMembership(Employee $employee, BusinessApp $app, bool $add): void
    {
        if (! $app->identityGroup?->azure_id || ! $employee->azure_id) {
            return;
        }

        try {
            $graph = new GraphService;

            if ($add) {
                $graph->addUserToGroup($employee->azure_id, $app->identityGroup->azure_id);
            } else {
                $graph->removeUserFromGroup($employee->azure_id, $app->identityGroup->azure_id);
            }
        } catch (\Throwable $e) {
            // 404/409 mean the membership was already in the desired state.
            if (! str_contains($e->getMessage(), '404') && ! str_contains($e->getMessage(), '409')) {
                session()->flash('warning',
                    "{$app->name} security group could not be updated in Azure: ".$e->getMessage());
            }
        }
    }

    private function audit(Employee $employee, ?BusinessApp $app, string $action, ?string $note = null): void
    {
        try {
            ActivityLog::create([
                'model_type' => Employee::class,
                'model_id' => $employee->id,
                'action' => $action,
                'changes' => [
                    'employee' => $employee->name,
                    'app' => $app?->name,
                    'note' => $note,
                ],
                'user_id' => Auth::id(),
            ]);
        } catch (\Throwable) {
            // never let audit failure break the action
        }
    }
}
