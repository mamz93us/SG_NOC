<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\IdentityUser;
use App\Services\Identity\EmployeeAccountLinker;
use App\Services\Identity\LinkedAccountSuggester;
use App\Services\People\EmployeeMerger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
    /**
     * People with more than one record: the ones the NOC suggests linking, the
     * ones already linked (grouped by their main record), and the manual form.
     */
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q', ''));
        $matches = fn (?string ...$values) => $search === ''
            || str_contains(mb_strtolower(implode(' ', array_filter($values))), mb_strtolower($search));

        $links = Employee::query()
            ->whereNotNull('linked_primary_employee_id')
            ->with(['branch', 'linkedPrimary.branch', 'linkedPrimary.department', 'linkedPrimary.identityUser', 'identityUser'])
            ->orderBy('name')
            ->get();
        $linkedPeople = $links
            ->groupBy('linked_primary_employee_id')
            ->map(fn ($accounts) => ['primary' => $accounts->first()->linkedPrimary, 'accounts' => $accounts])
            ->filter(fn ($person) => $person['primary']
                ? $matches($person['primary']->name, $person['primary']->email, ...$person['accounts']->pluck('email')->all())
                : $matches(...$person['accounts']->pluck('email')->all()))
            ->sortBy(fn ($person) => mb_strtolower($person['primary']?->name ?? ''))
            ->values();

        $employees = Employee::query()
            ->leftJoin('branches', 'branches.id', '=', 'employees.branch_id')
            ->leftJoin('departments', 'departments.id', '=', 'employees.department_id')
            ->toBase()
            ->get([
                'employees.id', 'employees.name', 'employees.email', 'employees.azure_id', 'employees.status',
                'employees.employee_type', 'employees.oracle_emp_no', 'employees.linked_primary_employee_id',
                'employees.job_title', 'branches.name as branch', 'departments.name as department',
            ]);
        $lastPunch = Schema::hasTable('attendance_punches')
            ? DB::table('attendance_punches')->whereNotNull('employee_id')->groupBy('employee_id')
                ->selectRaw('employee_id, max(punch_time) as last_punch')->pluck('last_punch', 'employee_id')->all()
            : [];
        $suggestions = array_values(array_filter(
            LinkedAccountSuggester::suggest($employees, $lastPunch, IdentityUser::query()->pluck('azure_id')),
            fn ($s) => $matches($s['primary']['name'], $s['primary']['email'], ...array_column($s['secondaries'], 'email'), ...array_column($s['secondaries'], 'name')),
        ));

        // Sign-in state and licence count of every account shown, by Azure object id.
        $azureIds = collect($suggestions)
            ->flatMap(fn ($s) => array_merge([$s['primary']['azure_id']], array_column($s['secondaries'], 'azure_id')))
            ->filter()->unique()->values();
        $identity = IdentityUser::whereIn('azure_id', $azureIds)->get(['azure_id', 'account_enabled', 'licenses_count'])->keyBy('azure_id');

        $branches = Branch::orderBy('name')->get();

        // Pre-select a JED branch when one exists (branch is "always JED" for these).
        $defaultBranchId = $branches->first(fn ($b) => str_contains(mb_strtolower($b->name), 'jed')
            || str_contains(mb_strtolower($b->name), 'jeddah'))?->id;

        return view('admin.identity.linked-accounts', [
            'search' => $search,
            'merges' => array_values(array_filter($suggestions, fn ($s) => $s['kind'] === 'merge')),
            'suggestions' => array_values(array_filter($suggestions, fn ($s) => $s['kind'] === 'link')),
            'identity' => $identity,
            'linkedPeople' => $linkedPeople,
            'linkCount' => $links->count(),
            'branches' => $branches,
            'defaultBranchId' => $defaultBranchId,
            'canSeeAttendance' => (bool) $request->user()?->can('view-attendance'),
        ]);
    }

    /**
     * Link suggested (or hand-picked) records to their main record. One button
     * links one person; "Link checked" links every ticked suggestion at once.
     */
    public function link(Request $request, EmployeeAccountLinker $linker): RedirectResponse
    {
        $data = $request->validate([
            'only' => 'nullable|integer|min:0',
            'links' => 'required|array|min:1',
            'links.*.selected' => 'nullable|boolean',
            'links.*.primary_id' => 'required|integer|exists:employees,id',
            'links.*.secondary_ids' => 'required|array|min:1',
            'links.*.secondary_ids.*' => 'integer|exists:employees,id',
        ]);

        $rows = isset($data['only'])
            ? array_intersect_key($data['links'], [(int) $data['only'] => true])
            : array_filter($data['links'], fn ($row) => ! empty($row['selected']));

        if ($rows === []) {
            return back()->with('error', 'Tick at least one person to link.');
        }

        $linked = [];
        $skipped = [];
        foreach ($rows as $row) {
            $result = $linker->link(Employee::findOrFail($row['primary_id']), $row['secondary_ids']);
            $linked = array_merge($linked, $result['linked']);
            $skipped = array_merge($skipped, $result['skipped']);
        }

        $message = $linked
            ? 'Linked '.count($linked).' account(s): '.implode(', ', $linked).'. Their signature and Azure contact data now follow the main record; run a Bulk Azure Contact Sync to push it to Azure.'
            : 'Nothing was linked.';
        if ($skipped) {
            $message .= ' Skipped: '.implode(' ', $skipped);
        }

        return back()->with($linked ? 'success' : 'error', $message);
    }

    /**
     * Merge records that are the same person into one: the record kept is the
     * one with the Microsoft account (EmployeeMerger::order()), and the others
     * are deleted once everything pointing at them has moved. Employee data, so
     * it takes manage-employees on top of the page's manage-identity.
     */
    public function merge(Request $request, EmployeeMerger $merger): RedirectResponse
    {
        abort_unless((bool) $request->user()?->can('manage-employees'), 403);

        $data = $request->validate([
            'only' => 'nullable|integer|min:0',
            'merges' => 'required|array|min:1',
            'merges.*.selected' => 'nullable|boolean',
            'merges.*.employee_ids' => 'required|array|min:2',
            'merges.*.employee_ids.*' => 'integer|distinct|exists:employees,id',
        ]);

        $rows = isset($data['only'])
            ? array_intersect_key($data['merges'], [(int) $data['only'] => true])
            : array_filter($data['merges'], fn ($row) => ! empty($row['selected']));

        if ($rows === []) {
            return back()->with('error', 'Tick at least one person to merge.');
        }

        $merged = [];
        $refused = [];
        foreach ($rows as $row) {
            [$keep, $duplicates] = EmployeeMerger::order(Employee::whereKey($row['employee_ids'])->get());
            foreach ($duplicates as $duplicate) {
                $label = "#{$duplicate->id} into {$keep->name} (#{$keep->id})";
                $result = $merger->merge($keep->fresh(), $duplicate);
                if ($result['merged']) {
                    $merged[] = $label;
                } else {
                    $refused[] = "{$label}: ".implode(' ', $result['problems']);
                }
            }
        }

        $message = $merged
            ? 'Merged '.count($merged).' duplicate record(s): '.implode(', ', $merged).'.'
            : 'Nothing was merged.';
        if ($refused) {
            $message .= ' Not merged: '.implode(' ', $refused);
        }

        return back()->with($merged ? 'success' : 'error', $message);
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
