<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\IdentityUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Dual-account linking: pair a secondary sign-in account (e.g. a SamirGroup
 * mailbox) with the primary employee record (the person's SSS HR record). The
 * secondary then inherits job title, department, extension, and mobile from the
 * primary — for both the NOC-rendered signature and the Azure contact sync —
 * while keeping its own branch (JED), name, and email.
 */
class LinkedAccountController extends Controller
{
    /** List existing links + the add form. */
    public function index(): View
    {
        $links = Employee::query()
            ->whereNotNull('linked_primary_employee_id')
            ->with(['branch', 'linkedPrimary.branch', 'linkedPrimary.department', 'identityUser'])
            ->orderBy('name')
            ->get();

        $branches = Branch::orderBy('name')->get();

        // Pre-select a JED branch when one exists (branch is "always JED" for these).
        $defaultBranchId = $branches->first(fn ($b) => str_contains(mb_strtolower($b->name), 'jed')
            || str_contains(mb_strtolower($b->name), 'jeddah'))?->id;

        return view('admin.identity.linked-accounts', compact('links', 'branches', 'defaultBranchId'));
    }

    /** Create (or update) the link. */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'secondary_email' => 'required|email',
            'primary_email' => 'required|email',
            'branch_id' => 'required|integer|exists:branches,id',
        ]);

        $secondaryEmail = mb_strtolower(trim($data['secondary_email']));
        $primaryEmail = mb_strtolower(trim($data['primary_email']));

        if ($secondaryEmail === $primaryEmail) {
            return back()->with('error', 'The secondary and primary accounts must be different.')->withInput();
        }

        // Resolve the secondary account from the Azure identity cache.
        $secondary = IdentityUser::whereRaw('LOWER(user_principal_name) = ?', [$secondaryEmail])
            ->orWhereRaw('LOWER(mail) = ?', [$secondaryEmail])
            ->first();

        if (! $secondary || empty($secondary->azure_id)) {
            return back()->with('error', "No Azure account found for {$secondaryEmail}. Run an Identity Sync first if it is new.")->withInput();
        }

        // Resolve the primary employee (the SSS HR record) by email, then by its Azure link.
        $primary = Employee::whereRaw('LOWER(email) = ?', [$primaryEmail])->first();
        if (! $primary) {
            $primaryIdentity = IdentityUser::whereRaw('LOWER(user_principal_name) = ?', [$primaryEmail])
                ->orWhereRaw('LOWER(mail) = ?', [$primaryEmail])
                ->first();
            if ($primaryIdentity) {
                $primary = Employee::where('azure_id', $primaryIdentity->azure_id)->first();
            }
        }

        if (! $primary) {
            return back()->with('error', "No employee record found for the primary {$primaryEmail}.")->withInput();
        }

        if ($primary->linked_primary_employee_id) {
            return back()->with('error', 'The primary you picked is itself a linked secondary. Choose the real HR record.')->withInput();
        }

        // Create or update the secondary's shadow employee row, keyed by its Azure id.
        $employee = Employee::firstOrNew(['azure_id' => $secondary->azure_id]);

        if ($employee->exists && $employee->id === $primary->id) {
            return back()->with('error', 'An account cannot be linked to itself.')->withInput();
        }

        $employee->fill([
            'name' => $primary->name ?: $secondary->display_name,
            'email' => $secondary->mail ?: $secondary->user_principal_name,
            'gender' => $primary->gender,
            'company' => $employee->company ?: 'Samir Group',
            'branch_id' => $data['branch_id'],
            'status' => 'active',
            'linked_primary_employee_id' => $primary->id,
        ]);
        $employee->save();

        ActivityLog::create([
            'model_type' => 'Employee',
            'model_id' => $employee->id,
            'action' => 'linked_account_set',
            'changes' => [
                'secondary' => $employee->email,
                'secondary_azure_id' => $employee->azure_id,
                'primary_employee_id' => $primary->id,
                'primary' => $primary->email,
                'branch_id' => $data['branch_id'],
            ],
            'user_id' => Auth::id(),
        ]);

        return back()->with('success', "Linked {$employee->email} → {$primary->name}. Its signature and Azure contact data now follow the primary. Run a Bulk Azure Contact Sync to push it to Azure.");
    }

    /** Remove a link (unlinks; keeps the shadow row so history/assets survive). */
    public function destroy(Employee $employee): RedirectResponse
    {
        if (! $employee->linked_primary_employee_id) {
            return back()->with('error', 'That employee is not a linked account.');
        }

        $employee->update(['linked_primary_employee_id' => null]);

        ActivityLog::create([
            'model_type' => 'Employee',
            'model_id' => $employee->id,
            'action' => 'linked_account_removed',
            'changes' => ['secondary' => $employee->email],
            'user_id' => Auth::id(),
        ]);

        return back()->with('success', "Unlinked {$employee->email}. It no longer inherits HR data from a primary.");
    }
}
