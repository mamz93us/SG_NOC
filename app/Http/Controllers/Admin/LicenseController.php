<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\License;
use App\Models\LicenseAssignment;
use App\Models\Device;
use App\Models\Employee;
use App\Models\AssetHistory;
use App\Models\ActivityLog;
use Illuminate\Http\Request;

class LicenseController extends Controller
{
    public function index(Request $request)
    {
        $query = License::with(['assignments.assignable'])->withCount('assignments');

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('license_name', 'like', "%{$s}%")
                  ->orWhere('vendor', 'like', "%{$s}%");
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

        $licenses     = $query->orderBy('license_name')->paginate(25)->withQueryString();
        $licenseTypes = License::TYPES;

        return view('admin.itam.licenses.index', compact('licenses', 'licenseTypes'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'license_name'  => 'required|string|max:255',
            'vendor'        => 'nullable|string|max:255',
            'license_key'   => 'nullable|string',
            'license_type'  => 'required|in:' . implode(',', License::TYPES),
            'purchase_date' => 'nullable|date',
            'expiry_date'   => 'nullable|date|after_or_equal:purchase_date',
            'cost'          => 'nullable|numeric|min:0',
            'seats'         => 'required|integer|min:1',
            'notes'         => 'nullable|string',
        ]);

        $license = License::create($data);
        ActivityLog::log('Created license', $license, ['license_name' => $license->license_name]);

        return back()->with('success', "License '{$license->license_name}' created.");
    }

    public function update(Request $request, License $license)
    {
        $data = $request->validate([
            'license_name'  => 'required|string|max:255',
            'vendor'        => 'nullable|string|max:255',
            'license_key'   => 'nullable|string',
            'license_type'  => 'required|in:' . implode(',', License::TYPES),
            'purchase_date' => 'nullable|date',
            'expiry_date'   => 'nullable|date',
            'cost'          => 'nullable|numeric|min:0',
            'seats'         => 'required|integer|min:1',
            'notes'         => 'nullable|string',
        ]);

        $license->update($data);
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
            'assignable_id'   => 'required|integer',
            'assigned_date'   => 'required|date',
            'notes'           => 'nullable|string',
        ]);

        if ($license->availableSeats() <= 0) {
            return back()->with('error', 'No available seats for this license.');
        }

        $assignableClass = $data['assignable_type'] === 'device' ? Device::class : Employee::class;
        $assignable      = $assignableClass::findOrFail($data['assignable_id']);

        $assignment = LicenseAssignment::create([
            'license_id'      => $license->id,
            'assignable_type' => $assignableClass,
            'assignable_id'   => $data['assignable_id'],
            'assigned_date'   => $data['assigned_date'],
            'notes'           => $data['notes'] ?? null,
        ]);

        // Log asset history if assigned to a device
        if ($data['assignable_type'] === 'device') {
            AssetHistory::record($assignable, 'license_assigned', "License '{$license->license_name}' assigned");
        }

        ActivityLog::log("Assigned license '{$license->license_name}' to {$data['assignable_type']} #{$data['assignable_id']}");

        return back()->with('success', 'License assigned successfully.');
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
