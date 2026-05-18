<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Accessory;
use App\Models\ActivityLog;
use App\Models\AssetHistory;
use App\Models\Branch;
use App\Models\Device;
use App\Models\License;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Services\DeviceLinkingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class PurchaseOrderController extends Controller
{
    public function index(Request $request)
    {
        $query = PurchaseOrder::with('supplier')->withCount('items');

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where('po_number', 'like', "%{$s}%");
        }
        if ($request->filled('supplier_id')) {
            $query->where('supplier_id', $request->supplier_id);
        }
        if ($request->filled('from')) {
            $query->whereDate('po_date', '>=', $request->from);
        }
        if ($request->filled('to')) {
            $query->whereDate('po_date', '<=', $request->to);
        }

        $orders = $query->orderByDesc('po_date')->orderByDesc('id')->paginate(25)->withQueryString();
        $suppliers = Supplier::orderBy('name')->get(['id', 'name']);

        return view('admin.itam.purchase-orders.index', compact('orders', 'suppliers'));
    }

    public function create()
    {
        $suppliers = Supplier::orderBy('name')->get(['id', 'name']);
        $branches = Branch::orderBy('name')->get(['id', 'name']);

        return view('admin.itam.purchase-orders.create', compact('suppliers', 'branches'));
    }

    public function store(Request $request, DeviceLinkingService $linker)
    {
        $data = $request->validate([
            'po_number' => 'required|string|max:32|unique:purchase_orders,po_number',
            'po_date' => 'required|date',
            'supplier_id' => 'nullable|exists:suppliers,id',
            'currency' => 'required|string|size:3',
            'tax' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',

            'items' => 'required|array|min:1',
            'items.*.line_type' => 'required|in:device,accessory,license',
            'items.*.name' => 'required|string|max:255',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.unit_cost' => 'required|numeric|min:0',
            'items.*.branch_id' => 'nullable|exists:branches,id',
            'items.*.manufacturer' => 'nullable|string|max:255',
            'items.*.model' => 'nullable|string|max:255',
            'items.*.serial_number' => 'nullable|string|max:255',
            'items.*.device_type' => 'nullable|string|max:50',
            'items.*.category' => 'nullable|string|max:50',
            'items.*.license_type' => 'nullable|in:subscription,perpetual,oem,freeware',
            'items.*.seats' => 'nullable|integer|min:1',
            'items.*.expiry_date' => 'nullable|date',
            'items.*.notes' => 'nullable|string',
        ]);

        // Per-type required fields
        foreach ($data['items'] as $idx => $item) {
            if ($item['line_type'] === 'device' && empty($item['serial_number'])) {
                return back()->withInput()
                    ->withErrors(["items.{$idx}.serial_number" => 'Serial number is required for device lines.']);
            }
            if ($item['line_type'] === 'license') {
                if (empty($item['seats'])) {
                    return back()->withInput()
                        ->withErrors(["items.{$idx}.seats" => 'Seats is required for license lines.']);
                }
                if (empty($item['license_type'])) {
                    return back()->withInput()
                        ->withErrors(["items.{$idx}.license_type" => 'License type is required for license lines.']);
                }
            }
        }

        $po = DB::transaction(function () use ($data, $linker) {
            $po = PurchaseOrder::create([
                'po_number' => $data['po_number'],
                'po_date' => $data['po_date'],
                'supplier_id' => $data['supplier_id'] ?? null,
                'currency' => strtoupper($data['currency']),
                'tax' => $data['tax'] ?? 0,
                'notes' => $data['notes'] ?? null,
                'status' => 'submitted',
                'created_by' => Auth::id(),
            ]);

            foreach ($data['items'] as $itemData) {
                $line = $po->items()->create($itemData);

                // Materialize the underlying asset based on line_type
                if ($line->line_type === 'device') {
                    $this->materializeDevice($po, $line, $linker);
                } elseif ($line->line_type === 'accessory') {
                    $this->materializeAccessory($po, $line);
                } elseif ($line->line_type === 'license') {
                    $this->materializeLicense($po, $line);
                }
            }

            $po->recalcTotals();

            return $po;
        });

        ActivityLog::log("Created Purchase Order {$po->po_number} with {$po->items()->count()} line(s).");

        return redirect()->route('admin.itam.purchase-orders.show', $po)
            ->with('success', "Purchase Order {$po->po_number} created.");
    }

    public function show(PurchaseOrder $purchaseOrder)
    {
        $purchaseOrder->load('items.branch', 'supplier');

        return view('admin.itam.purchase-orders.show', ['po' => $purchaseOrder]);
    }

    public function edit(PurchaseOrder $purchaseOrder)
    {
        $purchaseOrder->load('items.branch');
        $suppliers = Supplier::orderBy('name')->get(['id', 'name']);
        $branches = Branch::orderBy('name')->get(['id', 'name']);

        return view('admin.itam.purchase-orders.edit', [
            'po' => $purchaseOrder,
            'suppliers' => $suppliers,
            'branches' => $branches,
        ]);
    }

    public function update(Request $request, PurchaseOrder $purchaseOrder)
    {
        $data = $request->validate([
            'po_number' => 'required|string|max:32|unique:purchase_orders,po_number,'.$purchaseOrder->id,
            'po_date' => 'required|date',
            'supplier_id' => 'nullable|exists:suppliers,id',
            'currency' => 'required|string|size:3',
            'tax' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
            'status' => 'required|in:'.implode(',', PurchaseOrder::STATUSES),
        ]);

        $purchaseOrder->update($data);
        $purchaseOrder->recalcTotals();

        ActivityLog::log("Updated Purchase Order {$purchaseOrder->po_number}.");

        return redirect()->route('admin.itam.purchase-orders.show', $purchaseOrder)
            ->with('success', 'Purchase Order updated.');
    }

    public function destroy(PurchaseOrder $purchaseOrder)
    {
        $num = $purchaseOrder->po_number;
        // Detach but keep underlying assets (they may already be assigned to people).
        Device::where('purchase_order_id', $purchaseOrder->id)->update(['purchase_order_id' => null]);
        Accessory::where('purchase_order_id', $purchaseOrder->id)->update(['purchase_order_id' => null]);
        License::where('purchase_order_id', $purchaseOrder->id)->update(['purchase_order_id' => null]);
        $purchaseOrder->delete();

        ActivityLog::log("Deleted Purchase Order {$num} (assets preserved, PO link removed).");

        return redirect()->route('admin.itam.purchase-orders.index')
            ->with('success', "Purchase Order {$num} deleted.");
    }

    public function print(PurchaseOrder $purchaseOrder)
    {
        $purchaseOrder->load('items.branch', 'supplier');

        return view('admin.itam.purchase-orders.print', ['po' => $purchaseOrder]);
    }

    // ─────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────

    private function materializeDevice(PurchaseOrder $po, PurchaseOrderItem $line, DeviceLinkingService $linker): void
    {
        $device = Device::create([
            'type' => $line->device_type ?: 'laptop',
            'name' => $line->buildDeviceName($po->po_number),
            'manufacturer' => $line->manufacturer,
            'model' => $line->model,
            'serial_number' => $line->serial_number,
            'branch_id' => $line->branch_id,
            'status' => 'available',
            'condition' => 'new',
            'purchase_date' => $po->po_date,
            'purchase_cost' => $line->unit_cost,
            'currency' => $po->currency,
            'supplier_id' => $po->supplier_id,
            'purchase_order_id' => $po->id,
            'notes' => $line->notes,
        ]);

        $line->update(['asset_id' => $device->id]);

        AssetHistory::record(
            $device,
            'created',
            "Created from Purchase Order {$po->po_number}"
        );

        // Auto-link to AzureDevice if one already exists with this serial.
        $linker->linkBySerial($device);
    }

    private function materializeAccessory(PurchaseOrder $po, PurchaseOrderItem $line): void
    {
        $accessory = Accessory::create([
            'name' => $line->name,
            'category' => $line->category,
            'quantity_total' => $line->quantity,
            'quantity_available' => $line->quantity,
            'supplier_id' => $po->supplier_id,
            'purchase_order_id' => $po->id,
            'branch_id' => $line->branch_id,
            'purchase_cost' => $line->unit_cost,
            'currency' => $po->currency,
            'notes' => $line->notes ? "PO:{$po->po_number} — ".$line->notes : "From PO:{$po->po_number}",
        ]);

        $line->update(['asset_id' => $accessory->id]);
    }

    private function materializeLicense(PurchaseOrder $po, PurchaseOrderItem $line): void
    {
        $license = License::create([
            'license_name' => $line->name." (PO:{$po->po_number})",
            'vendor' => $line->manufacturer,
            'license_type' => $line->license_type ?: 'subscription',
            'purchase_date' => $po->po_date,
            'expiry_date' => $line->expiry_date,
            'cost' => $line->unit_cost,
            'currency' => $po->currency,
            'seats' => $line->seats ?: 1,
            'supplier_id' => $po->supplier_id,
            'purchase_order_id' => $po->id,
            'notes' => $line->notes,
        ]);

        $line->update(['asset_id' => $license->id]);
    }
}
