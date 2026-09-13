<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Attendance\AttendanceTask;
use App\Models\Attendance\BiotimeEmployee;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Device;
use App\Models\Employee;
use App\Models\EmployeeAsset;
use App\Models\EmployeeSignatureRole;
use App\Models\IdentityUser;
use App\Services\Identity\AzureContactSyncService;
use App\Services\PhoneDeviceLookup;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

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

        // Service employees (no mailbox — drivers, guards, warehouse) are listed
        // with everyone else and badged, never hidden by default: a filtered-out
        // person is one nobody remembers to look for.
        if ($request->filled('type')) {
            $request->type === Employee::TYPE_SERVICE
                ? $query->service()
                : $query->standard();
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
            'name' => 'employees.name',
            'branch' => 'branches.name',
            'department' => 'departments.name',
            'job_title' => 'employees.job_title',
            'status' => 'employees.status',
            'assets' => 'active_assets_count',
            'hired' => 'employees.hired_date',
        ];

        $orderCol = $sortMap[$sort] ?? 'employees.name';
        $query->orderBy($orderCol, $direction);

        $employees = $query->paginate(25)->withQueryString();
        $branches = Branch::orderBy('name')->get();
        $total = Employee::count();

        return view('admin.employees.index', compact('employees', 'branches', 'total'));
    }

    public function search(Request $request)
    {
        $q = $request->get('q', '');
        $branchId = $request->get('branch_id');

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
            'supervisor',
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
            ->filter(fn ($l) => ($l->seats - $l->assignments_count) > 0)
            ->values();

        // License assignments for this employee (morphMany)
        $licenseAssignments = \App\Models\LicenseAssignment::with('license')
            ->where('assignable_type', Employee::class)
            ->where('assignable_id', $employee->id)
            ->get();

        // Intune-managed devices (laptops etc.) owned by this employee, matched by UPN.
        $upn = $employee->identityUser?->user_principal_name ?: $employee->email;
        $azureDevices = $upn
            ? \App\Models\AzureDevice::where('upn', $upn)
                ->orderByDesc('last_sync_at')
                ->get()
            : collect();

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

        // Where this employee's devices were last seen on the network,
        // resolved from their MACs through the DHCP lease table.
        $networkPresence = app(\App\Services\Network\EmployeeNetworkLocator::class)->locate($employee);

        return view('admin.employees.show', compact(
            'employee', 'availableDevices', 'availableAccessories',
            'availableLicenses', 'licenseAssignments', 'phoneInfo', 'azureDevices',
            'networkPresence'
        ));
    }

    public function create()
    {
        $branches = Branch::orderBy('name')->get();
        $departments = Department::orderBy('name')->get();
        $azureUsers = IdentityUser::where('account_enabled', true)->orderBy('display_name')->get();

        return view('admin.employees.form', compact('branches', 'departments', 'azureUsers') + $this->hrFormData());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'azure_id' => 'nullable|string|max:100',
            'branch_id' => 'nullable|exists:branches,id',
            'department_id' => 'nullable|exists:departments,id',
            'job_title' => 'nullable|string|max:255',
            'gender' => 'nullable|in:male,female',
            'status' => 'required|in:active,terminated,on_leave',
            'hired_date' => 'nullable|date',
            'notes' => 'nullable|string|max:2000',
        ] + $this->contactRules() + $this->hrRules());

        $employee = Employee::create($this->hrFields($validated));

        return $this->savedRedirect($employee, 'Employee created successfully.', filled($employee->oracle_emp_no));
    }

    /** Validation rules for the per-employee contact fields (NOC = source of truth). */
    private function contactRules(): array
    {
        return [
            'mobile_phone' => 'nullable|string|max:50',
            'work_phone' => 'nullable|string|max:50',
            'extension_number' => 'nullable|string|max:20',   // pushed to Azure fax field (%%FaxNumber%%)
            'office_location' => 'nullable|string|max:120',
            'city' => 'nullable|string|max:120',
            'street_address' => 'nullable|string|max:255',
            'company' => 'nullable|string|max:150',
        ];
    }

    /**
     * Push the employee's contact fields to Azure AD (auto-sync on save).
     * Never blocks the save — returns a message + flash level to append.
     *
     * @return array{0: string, 1: string} [message, flashLevel]
     */
    private function pushToAzure(Employee $employee): array
    {
        if (empty($employee->azure_id)) {
            return ['', 'success'];
        }

        try {
            $service = app(AzureContactSyncService::class);
            $proposed = $service->computeFromEmployee($employee);
            if ($proposed === []) {
                return ['', 'success'];
            }
            $service->applyToEmployee($employee, $proposed);

            return [' Synced '.count($proposed).' field(s) to Azure AD.', 'success'];
        } catch (\Throwable $e) {
            Log::warning('EmployeeController: Azure push failed', [
                'employee_id' => $employee->id,
                'error' => $e->getMessage(),
            ]);

            if (AzureContactSyncService::isProtectedAdminError($e)) {
                return [' (Saved locally. This is a protected admin account in Entra — app-only sync '
                    .'cannot modify it; update it directly in Entra if needed.)', 'warning'];
            }

            return [' (Saved locally, but Azure sync failed: '.$e->getMessage().')', 'warning'];
        }
    }

    public function edit(Employee $employee)
    {
        $branches = Branch::orderBy('name')->get();
        $departments = Department::orderBy('name')->get();
        $employee->load('signatureRoles', 'manager.branch', 'supervisor.branch', 'linkedPrimary');

        return view('admin.employees.form', compact('employee', 'branches', 'departments') + $this->hrFormData($employee));
    }

    public function update(Request $request, Employee $employee)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'azure_id' => 'nullable|string|max:100',
            'branch_id' => 'nullable|exists:branches,id',
            'department_id' => 'nullable|exists:departments,id',
            'job_title' => 'nullable|string|max:255',
            'gender' => 'nullable|in:male,female',
            'status' => 'required|in:active,terminated,on_leave',
            'hired_date' => 'nullable|date',
            'terminated_date' => 'nullable|date|after_or_equal:hired_date',
            'notes' => 'nullable|string|max:2000',
        ] + $this->contactRules() + $this->hrRules());

        // Signature roles are managed by their own endpoints (store/update/destroy) so a
        // half-filled role can never block saving the employee profile.
        $employee->update($this->hrFields($validated, $employee));

        return $this->savedRedirect($employee, 'Employee updated successfully.', $employee->wasChanged('oracle_emp_no'));
    }

    /**
     * Back to the profile, with whatever the Azure push and a changed Oracle
     * number have to add. Either one turns the message into a warning.
     */
    private function savedRedirect(Employee $employee, string $message, bool $oracleNumberChanged)
    {
        $notes = [$this->pushToAzure($employee)];
        if ($oracleNumberChanged) {
            $notes[] = $this->oracleNumberChanged($employee);
        }

        $level = in_array('warning', array_column($notes, 1), true) ? 'warning' : 'success';

        return redirect()
            ->route('admin.employees.show', $employee->id)
            ->with($level, $message.implode('', array_column($notes, 0)));
    }

    // ─────────────────────────────────────────────────────────────
    // Oracle HR & reporting lines — the Oracle number, the Oracle
    // department, manager and supervisor, saved with the profile.
    // ─────────────────────────────────────────────────────────────

    /** Manager and supervisor arrive as picker text: "123 · Name · Oracle 456 · Branch". */
    private function hrRules(): array
    {
        return [
            'oracle_emp_no' => 'nullable|string|max:50',
            'oracle_department' => 'nullable|string|max:255',
            'oracle_dept_no' => 'nullable|string|max:50',
            'manager' => 'nullable|string|max:255',
            'supervisor' => 'nullable|string|max:255',
        ];
    }

    /**
     * The section's input as columns. An Oracle value is trimmed and a blank one
     * stored as null — the attendance matcher compares the number exactly — and
     * the two pickers become manager_id / supervisor_id. A field the request did
     * not send is left as it is.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function hrFields(array $validated, ?Employee $employee = null): array
    {
        foreach (['oracle_emp_no', 'oracle_department', 'oracle_dept_no'] as $field) {
            if (array_key_exists($field, $validated)) {
                $value = trim((string) $validated[$field]);
                $validated[$field] = $value === '' ? null : $value;
            }
        }

        foreach (['manager', 'supervisor'] as $line) {
            if (array_key_exists($line, $validated)) {
                $validated[$line.'_id'] = $this->pickedColleague($line, $validated[$line], $employee);
                unset($validated[$line]);
            }
        }

        $stored = fn (string $column): ?int => $employee?->{$column} === null ? null : (int) $employee->{$column};
        $managerId = array_key_exists('manager_id', $validated) ? $validated['manager_id'] : $stored('manager_id');
        $supervisorId = array_key_exists('supervisor_id', $validated) ? $validated['supervisor_id'] : $stored('supervisor_id');

        // Onboarding's rule, applied to a pick being made now: a record that
        // already names one person for both still saves when something else changes.
        $picking = $managerId !== $stored('manager_id') || $supervisorId !== $stored('supervisor_id');
        if ($picking && $supervisorId !== null && $supervisorId === $managerId) {
            throw ValidationException::withMessages([
                'supervisor' => 'Manager and supervisor are the same person. Leave the supervisor blank if there is only one.',
            ]);
        }

        return $validated;
    }

    /**
     * The picker's value starts with the employee id. A linked secondary mailbox
     * resolves to its primary record, which is where reporting lines are read
     * (AssistantToolbox::team()).
     *
     * @throws ValidationException
     */
    private function pickedColleague(string $line, ?string $value, ?Employee $employee): ?int
    {
        if (trim((string) $value) === '') {
            return null;
        }

        $picked = preg_match('/^\s*#?(\d+)/', (string) $value, $m) ? Employee::find((int) $m[1]) : null;
        $picked = $picked?->linked_primary_employee_id ? ($picked->linkedPrimary ?? $picked) : $picked;

        $refuse = fn (string $message) => ValidationException::withMessages([$line => $message]);

        if (! $picked) {
            throw $refuse("Pick the {$line} from the list — start typing a name or Oracle number.");
        }

        if ($employee && in_array($picked->id, array_filter([$employee->id, $employee->linked_primary_employee_id]))) {
            throw $refuse("An employee cannot be their own {$line}.");
        }

        // Nobody who has left is offered, but one already on the record stays put,
        // so saving any other field never quietly drops them.
        if ($picked->status === 'terminated' && (int) $picked->id !== (int) $employee?->{$line.'_id'}) {
            throw $refuse("{$picked->name} has left the company — pick someone who still works here.");
        }

        return (int) $picked->id;
    }

    /**
     * What the section offers: colleagues to pick a manager or supervisor from —
     * primary records of people still employed, never the person themselves —
     * and the Oracle departments already on file, each with its number where
     * the name only ever carries one.
     *
     * @return array{employeeOptions: \Illuminate\Database\Eloquent\Collection<int, Employee>, oracleDepartments: array<string, ?string>}
     */
    private function hrFormData(?Employee $employee = null): array
    {
        $employeeOptions = Employee::with('branch:id,name')
            ->where('status', '!=', 'terminated')
            ->whereNull('linked_primary_employee_id')
            ->when($employee, fn ($query) => $query->whereNotIn('id', array_filter([$employee->id, $employee->linked_primary_employee_id])))
            ->orderBy('name')
            ->get(['id', 'name', 'oracle_emp_no', 'branch_id', 'status']);

        $oracleDepartments = Employee::query()
            ->whereNotNull('oracle_department')
            ->where('oracle_department', '!=', '')
            ->distinct()
            ->orderBy('oracle_department')
            ->get(['oracle_department', 'oracle_dept_no'])
            ->groupBy('oracle_department')
            ->map(function ($rows) {
                $numbers = $rows->pluck('oracle_dept_no')->filter()->unique();

                return $numbers->count() === 1 ? (string) $numbers->first() : null;
            })
            ->all();

        return compact('employeeOptions', 'oracleDepartments');
    }

    /**
     * The Oracle number is how fingerprint punches find their owner
     * (EmployeeLinker), so a new one re-decides every automatically matched
     * code — the ones linked under the old number are exactly what
     * retryUnlinked() would never revisit. Queued, never inline: a re-match
     * rebuilds days. HR's manual links are left alone, as always.
     *
     * @return array{0: string, 1: string} [message, flashLevel]
     */
    private function oracleNumberChanged(Employee $employee): array
    {
        $message = '';

        if (BiotimeEmployee::exists()) {
            AttendanceTask::queue('relink', ['source_id' => null, 'all' => true],
                'Re-match attendance codes — an Oracle number changed', Auth::id());
            $message = ' Attendance is re-matching fingerprint codes to Oracle numbers in the background.';
        }

        if ($employee->oracle_emp_no === null) {
            return [$message, 'success'];
        }

        // EMP_NO collides between the SSS-Egypt and SamirGroup series, so a shared
        // number can be right; it is still worth saying out loud.
        $others = Employee::with('branch:id,name')
            ->where('oracle_emp_no', $employee->oracle_emp_no)
            ->whereNotIn('id', array_filter([$employee->id, $employee->linked_primary_employee_id]))
            ->whereNull('linked_primary_employee_id')
            ->orderBy('name')
            ->get(['id', 'name', 'branch_id']);

        if ($others->isEmpty()) {
            return [$message, 'success'];
        }

        $names = $others->map(fn (Employee $other) => $other->name.($other->branch ? " ({$other->branch->name})" : ''))->implode(', ');

        return [
            $message." Oracle number {$employee->oracle_emp_no} is also on {$names}. Attendance tells them apart by the branch a code punches in; a code it cannot place waits on Attendance ▸ Employee Mapping.",
            'warning',
        ];
    }

    // ─────────────────────────────────────────────────────────────
    // Signature roles (extra classic-Outlook signatures) — managed
    // independently of the main profile save, each with its own save/remove.
    // ─────────────────────────────────────────────────────────────

    /** Validation shared by add + edit: label required, plus at least a title OR department. */
    private function signatureRoleRules(): array
    {
        return [
            'label' => 'required|string|max:120',
            'job_title' => 'nullable|string|max:255',
            'department' => 'nullable|string|max:255',
        ];
    }

    /** A role must change something, else it is identical to the default signature. */
    private function signatureRoleHasContent(array $data): bool
    {
        return trim((string) ($data['job_title'] ?? '')) !== ''
            || trim((string) ($data['department'] ?? '')) !== '';
    }

    public function storeSignatureRole(Request $request, Employee $employee)
    {
        $data = $request->validate($this->signatureRoleRules());
        if (! $this->signatureRoleHasContent($data)) {
            return back()->with('error', 'Add a job title and/or department for the role — otherwise it is identical to the default signature.');
        }

        $employee->signatureRoles()->create([
            'label' => trim($data['label']),
            'job_title' => trim((string) ($data['job_title'] ?? '')) ?: null,
            'department' => trim((string) ($data['department'] ?? '')) ?: null,
            'sort_order' => (int) ($employee->signatureRoles()->max('sort_order') + 1),
        ]);

        return back()->with('success', "Signature role \"{$data['label']}\" added.");
    }

    public function updateSignatureRole(Request $request, Employee $employee, EmployeeSignatureRole $role)
    {
        abort_unless($role->employee_id === $employee->id, 404);

        $data = $request->validate($this->signatureRoleRules());
        if (! $this->signatureRoleHasContent($data)) {
            return back()->with('error', 'Add a job title and/or department for the role — otherwise it is identical to the default signature.');
        }

        $role->update([
            'label' => trim($data['label']),
            'job_title' => trim((string) ($data['job_title'] ?? '')) ?: null,
            'department' => trim((string) ($data['department'] ?? '')) ?: null,
        ]);

        return back()->with('success', "Signature role \"{$data['label']}\" saved.");
    }

    public function destroySignatureRole(Employee $employee, EmployeeSignatureRole $role)
    {
        abort_unless($role->employee_id === $employee->id, 404);
        $label = $role->label;
        $role->delete();

        return back()->with('success', "Signature role \"{$label}\" removed.");
    }

    public function assignAsset(Request $request, Employee $employee)
    {
        $validated = $request->validate([
            'asset_id' => 'required|exists:devices,id',
            'assigned_date' => 'required|date',
            'condition' => 'required|in:good,fair,poor',
            'notes' => 'nullable|string|max:500',
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
            return back()->with('error', 'This asset is already assigned to '.($activeAssignment->employee?->name ?? 'someone else').'.');
        }

        // 3. Update status and create record atomically
        \Illuminate\Support\Facades\DB::transaction(function () use ($validated, $employee) {
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
            'condition' => 'required|in:good,fair,poor',
            'notes' => 'nullable|string|max:500',
        ]);

        \Illuminate\Support\Facades\DB::transaction(function () use ($asset, $request) {
            $asset->update([
                'returned_date' => $request->returned_date,
                'condition' => $request->condition,
                'notes' => $request->notes,
            ]);

            Device::where('id', $asset->asset_id)->update(['status' => 'available']);
        });

        return back()->with('success', 'Asset returned successfully.');
    }

    // ─────────────────────────────────────────────────────────────
    // Contact Linking
    // ─────────────────────────────────────────────────────────────

    /**
     * Link an employee to a contact (by contact_id), or re-pick an already
     * linked one. Choosing a contact here is how the employee's extension is
     * set: the picked contact's phone becomes the employee's extension_number.
     */
    public function linkContact(Request $request, Employee $employee)
    {
        $request->validate(['contact_id' => 'required|exists:contacts,id']);

        $contact = \App\Models\Contact::findOrFail($request->contact_id);

        $attributes = ['contact_id' => $contact->id];

        // Picking a contact chooses its extension too — overwrite so a re-pick
        // moves the extension to the newly chosen contact. Only touch it when
        // the contact actually has a phone, so we never blank an existing one.
        if (filled($contact->phone)) {
            $attributes['extension_number'] = $contact->phone;
        }

        $employee->update($attributes);

        $msg = "Linked to contact: {$contact->first_name} {$contact->last_name}";
        $msg .= filled($contact->phone) ? " — extension set to {$contact->phone}." : '.';

        return back()->with('success', $msg);
    }

    /**
     * Set the employee's extension directly, independent of any linked
     * contact. Used when the real extension differs from the contact's phone.
     */
    public function updateExtension(Request $request, Employee $employee)
    {
        $validated = $request->validate([
            'extension_number' => 'nullable|string|max:50',
        ]);

        $extension = trim((string) ($validated['extension_number'] ?? '')) ?: null;
        $employee->update(['extension_number' => $extension]);

        return back()->with('success', $extension
            ? "Extension set to {$extension}."
            : 'Extension cleared.');
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
        $branches = Branch::orderBy('name')->get();

        return view('admin.employees.sync', compact('azureUsers', 'departments', 'branches'));
    }

    public function doSync(Request $request)
    {
        $this->authorize('manage-employees');

        $request->validate([
            'azure_ids' => 'required|array|min:1',
            'azure_ids.*' => 'required|string',
            'branch_id' => 'nullable|exists:branches,id',
        ]);

        $created = 0;
        foreach ($request->azure_ids as $azureId) {
            $identityUser = IdentityUser::where('azure_id', $azureId)->first();
            if (! $identityUser) {
                continue;
            }

            // Skip if already linked by azure_id
            if (Employee::where('azure_id', $azureId)->exists()) {
                continue;
            }

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
                'azure_id' => $azureId,
                'name' => $identityUser->display_name,
                'email' => $email,
                'branch_id' => $branchId,
                'department_id' => $department?->id,
                'manager_id' => $manager?->id,
                'job_title' => $identityUser->job_title,
                'status' => 'active',
                'hired_date' => now()->toDateString(),
            ]);
            $created++;
        }

        return redirect()
            ->route('admin.employees.index')
            ->with('success', "{$created} employee(s) imported from Azure.");
    }
}
