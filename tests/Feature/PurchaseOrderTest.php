<?php

use App\Models\Accessory;
use App\Models\AzureDevice;
use App\Models\Branch;
use App\Models\Device;
use App\Models\License;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Services\DeviceLinkingService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->branch = Branch::create(['id' => 1, 'name' => 'HQ']);
});

it('creates a PurchaseOrder with device, accessory, and license lines and materializes each catalog row', function () {
    $supplier = Supplier::create(['name' => 'Acme IT']);
    $linker = new DeviceLinkingService;

    $po = PurchaseOrder::create([
        'po_number' => 'PO:00001',
        'po_date' => now(),
        'supplier_id' => $supplier->id,
        'currency' => 'SAR',
        'tax' => 0,
        'status' => 'submitted',
    ]);

    // Device line
    $deviceLine = $po->items()->create([
        'line_type' => 'device',
        'name' => 'Laptop Lenovo',
        'manufacturer' => 'Lenovo',
        'model' => 'T14',
        'serial_number' => '210US45',
        'branch_id' => $this->branch->id,
        'quantity' => 1,
        'unit_cost' => 4500,
    ]);

    $accessoryLine = $po->items()->create([
        'line_type' => 'accessory',
        'name' => 'USB-C Hub',
        'category' => 'dock',
        'branch_id' => $this->branch->id,
        'quantity' => 5,
        'unit_cost' => 120,
    ]);

    $licenseLine = $po->items()->create([
        'line_type' => 'license',
        'name' => 'M365 E3',
        'manufacturer' => 'Microsoft',
        'license_type' => 'subscription',
        'seats' => 10,
        'expiry_date' => now()->addYear(),
        'quantity' => 1,
        'unit_cost' => 990,
    ]);

    // Now exercise the materialization helpers through the controller. Use
    // the controller's logic directly so we hit the exact path used in prod.
    $controller = new \App\Http\Controllers\Admin\PurchaseOrderController;
    $reflection = new ReflectionClass($controller);

    $materializeDevice = $reflection->getMethod('materializeDevice');
    $materializeDevice->setAccessible(true);
    $materializeDevice->invoke($controller, $po, $deviceLine, $linker);

    $materializeAccessory = $reflection->getMethod('materializeAccessory');
    $materializeAccessory->setAccessible(true);
    $materializeAccessory->invoke($controller, $po, $accessoryLine);

    $materializeLicense = $reflection->getMethod('materializeLicense');
    $materializeLicense->setAccessible(true);
    $materializeLicense->invoke($controller, $po, $licenseLine);

    // Assert one row created in each catalog with the PO tag
    expect(Device::where('purchase_order_id', $po->id)->count())->toBe(1);
    expect(Accessory::where('purchase_order_id', $po->id)->count())->toBe(1);
    expect(License::where('purchase_order_id', $po->id)->count())->toBe(1);

    $device = Device::where('purchase_order_id', $po->id)->first();
    expect($device->serial_number)->toBe('210US45');
    expect($device->name)->toContain('210US45');
    expect($device->name)->toContain('PO:00001');
    expect($device->branch_id)->toBe($this->branch->id);

    $accessory = Accessory::where('purchase_order_id', $po->id)->first();
    expect($accessory->branch_id)->toBe($this->branch->id);
    expect($accessory->quantity_total)->toBe(5);
    expect($accessory->quantity_available)->toBe(5);

    $license = License::where('purchase_order_id', $po->id)->first();
    expect($license->seats)->toBe(10);
});

it('formats the Device name as "Name Manufacturer Model Serial PO:Number"', function () {
    $po = PurchaseOrder::create([
        'po_number' => 'PO:00007',
        'po_date' => now(),
        'currency' => 'SAR',
        'status' => 'submitted',
    ]);

    $line = $po->items()->create([
        'line_type' => 'device',
        'name' => 'Laptop',
        'manufacturer' => 'Lenovo',
        'model' => 'T14',
        'serial_number' => '210US45',
        'quantity' => 1,
        'unit_cost' => 0,
    ]);

    expect($line->buildDeviceName($po->po_number))
        ->toBe('Laptop Lenovo T14 210US45 PO:PO:00007');
});

it('links a newly-created PO device to an existing AzureDevice with the same serial', function () {
    $po = PurchaseOrder::create([
        'po_number' => 'PO:00001',
        'po_date' => now(),
        'currency' => 'SAR',
        'status' => 'submitted',
    ]);

    // Azure already saw this serial (synced before the PO was entered)
    $azDev = AzureDevice::create([
        'azure_device_id' => 'az-aaa-1',
        'display_name' => 'LAPTOP-1',
        'serial_number' => '210US45',
        'last_sync_at' => now(),
        'link_status' => 'unlinked',
    ]);

    $line = $po->items()->create([
        'line_type' => 'device',
        'name' => 'Laptop',
        'manufacturer' => 'Lenovo',
        'model' => 'T14',
        'serial_number' => '210US45',
        'branch_id' => $this->branch->id,
        'quantity' => 1,
        'unit_cost' => 0,
    ]);

    $controller = new \App\Http\Controllers\Admin\PurchaseOrderController;
    $r = new ReflectionClass($controller);
    $m = $r->getMethod('materializeDevice');
    $m->setAccessible(true);
    $m->invoke($controller, $po, $line, new DeviceLinkingService);

    $device = Device::where('serial_number', '210US45')->first();
    $azDev->refresh();

    expect($azDev->device_id)->toBe($device->id);
    expect($azDev->link_status)->toBe('linked'); // PO-tagged → auto-promoted
});

it('recalculates totals from line items', function () {
    $po = PurchaseOrder::create([
        'po_number' => 'PO:99',
        'po_date' => now(),
        'currency' => 'SAR',
        'tax' => 50,
        'status' => 'submitted',
    ]);

    $po->items()->create([
        'line_type' => 'accessory', 'name' => 'X', 'quantity' => 2, 'unit_cost' => 100,
    ]);
    $po->items()->create([
        'line_type' => 'accessory', 'name' => 'Y', 'quantity' => 1, 'unit_cost' => 250,
    ]);

    $po->recalcTotals();

    expect((float) $po->subtotal)->toBe(450.0);
    expect((float) $po->total)->toBe(500.0);
});
