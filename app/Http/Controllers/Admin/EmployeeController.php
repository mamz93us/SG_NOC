<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Device;
use App\Models\Employee;
use App\Models\EmployeeAsset;
use App\Models\AllowedDomain;
use App\Models\IdentityUser;
use App\Services\PhoneDeviceLookup;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EmployeeController extends Controller
{
    public function index(Request $request)
    {
        $query = Employee::query()
            ->leftJoin('branches', 'employees.branch_id', '=', 'branches.id')
            ->leftJoin('departments', 'employees.department_id', '=', 'departments.id')
            ->select('employees.*')
            ->with('branch', 'department')
            ->withCount('activeAssets');

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(fn ($q) => $q->where('employees.name', 'like', "%{$s}%")
                ->orWhere('employees.email', 'like', "%{$s}%")
                ->orWhere('employees.job_title', 'like', "%{$s}%"));
        }

        if ($request->filled('status')) {
            $query->where('employees.status', $request->status);
        }

        if ($request->filled('branch_id')) {
            $query->where('employees.branch_id', $request->branch_id);
        }

        if ($request->filled('has_assets')) {
            if ($request->has_assets === 'yes') {
                $query->has('activeAssets');
            } elseif ($request->has_assets === 'no') {
                $query->doesntHave('activeAssets');
            }
        }

        // Sorting
        $sort = $request->get('sort', 'name');
        $direction = $request->get('direction', 'asc') === 'desc' ? 'desc' : 'asc';
        
        $sortMap = [
            'name'       => 'employees.name',
            'branch'     => 'branches.name',
            'department' => 'departments.name',
            'job_title'  => 'employees.job_title',
            'status'     => 'employees.status',
            'assets'     => 'active_assets_count',
            'hired'      => 'employees.hired_date',
        ];

        $orderCol = $sortMap[$sort] ?? 'employees.name';
        $query->orderBy($orderCol, $direction);

        $employees = $query->paginate(25)->withQueryString();
        $branches  = Branch::orderBy('name')->get();
        $total     = Employee::count();

        return view('admin.employees.index', compact('employees', 'branches', 'total'));
    }

    public function search(Request $request)
    {
        $q         = $request->get('q', '');
        $branchId  = $request->get('branch_id');

        $query = Employee::query()
            ->where('status', 'active')
            ->where(fn ($x) => $x->where('name', 'like', "%{$q}%")
                ->orWhere('email', 'like', "%{$q}%"))
            ->when($branchId, fn ($x) => $x->where('branch_id', $branchId))
            ->orderBy('name')
            ->limit(20)
            ->get(['id', 'name', 'email', 'branch_id']);

        return response()->json($query);
    }

    public function show(Employee $employee)
    {
        $employee->load([
            'branch.ucmServer',
            'department',
            'manager',
            'activeAssets.device',
            'assetAssignments.device',
            'activeItems',
            'items',
            'identityUser',
            'accessoryAssignments.accessory',
            'contact',
        ]);

        // Only show user-equipment types in the assign modal (laptops, monitors, etc.)
        $availableDevices = Device::userEquipment()
            ->where('status', 'available')
            ->orderBy('type')
            ->orderBy('name')
            ->get();

        // Available accessories for the assign modal
        $availableAccessories = \App\Models\Accessory::where('quantity_available', '>', 0)
            ->orderBy('name')->get();

        // Available licenses (with seats remaining) for the assign modal
        // Eager-count assignments and filter in SQL to avoid N+1 usedSeats() calls.
        $availableLicenses = \App\Models\License::withCount('assignments')
            ->get()
            ->filter(fn($l) => ($l->seats - $l->assignments_count) > 0)
            ->values();

        // License assignments for this employee (morphMany)
        $licenseAssignments = \App\Models\LicenseAssignment::with('license')
            ->where('assignable_type', Employee::class)
            ->where('assignable_id', $employee->id)
            ->get();

        // Resolve linked phone device from extension or linked contact's phone
        $phoneInfo = null;
        $extensionToLookup = $employee->extension_number
            ?: ($employee->contact?->phone ?? null);

        if ($extensionToLookup) {
            $ucmServerId = $employee->ucm_server_id
                ?? $employee->branch?->ucmServer?->id;
            $phoneInfo = PhoneDeviceLookup::findByExtension(
                $extensionToLookup, $ucmServerId
            );
        }

        return view('admin.employees.show', compact(
            'employee', 'availableDevices', 'availableAccessories',
            'availableLicenses', 'licenseAssignments', 'phoneInfo'
        ));
    }

    public function create()
    {
        $branches    = Branch::orderBy('name')->get();
        $departments = Department::orderBy('name')->get();
        $managers    = Employee::where('status', 'active')->orderBy('name')->get();
        $azureUsers  = IdentityUser::where('account_enabled', true)->orderBy('display_name')->get();

        return view('admin.employees.form', compact('branches', 'departments', 'managers', 'azureUsers'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'          => 'required|string|max:255',
            'email'         => 'nullable|email|max:255',
            'azure_id'      => 'nullable|string|max:100',
            'branch_id'     => 'nullable|exists:branches,id',
            'department_id' => 'nullable|exists:departments,id',
            'manager_id'    => 'nullable|exists:employees,id',
            'job_title'     => 'nullable|string|max:255',
            'status'        => 'required|in:active,terminated,on_leave',
            'hired_date'    => 'nullable|date',
            'notes'         => 'nullable|string|max:2000',
        ]);

        $employee = Employee::create($validated);

        return redirect()
            ->route('admin.employees.show', $employee->id)
            ->with('success', 'Employee created successfully.');
    }

    public function edit(Employee $employee)
    {
        $branches    = Branch::orderBy('name')->get();
        $departments = Department::orderBy('name')->get();
        $managers    = Employee::where('status', 'active')->where('id', '!=', $employee->id)->orderBy('name')->get();

        return view('admin.employees.form', compact('employee', 'branches', 'departments', 'managers'));
    }

    public function update(Request $request, Employee $employee)
    {
        $validated = $request->validate([
            'name'             => 'required|string|max:255',
            'email'            => 'nullable|email|max:255',
            'azure_id'         => 'nullable|string|max:100',
            'branch_id'        => 'nullable|exists:branches,id',
            'department_id'    => 'nullable|exists:departments,id',
            'manager_id'       => 'nullable|exists:employees,id',
            'job_title'        => 'nullable|string|max:255',
            'status'           => 'required|in:active,terminated,on_leave',
            'hired_date'       => 'nullable|date',
            'terminated_date'  => 'nullable|date|after_or_equal:hired_date',
            'notes'            => 'nullable|string|max:2000',
        ]);

        $employee->update($validated);

        return redirect()
            ->route('admin.employees.show', $employee->id)
            ->with('success', 'Employee updated successfully.');
    }

    public function assignAsset(Request $request, Employee $employee)
    {
        $validated = $request->validate([
            'asset_id'      => 'required|exists:devices,id',
            'assigned_date' => 'required|date',
            'condition'     => 'required|in:good,fair,poor',
            'notes'         => 'nullable|string|max:500',
        ]);

        // 1. Prevent double-click submissions (debounce)
        $exists = EmployeeAsset::where('asset_id', $validated['asset_id'])
            ->where('employee_id', $employee->id)
            ->whereNull('returned_date')
            ->where('created_at', '>=', now()->subSeconds(15))
            ->exists();

        if ($exists) {
            return back()->with('warning', 'Recent assignment detected. Please refresh.');
        }

        // 2. Check not already assigned to anyone else
        $activeAssignment = EmployeeAsset::where('asset_id', $validated['asset_id'])
            ->whereNull('returned_date')
            ->first();

        if ($activeAssignment) {
            return back()->with('error', 'This asset is already assigned to ' . ($activeAssignment->employee?->name ?? 'someone else') . '.');
        }

        // 3. Update status and create record atomically
        \Illuminate\Support\Facades\DB::transaction(function() use ($validated, $employee) {
            EmployeeAsset::create(array_merge($validated, ['employee_id' => $employee->id]));
            Device::where('id', $validated['asset_id'])->update(['status' => 'assigned']);
        });

        return back()->with('success', 'Asset assigned successfully.');
    }

    public function returnAsset(Request $request, Employee $employee, EmployeeAsset $asset)
    {
        // Guard against URL tampering: the assignment must belong to this employee
        // and must still be an open (not-yet-returned) assignment.
        abort_unless($asset->employee_id === $employee->id, 404);
        abort_if($asset->returned_date !== null, 409, 'Asset has already been returned.');

        $request->validate([
            'returned_date' => 'required|date',
            'condition'     => 'required|in:good,fair,poor',
            'notes'         => 'nullable|string|max:500',
        ]);

        \Illuminate\Support\Facades\DB::transaction(function () use ($asset, $request) {
            $asset->update([
                'returned_date' => $request->returned_date,
                'condition'     => $request->condition,
                'notes'         => $request->notes,
            ]);

            Device::where('id', $asset->asset_id)->update(['status' => 'available']);
        });

        return back()->with('success', 'Asset returned successfully.');
    }

    // ─────────────────────────────────────────────────────────────
    // Contact Linking
    // ─────────────────────────────────────────────────────────────

    /**
     * Link an employee to a contact (by contact_id).
     */
    public function linkContact(Request $request, Employee $employee)
    {
        $request->validate(['contact_id' => 'required|exists:contacts,id']);

        $contact = \App\Models\Contact::findOrFail($request->contact_id);
        $employee->update(['contact_id' => $contact->id]);

        // Auto-fill extension_number from contact's phone if employee doesn't have one
        if (!$employee->extension_number && $contact->phone) {
            $employee->update(['extension_number' => $contact->phone]);
        }

        return back()->with('success', "Linked to contact: {$contact->first_name} {$contact->last_name}");
    }

    /**
     * Unlink the contact from an employee.
     */
    public function unlinkContact(Employee $employee)
    {
        $employee->update(['contact_id' => null]);

        return back()->with('success', 'Contact unlinked.');
    }

    /**
     * Auto-link all employees to contacts by matching email addresses.
     * Uses raw SQL join-update for performance (handles thousands of rows instantly).
     */
    public function autoLinkContacts()
    {
        $this->authorize('manage-employees');

        // Diagnostics: count potential matches
        $empWithEmail = \Illuminate\Support\Facades\DB::table('employees')
            ->whereNull('contact_id')
            ->whereNotNull('email')->where('email', '!=', '')->count();

        $contactsWithEmail = \Illuminate\Support\Facades\DB::table('contacts')
            ->whereNotNull('email')->where('email', '!=', '')->count();

        // Step 1: Link contact_id by matching email (single UPDATE … JOIN)
        $linked = \Illuminate\Support\Facades\DB::update("
            UPDATE employees e
            INNER JOIN contacts c ON LOWER(TRIM(c.email)) = LOWER(TRIM(e.email))
            SET e.contact_id = c.id
            WHERE e.contact_id IS NULL
              AND e.email IS NOT NULL
              AND e.email != ''
              AND c.email IS NOT NULL
              AND c.email != ''
        ");

        // Step 2: Auto-fill extension_number from linked contact's phone where missing
        $extensionsFilled = \Illuminate\Support\Facades\DB::update("
            UPDATE employees e
            INNER JOIN contacts c ON c.id = e.contact_id
            SET e.extension_number = c.phone
            WHERE (e.extension_number IS NULL OR e.extension_number = '')
              AND c.phone IS NOT NULL
              AND c.phone != ''
        ");

        $msg = "Auto-linked {$linked} employee(s) to contacts by email.";
        if ($extensionsFilled > 0) {
            $msg .= " {$extensionsFilled} extension number(s) auto-filled.";
        }
        $msg .= " (Scanned: {$empWithEmail} employees with email, {$contactsWithEmail} contacts with email)";

        return back()->with('success', $msg);
    }

    public function report(Employee $employee)
    {
        $employee->load([
            'branch', 'department', 'manager',
            'assetAssignments.device',
            'activeItems',
            'accessoryAssignments.accessory',
            'identityUser',
        ]);

        $licenseAssignments = \App\Models\LicenseAssignment::with('license')
            ->where('assignable_type', Employee::class)
            ->where('assignable_id', $employee->id)
            ->get();

        $settings = \App\Models\Setting::first();

        return view('admin.employees.report', compact('employee', 'licenseAssignments', 'settings'));
    }

    // ─────────────────────────────────────────────────────────────
    // Azure Sync
    // ─────────────────────────────────────────────────────────────

    public function showSync()
    {
        $this->authorize('manage-employees');

        // Get existing Azure IDs already linked
        $linkedAzureIds = Employee::whereNotNull('azure_id')->pluck('azure_id')->toArray();

        // Get allowed domains for filtering
        $allowedDomains = \App\Models\AllowedDomain::getList();

        // Find unlinked Azure users: no #EXT# and not already linked
        $query = IdentityUser::whereNotIn('azure_id', $linkedAzureIds)
            ->where('account_enabled', true)
            ->where('user_principal_name', 'not like', '%#EXT#%')
            ->orderBy('display_name');

        // If allowed domains are configured, filter to those domains
        if (! empty($allowedDomains)) {
            $query->where(function ($q) use ($allowedDomains) {
                foreach ($allowedDomains as $domain) {
                    $q->orWhere('user_principal_name', 'like', "%@{$domain}");
                }
            });
        }

        $azureUsers = $query->get();
        $departments = Department::orderBy('name')->get();
        $branches    = Branch::orderBy('name')->get();

        return view('admin.employees.sync', compact('azureUsers', 'departments', 'branches'));
    }

    public function doSync(Request $request)
    {
        $this->authorize('manage-employees');

        $request->validate([
            'azure_ids'   => 'required|array|min:1',
            'azure_ids.*' => 'required|string',
            'branch_id'   => 'nullable|exists:branches,id',
        ]);

        $created = 0;
        foreach ($request->azure_ids as $azureId) {
            $identityUser = IdentityUser::where('azure_id', $azureId)->first();
            if (! $identityUser) continue;

            // Skip if already linked by azure_id
            if (Employee::where('azure_id', $azureId)->exists()) continue;

            // Also deduplicate by email — link if a manual employee already exists
            $email = $identityUser->mail ?? $identityUser->user_principal_name;
            if ($email && ($existing = Employee::where('email', $email)->whereNull('azure_id')->first())) {
                $existing->update(['azure_id' => $azureId]);
                continue;
            }
            // ── Auto-match Department (create if not found) ───────
            $department = null;
            if (! empty($identityUser->department)) {
                $department = Department::firstOrCreate(
                    ['name' => $identityUser->department]
                );
            }

            // ── Auto-match Manager (only if already imported) ─────
            $manager = null;
            if (! empty($identityUser->manager_azure_id)) {
                $manager = Employee::where('azure_id', $identityUser->manager_azure_id)->first();
            }

            // ── Auto-match Branch via office_location → branch name ──
            // Falls back to the form's selected fallback branch_id.
            $branchId = $request->branch_id;
            if (! empty($identityUser->office_location)) {
                $matchedBranch = Branch::where('name', 'like', $identityUser->office_location)->first();
                if ($matchedBranch) {
                    $branchId = $matchedBranch->id;
                }
            }

            Employee::create([
                'azure_id'      => $azureId,
                'name'          => $identityUser->display_name,
                'email'         => $email,
                'branch_id'     => $branchId,
                'department_id' => $department?->id,
                'manager_id'    => $manager?->id,
                'job_title'     => $identityUser->job_title,
                'status'        => 'active',
                'hired_date'    => now()->toDateString(),
            ]);
            $created++;
        }

        return redirect()
            ->route('admin.employees.index')
            ->with('success', "{$created} employee(s) imported from Azure.");
    }
}
