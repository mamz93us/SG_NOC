<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Accessory;
use App\Models\AccessoryAssignment;
use App\Models\Employee;
use App\Models\Device;
use App\Models\Supplier;
use App\Models\ActivityLog;
use Illuminate\Http\Request;

class AccessoryController extends Controller
{
    public function index(Request $request)
    {
        $query = Accessory::with(['supplier', 'activeAssignments.employee', 'activeAssignments.device'])
            ->withCount(['assignments', 'activeAssignments']);

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('name', 'like', "%{$s}%")
                  ->orWhere('category', 'like', "%{$s}%");
            });
        }

        if ($request->filled('category')) {
            $query->where('category', $request->category);
        }

        $accessories = $query->orderBy('name')->paginate(25)->withQueryString();
        $suppliers   = Supplier::orderBy('name')->get();
        $categories  = Accessory::CATEGORIES;

        return view('admin.itam.accessories.index', compact('accessories', 'suppliers', 'categories'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'               => 'required|string|max:255',
            'category'           => 'nullable|string|max:50',
            'quantity_total'     => 'required|integer|min:0',
            'quantity_available' => 'required|integer|min:0',
            'supplier_id'        => 'nullable|exists:suppliers,id',
            'purchase_cost'      => 'nullable|numeric|min:0',
            'notes'              => 'nullable|string',
        ]);

        $accessory = Accessory::create($data);
        ActivityLog::log('Created accessory', $accessory, $data);

        return back()->with('success', "Accessory '{$accessory->name}' created.");
    }

    public function update(Request $request, Accessory $accessory)
    {
        $data = $request->validate([
            'name'               => 'required|string|max:255',
            'category'           => 'nullable|string|max:50',
            'quantity_total'     => 'required|integer|min:0',
            'quantity_available' => 'required|integer|min:0',
            'supplier_id'        => 'nullable|exists:suppliers,id',
            'purchase_cost'      => 'nullable|numeric|min:0',
            'notes'              => 'nullable|string',
        ]);

        $accessory->update($data);
        ActivityLog::log('Updated accessory', $accessory, $data);

        return back()->with('success', "Accessory '{$accessory->name}' updated.");
    }

    public function destroy(Accessory $accessory)
    {
        if ($accessory->activeAssignments()->count() > 0) {
            return back()->with('error', 'Cannot delete accessory with active assignments.');
        }

        $name = $accessory->name;
        $snapshot = $accessory->toArray();
        $accessory->delete();

        ActivityLog::log('Deleted accessory', $accessory, $snapshot);

        return back()->with('success', "Accessory '{$name}' deleted.");
    }

    public function assign(Request $request, Accessory $accessory)
    {
        if (!$accessory->isAvailable()) {
            return back()->with('error', 'No units available for this accessory.');
        }

        $data = $request->validate([
            'assign_to'     => 'required|in:employee,device',
            'assignable_id' => 'required|integer',
            'assigned_date' => 'required|date',
            'notes'         => 'nullable|string',
        ]);

        // 1. Prevent duplicate submissions within 15 seconds (debounce)
        $exists = AccessoryAssignment::where('accessory_id', $accessory->id)
            ->where(function($q) use ($data) {
                if ($data['assign_to'] === 'employee') {
                    $q->where('employee_id', $data['assignable_id']);
                } else {
                    $q->where('device_id', $data['assignable_id']);
                }
            })
            ->whereNull('returned_date')
            ->where('created_at', '>=', now()->subSeconds(15))
            ->exists();

        if ($exists) {
            return back()->with('warning', 'A recent assignment for this item was already processed. Please refresh if you need to add another.');
        }

        // 2. Wrap in transaction for atomicity
        \Illuminate\Support\Facades\DB::transaction(function() use ($accessory, $data) {
            // Validate the assignable actually exists (inside transaction for safety)
            if ($data['assign_to'] === 'employee') {
                if (!\App\Models\Employee::where('id', $data['assignable_id'])->exists()) {
                    throw new \RuntimeException('Employee not found.');
                }
            } else {
                if (!\App\Models\Device::where('id', $data['assignable_id'])->exists()) {
                    throw new \RuntimeException('Device not found.');
                }
            }

            AccessoryAssignment::create([
                'accessory_id'  => $accessory->id,
                'employee_id'   => $data['assign_to'] === 'employee' ? $data['assignable_id'] : null,
                'device_id'     => $data['assign_to'] === 'device'   ? $data['assignable_id'] : null,
                'assigned_date' => $data['assigned_date'],
                'notes'         => $data['notes'] ?? null,
            ]);

            // Decrement available quantity
            $accessory->decrement('quantity_available');
        });

        ActivityLog::log("Assigned accessory '{$accessory->name}'");

        return back()->with('success', "Accessory '{$accessory->name}' assigned.");
    }

    public function returnItem(Request $request, Accessory $accessory, AccessoryAssignment $assignment)
    {
        $assignment->update(['returned_date' => now()->toDateString()]);
        $accessory->increment('quantity_available');

        ActivityLog::log("Returned accessory '{$accessory->name}'");

        return back()->with('success', "Accessory '{$accessory->name}' returned.");
    }
}
