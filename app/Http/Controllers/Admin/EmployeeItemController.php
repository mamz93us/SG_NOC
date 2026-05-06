<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Employee;
use App\Models\EmployeeItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EmployeeItemController extends Controller
{
    public function store(Request $request, Employee $employee)
    {
        $this->authorize('manage-employees');

        $validated = $request->validate([
            'item_name'     => 'required|string|max:100',
            'item_type'     => 'required|in:laptop,desktop,phone,headset,tablet,keyboard,mouse,other',
            'serial_number' => 'nullable|string|max:100',
            'model'         => 'nullable|string|max:100',
            'condition'     => 'required|in:good,fair,poor',
            'assigned_date' => 'required|date',
            'notes'         => 'nullable|string|max:1000',
        ]);

        $item = $employee->items()->create($validated);

        ActivityLog::create([
            'model_type' => EmployeeItem::class,
            'model_id'   => $item->id,
            'action'     => 'employee_item_created',
            'changes'    => ['employee_id' => $employee->id] + $item->toArray(),
            'user_id'    => Auth::id(),
        ]);

        return back()->with('success', "Item \"{$validated['item_name']}\" added to {$employee->name}.");
    }

    public function returnItem(Request $request, Employee $employee, EmployeeItem $item)
    {
        $this->authorize('manage-employees');

        if ($item->employee_id !== $employee->id) {
            abort(404);
        }

        $request->validate([
            'returned_date' => 'required|date',
        ]);

        $item->update([
            'returned_date' => $request->returned_date,
        ]);

        ActivityLog::create([
            'model_type' => EmployeeItem::class,
            'model_id'   => $item->id,
            'action'     => 'employee_item_returned',
            'changes'    => ['employee_id' => $employee->id, 'item_name' => $item->item_name, 'returned_date' => $request->returned_date],
            'user_id'    => Auth::id(),
        ]);

        return back()->with('success', "Item \"{$item->item_name}\" marked as returned.");
    }

    public function destroy(Employee $employee, EmployeeItem $item)
    {
        $this->authorize('manage-employees');

        if ($item->employee_id !== $employee->id) {
            abort(404);
        }

        $name = $item->item_name;
        $snapshot = $item->toArray();
        $item->delete();

        ActivityLog::create([
            'model_type' => EmployeeItem::class,
            'model_id'   => $item->id,
            'action'     => 'employee_item_deleted',
            'changes'    => $snapshot,
            'user_id'    => Auth::id(),
        ]);

        return back()->with('success', "Item \"{$name}\" removed.");
    }
}
