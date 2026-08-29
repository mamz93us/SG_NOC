<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\AssetHistory;
use App\Models\Device;
use App\Models\Employee;
use App\Models\IdentityLicense;
use App\Models\IdentityUser;
use App\Models\License;
use App\Models\LicenseAssignment;
use App\Models\Supplier;
use App\Services\Identity\GraphService;
use App\Support\Currency;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LicenseController extends Controller
{
    public function index(Request $request)
    {
        $query = License::with(['supplier', 'assignments.assignable', 'identityLicense'])->withCount('assignments');

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('license_name', 'like', "%{$s}%")
                    ->orWhere('vendor', 'like', "%{$s}%")
                    ->orWhereHas('supplier', fn ($sq) => $sq->where('name', 'like', "%{$s}%"));
            });
        }

        if ($request->filled('type')) {
            $query->where('license_type', $request->type);
        }

        if ($request->filled('status')) {
            if ($request->status === 'expired') {
                $query->whereNotNull('expiry_date')->where('expiry_date', '<', now());
            } elseif ($request->status === 'expiring') {
                $query->whereNotNull('expiry_date')
                    ->where('expiry_date', '>=', now())
                    ->where('expiry_date', '<=', now()->addDays(30));
            } elseif ($request->status === 'active') {
                $query->where(function ($q) {
                    $q->whereNull('expiry_date')->orWhere('expiry_date', '>', now());
                });
            }
        }

        $licenses = $query->orderBy('license_name')->paginate(25)->withQueryString();
        $licenseTypes = License::TYPES;
        $suppliers = Supplier::orderBy('name')->get();
        $identityLicenses = IdentityLicense::orderBy('sku_part_number')->get(['id', 'sku_part_number', 'display_name', 'license_id']);

        return view('admin.itam.licenses.index', compact('licenses', 'licenseTypes', 'suppliers', 'identityLicenses'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'license_name' => 'required|string|max:255',
            'supplier_id' => 'nullable|exists:suppliers,id',
            'license_key' => 'nullable|string',
            'license_type' => 'required|in:'.implode(',', License::TYPES),
            'purchase_date' => 'nullable|date',
            'expiry_date' => 'nullable|date|after_or_equal:purchase_date',
            'cost' => 'nullable|numeric|min:0',
            'currency' => 'required|in:'.implode(',', Currency::CODES),
            'seats' => 'required|integer|min:1',
            'notes' => 'nullable|string',
            'identity_license_id' => 'nullable|exists:identity_licenses,id',
        ]);

        $identityLicenseId = $data['identity_license_id'] ?? null;
        unset($data['identity_license_id']);

        $license = License::create($data);

        if ($identityLicenseId) {
            IdentityLicense::where('id', $identityLicenseId)->update(['license_id' => $license->id]);
        }

        ActivityLog::log('Created license', $license, ['license_name' => $license->license_name]);

        return back()->with('success', "License '{$license->license_name}' created.");
    }

    public function update(Request $request, License $license)
    {
        $data = $request->validate([
            'license_name' => 'required|string|max:255',
            'supplier_id' => 'nullable|exists:suppliers,id',
            'license_key' => 'nullable|string',
            'license_type' => 'required|in:'.implode(',', License::TYPES),
            'purchase_date' => 'nullable|date',
            'expiry_date' => 'nullable|date',
            'cost' => 'nullable|numeric|min:0',
            'currency' => 'required|in:'.implode(',', Currency::CODES),
            'seats' => 'required|integer|min:1',
            'notes' => 'nullable|string',
            'identity_license_id' => 'nullable|exists:identity_licenses,id',
        ]);

        // The edit modal does not pre-fill license_key (it's never sent to the
        // browser). Treat a blank submission as "no change" so existing keys
        // are preserved on every edit.
        if (empty($data['license_key'])) {
            unset($data['license_key']);
        }

        $identityLicenseId = $data['identity_license_id'] ?? null;
        unset($data['identity_license_id']);

        $license->update($data);

        // Detach any prior SKU link, then attach the requested one (if any).
        IdentityLicense::where('license_id', $license->id)->update(['license_id' => null]);
        if ($identityLicenseId) {
            IdentityLicense::where('id', $identityLicenseId)->update(['license_id' => $license->id]);
        }

        ActivityLog::log('Updated license', $license, $data);

        return back()->with('success', "License '{$license->license_name}' updated.");
    }

    public function destroy(License $license)
    {
        $name = $license->license_name;
        $license->assignments()->delete();
        $license->delete();
        ActivityLog::log('Deleted license', 'License', 'deleted', $license->id ?? 0);

        return back()->with('success', "License '{$name}' deleted.");
    }

    public function assign(Request $request, License $license)
    {
        $data = $request->validate([
            'assignable_type' => 'required|in:device,employee',
            'assignable_id' => 'required|integer',
            'assigned_date' => 'required|date',
            'notes' => 'nullable|string',
        ]);

        $assignableClass = $data['assignable_type'] === 'device' ? Device::class : Employee::class;

        // 1. Prevent double-click submissions (debounce)
        $exists = LicenseAssignment::where('license_id', $license->id)
            ->where('assignable_type', $assignableClass)
            ->where('assignable_id', $data['assignable_id'])
            ->where('created_at', '>=', now()->subSeconds(15))
            ->exists();

        if ($exists) {
            return back()->with('warning', 'A recent assignment for this license was already processed.');
        }

        if ($license->availableSeats() <= 0) {
            return back()->with('error', 'No available seats for this license.');
        }

        $assignable = $assignableClass::findOrFail($data['assignable_id']);

        // When this License is linked to a Microsoft/Azure SKU, an employee
        // assignment must actually grant the license in Azure — a local-only
        // row here would just be bookkeeping that lies about what the tenant
        // has. Devices never hold M365 licenses, so they stay local-only.
        $identityLicense = $license->identityLicense;
        if ($data['assignable_type'] === 'employee' && $identityLicense) {
            if (! $assignable->azure_id) {
                return back()->with('error',
                    "{$assignable->name} has no linked Azure account — run identity sync first, then retry.");
            }

            try {
                (new GraphService)->assignLicense($assignable->azure_id, $identityLicense->sku_id);
            } catch (\Throwable $e) {
                return back()->with('error', "Graph license grant failed: {$e->getMessage()}");
            }
        }

        DB::transaction(function () use ($license, $assignable, $assignableClass, $data) {
            $assignment = LicenseAssignment::create([
                'license_id' => $license->id,
                'assignable_type' => $assignableClass,
                'assignable_id' => $data['assignable_id'],
                'assigned_date' => $data['assigned_date'],
                'notes' => $data['notes'] ?? null,
            ]);

            // Log asset history if assigned to a device
            if ($data['assignable_type'] === 'device') {
                AssetHistory::record($assignable, 'license_assigned', "License '{$license->license_name}' assigned");
            }
        });

        ActivityLog::log("Assigned license '{$license->license_name}' to {$data['assignable_type']} #{$data['assignable_id']}");

        return back()->with('success', 'License assigned successfully.');
    }

    /**
     * Active employees eligible for a bulk Graph auto-assign of this License's
     * linked Azure SKU: have an Azure account, and hold neither a local
     * LicenseAssignment for it nor the SKU itself per the last identity sync.
     * Capped by whichever seat count is tighter — ITAM's purchased seats or
     * Azure's live available count — so the preview never promises more than
     * can actually be granted.
     */
    public function autoAssignEligible(License $license)
    {
        $identityLicense = $license->identityLicense;
        if (! $identityLicense) {
            return response()->json(['error' => 'This license is not linked to a Microsoft/Azure SKU.'], 422);
        }

        $skuId = $identityLicense->sku_id;
        $itamAvailable = $license->availableSeats();
        $azureAvailable = max(0, (int) $identityLicense->available);
        $capacity = min($itamAvailable, $azureAvailable);

        $alreadyLocal = LicenseAssignment::where('license_id', $license->id)
            ->where('assignable_type', Employee::class)
            ->pluck('assignable_id');

        $candidates = Employee::query()
            ->where('status', 'active')
            ->whereNotNull('azure_id')
            ->whereNotIn('id', $alreadyLocal)
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'azure_id']);

        // Cross-check against the last identity:sync so someone Azure already
        // shows as licensed (e.g. assigned outside NOC, or via group-based
        // licensing) is not offered a redundant assign.
        $alreadyHoldingAzureIds = IdentityUser::whereIn('azure_id', $candidates->pluck('azure_id'))
            ->get(['azure_id', 'assigned_licenses'])
            ->filter(fn ($iu) => in_array($skuId, (array) $iu->assigned_licenses, true))
            ->pluck('azure_id');

        $eligible = $candidates->reject(fn ($e) => $alreadyHoldingAzureIds->contains($e->azure_id))->values();

        return response()->json([
            'sku_part_number' => $identityLicense->sku_part_number,
            'itam_available' => $itamAvailable,
            'azure_available' => $azureAvailable,
            'capacity' => $capacity,
            'eligible_count' => $eligible->count(),
            'employees' => $eligible->map(fn ($e) => ['id' => $e->id, 'name' => $e->name, 'email' => $e->email])->values(),
        ]);
    }

    /**
     * Execute a bulk Graph auto-assign for the employee IDs an admin selected
     * from the eligible-list preview above. Every step is re-verified here
     * (not trusted from the preview) since Azure/local state can move between
     * the two requests, and the loop stops the moment either seat count is
     * exhausted rather than over-committing past what was actually purchased
     * or licensed.
     */
    public function autoAssign(Request $request, License $license)
    {
        $data = $request->validate([
            'employee_ids' => 'required|array|min:1',
            'employee_ids.*' => 'integer|exists:employees,id',
        ]);

        $identityLicense = $license->identityLicense;
        if (! $identityLicense) {
            return back()->with('error', 'This license is not linked to a Microsoft/Azure SKU.');
        }
        $skuId = $identityLicense->sku_id;

        $graph = new GraphService;
        $assigned = [];
        $skipped = [];
        $errors = [];

        foreach ($data['employee_ids'] as $employeeId) {
            $license->refresh();
            $identityLicense->refresh();

            if ($license->availableSeats() <= 0) {
                $skipped[] = 'Stopped — no ITAM-purchased seats left.';
                break;
            }
            if ((int) $identityLicense->available <= 0) {
                $skipped[] = 'Stopped — Azure reports no available seats left for this SKU.';
                break;
            }

            $employee = Employee::find($employeeId);
            if (! $employee || $employee->status !== 'active' || ! $employee->azure_id) {
                $skipped[] = "#{$employeeId}: no longer eligible (inactive or no Azure account).";

                continue;
            }

            $alreadyAssigned = LicenseAssignment::where('license_id', $license->id)
                ->where('assignable_type', Employee::class)
                ->where('assignable_id', $employee->id)
                ->exists();
            if ($alreadyAssigned) {
                $skipped[] = "{$employee->name}: already assigned.";

                continue;
            }

            try {
                $graph->assignLicense($employee->azure_id, $skuId);

                DB::transaction(function () use ($license, $employee) {
                    LicenseAssignment::create([
                        'license_id' => $license->id,
                        'assignable_type' => Employee::class,
                        'assignable_id' => $employee->id,
                        'assigned_date' => now()->toDateString(),
                        'notes' => 'Auto-assigned via Graph API',
                    ]);
                });

                $assigned[] = $employee->name;
            } catch (\Throwable $e) {
                $errors[] = "{$employee->name}: {$e->getMessage()}";
            }
        }

        ActivityLog::log(
            "Auto-assigned license '{$license->license_name}' to ".count($assigned).' employee(s) via Graph'
        );

        $summary = count($assigned).' assigned'.($assigned ? ' ('.implode(', ', $assigned).')' : '').'.'
            .($skipped ? ' '.count($skipped).' skipped: '.implode(' / ', $skipped) : '')
            .($errors ? ' '.count($errors).' failed: '.implode(' / ', $errors) : '');

        return back()->with($errors ? 'error' : 'success', $summary);
    }

    public function unassign(License $license, LicenseAssignment $assignment)
    {
        // Log history if it was a device
        if ($assignment->assignable_type === Device::class || $assignment->assignable_type === 'App\Models\Device') {
            $device = Device::find($assignment->assignable_id);
            if ($device) {
                AssetHistory::record($device, 'license_removed', "License '{$license->license_name}' removed");
            }
        }

        $assignment->delete();
        ActivityLog::log("Unassigned license '{$license->license_name}'");

        return back()->with('success', 'License assignment removed.');
    }
}
