<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use App\Models\ActivityLog;
use Illuminate\Http\Request;

class SupplierController extends Controller
{
    public function index(Request $request)
    {
        $query = Supplier::withCount('devices')->with('accessories');

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('name', 'like', "%{$s}%")
                  ->orWhere('email', 'like', "%{$s}%")
                  ->orWhere('contact_person', 'like', "%{$s}%");
            });
        }

        $suppliers = $query->orderBy('name')->paginate(25)->withQueryString();

        return view('admin.itam.suppliers.index', compact('suppliers'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'           => 'required|string|max:255',
            'contact_person' => 'nullable|string|max:255',
            'email'          => 'nullable|email|max:255',
            'phone'          => 'nullable|string|max:30',
            'address'        => 'nullable|string',
            'notes'          => 'nullable|string',
        ]);

        $supplier = Supplier::create($data);
        ActivityLog::log('Created supplier', $supplier, $data);

        if ($request->expectsJson()) {
            return response()->json(['id' => $supplier->id, 'name' => $supplier->name]);
        }

        return back()->with('success', "Supplier '{$supplier->name}' created.");
    }

    public function update(Request $request, Supplier $supplier)
    {
        $data = $request->validate([
            'name'           => 'required|string|max:255',
            'contact_person' => 'nullable|string|max:255',
            'email'          => 'nullable|email|max:255',
            'phone'          => 'nullable|string|max:30',
            'address'        => 'nullable|string',
            'notes'          => 'nullable|string',
        ]);

        $supplier->update($data);
        ActivityLog::log('Updated supplier', $supplier, $data);

        return back()->with('success', "Supplier '{$supplier->name}' updated.");
    }

    public function destroy(Supplier $supplier)
    {
        if ($supplier->devices()->count() > 0 || $supplier->accessories()->count() > 0) {
            return back()->with('error', 'Cannot delete supplier with linked assets or accessories.');
        }

        $name = $supplier->name;
        $supplier->delete();
        ActivityLog::log('Deleted supplier', 'Supplier', 'deleted', $supplier->id ?? 0);

        return back()->with('success', "Supplier '{$name}' deleted.");
    }
}
