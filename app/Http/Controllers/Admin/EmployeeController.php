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
        $availableLicenses = \App\Models\License::all()->filter(fn($l) => $l->availableSeats() > 0)->values();

        // License assignments for this employee (morphMany)
        $licenseAssignments = \App\Models\LicenseAssignment::with('license')
            ->where('assignable_type', Employee::class)
            ->where('assignable_id', $employee->id)
            ->get();

        return view('admin.employees.show', compact(
            'employee', 'availableDevices', 'availableAccessories',
            'availableLicenses', 'licenseAssignments'
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

        // Check not already assigned
        $existing = EmployeeAsset::where('asset_id', $validated['asset_id'])
            ->whereNull('returned_date')
            ->first();

        if ($existing) {
            return back()->with('error', 'This asset is already assigned to another employee.');
        }

        EmployeeAsset::create(array_merge($validated, ['employee_id' => $employee->id]));

        Device::where('id', $validated['asset_id'])->update(['status' => 'assigned']);

        return back()->with('success', 'Asset assigned successfully.');
    }

    public function returnAsset(Request $request, Employee $employee, EmployeeAsset $asset)
    {
        $request->validate([
            'returned_date' => 'required|date',
            'condition'     => 'required|in:good,fair,poor',
            'notes'         => 'nullable|string|max:500',
        ]);

        $asset->update([
            'returned_date' => $request->returned_date,
            'condition'     => $request->condition,
            'notes'         => $request->notes,
        ]);

        Device::where('id', $asset->asset_id)->update(['status' => 'available']);

        return back()->with('success', 'Asset returned successfully.');
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
