<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\AssetHistory;
use App\Models\Device;
use App\Models\PhoneRequestLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

class DeviceImportController extends Controller
{
    /**
     * Show the upload form + manual add form.
     */
    public function showForm()
    {
        $this->authorize('manage-assets');

        return view('admin.devices.import');
    }

    /**
     * Parse uploaded Excel and show preview.
     * Optimized: uses batch queries, processes in chunks, set_time_limit for large files.
     */
    public function preview(Request $request)
    {
        $this->authorize('manage-assets');
        set_time_limit(120);

        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv|max:10240',
        ]);

        $rows = Excel::toArray([], $request->file('file'));
        $data = $rows[0] ?? [];

        if (count($data) < 2) {
            return back()->with('error', 'The file appears to be empty or has no data rows.');
        }

        $header = array_shift($data);

        // Find MAC, Serial and Model columns by header name (case-insensitive)
        // Also strip non-breaking spaces and other invisible chars
        $macCol    = null;
        $serialCol = null;
        $modelCol  = null;
        foreach ($header as $i => $h) {
            $h = strtolower(trim(preg_replace('/[\x00-\x1F\x7F\xA0]/u', '', $h ?? '')));
            if ($macCol === null && (str_contains($h, 'mac') || str_contains($h, 'address'))) {
                $macCol = $i;
            }
            if ($serialCol === null && str_contains($h, 'serial')) {
                $serialCol = $i;
            }
            if ($modelCol === null && str_contains($h, 'model')) {
                $modelCol = $i;
            }
        }

        if ($macCol === null) {
            return back()->with('error', 'Could not find a MAC address column in the header row. Make sure the header contains "MAC".');
        }

        // First pass: extract and normalize all MACs
        $parsedRows = [];
        $allMacs = [];
        foreach ($data as $row) {
            $rawMac = trim($row[$macCol] ?? '');
            $serial = $serialCol !== null ? trim($row[$serialCol] ?? '') : '';
            $model  = $modelCol !== null ? trim($row[$modelCol] ?? '') : '';
            $mac = strtolower(preg_replace('/[^a-fA-F0-9]/', '', $rawMac));
            if (!$mac || strlen($mac) < 12) continue;
            $mac = substr($mac, 0, 12); // ensure exactly 12 hex chars
            $parsedRows[] = ['mac' => $mac, 'serial' => $serial, 'model' => $model];
            $allMacs[] = $mac;
        }

        if (empty($allMacs)) {
            return back()->with('error', 'No valid MAC addresses found in the file.');
        }

        // Batch lookup: all devices and phone logs in 2 queries
        $devicesByMac = Device::whereIn('mac_address', $allMacs)
            ->get()
            ->keyBy('mac_address');

        $phoneLogsByMac = PhoneRequestLog::whereIn('mac', $allMacs)
            ->select('mac', 'model', DB::raw('MAX(created_at) as last_at'))
            ->groupBy('mac', 'model')
            ->get()
            ->keyBy('mac');

        $preview = [];
        foreach ($parsedRows as $parsed) {
            $mac    = $parsed['mac'];
            $serial = $parsed['serial'];
            $model  = $parsed['model'];
            $existingDevice = $devicesByMac[$mac] ?? null;
            $phoneLog       = $phoneLogsByMac[$mac] ?? null;

            // Model priority: Excel file > phone logs
            $finalModel = $model ?: ($phoneLog?->model ?? '');

            $preview[] = [
                'mac'             => $mac,
                'mac_display'     => strtoupper(implode(':', str_split($mac, 2))),
                'serial'          => $serial,
                'model'           => $finalModel,
                'existing_device' => $existingDevice ? [
                    'id'             => $existingDevice->id,
                    'name'           => $existingDevice->name,
                    'current_serial' => $existingDevice->serial_number,
                    'current_model'  => $existingDevice->model,
                ] : null,
                'action'          => $existingDevice ? 'update' : 'create',
                'model_from_log'  => $phoneLog?->model,
            ];
        }

        if (empty($preview)) {
            return back()->with('error', 'No valid MAC addresses found in the file.');
        }

        session(['device_import_preview' => $preview]);

        return view('admin.devices.import-preview', compact('preview'));
    }

    /**
     * Apply confirmed import.
     */
    public function apply(Request $request)
    {
        $this->authorize('manage-assets');
        set_time_limit(120);

        $request->validate([
            'selected'   => 'required|array|min:1',
            'selected.*' => 'integer',
        ]);

        $preview = session('device_import_preview', []);

        if (empty($preview)) {
            return redirect()->route('admin.devices.import')
                ->with('error', 'Session expired. Please re-upload the file.');
        }

        $selectedIndices = $request->selected;
        $updated = 0;
        $created = 0;
        $results = [];

        DB::transaction(function () use ($preview, $selectedIndices, &$updated, &$created, &$results) {
            foreach ($selectedIndices as $idx) {
                $row = $preview[$idx] ?? null;
                if (!$row) {
                    continue;
                }

                if ($row['action'] === 'update' && $row['existing_device']) {
                    $device = Device::find($row['existing_device']['id']);
                    if ($device) {
                        $updates = [];
                        $notes = [];

                        if ($row['serial'] && $row['serial'] !== $device->serial_number) {
                            $updates['serial_number'] = $row['serial'];
                            $notes[] = "Serial: {$row['serial']}";
                        }
                        if ($row['model'] && $row['model'] !== $device->model) {
                            $updates['model'] = $row['model'];
                            $notes[] = "Model: {$row['model']}";
                        }

                        if (!empty($updates)) {
                            $device->update($updates);
                            AssetHistory::record($device, 'note_added',
                                "Updated via import: " . implode(', ', $notes));
                        }
                        $results[] = [
                            'mac_display' => $row['mac_display'],
                            'serial'      => $row['serial'],
                            'model'       => $row['model'],
                            'action'      => 'updated',
                            'device_id'   => $device->id,
                            'device_name' => $device->name,
                        ];
                        $updated++;
                    }
                } else {
                    $name = $row['model']
                        ?: ($row['model_from_log'] ?? ('Phone ' . strtoupper(substr($row['mac'], -4))));

                    $device = Device::create([
                        'type'          => 'other',
                        'name'          => $name,
                        'model'         => $row['model'] ?: $row['model_from_log'],
                        'mac_address'   => $row['mac'],
                        'serial_number' => $row['serial'],
                        'status'        => 'active',
                        'source'        => 'manual',
                    ]);

                    AssetHistory::record($device, 'created',
                        "Created via MAC/Serial import");
                    $results[] = [
                        'mac_display' => $row['mac_display'],
                        'serial'      => $row['serial'],
                        'model'       => $row['model'] ?: $row['model_from_log'],
                        'action'      => 'created',
                        'device_id'   => $device->id,
                        'device_name' => $device->name,
                    ];
                    $created++;
                }
            }
        });

        session()->forget('device_import_preview');

        ActivityLog::log("Device import: {$updated} updated, {$created} created");

        return view('admin.devices.import-results', [
            'results' => $results,
            'updated' => $updated,
            'created' => $created,
            'source'  => 'Excel Import',
        ]);
    }

    /**
     * Manual add — single device.
     */
    public function manualStore(Request $request)
    {
        $this->authorize('manage-assets');

        $request->validate([
            'mac_address'   => 'required|string|min:12',
            'serial_number' => 'nullable|string|max:100',
            'model'         => 'nullable|string|max:100',
        ]);

        $mac = strtolower(preg_replace('/[^a-fA-F0-9]/', '', $request->mac_address));
        if (strlen($mac) < 12) {
            return back()->with('error', 'Invalid MAC address.');
        }
        $mac = substr($mac, 0, 12);

        $macDisplay = strtoupper(implode(':', str_split($mac, 2)));
        $existing = Device::where('mac_address', $mac)->first();
        $results = [];

        if ($existing) {
            $updates = [];
            $notes = [];
            if ($request->serial_number && $request->serial_number !== $existing->serial_number) {
                $updates['serial_number'] = $request->serial_number;
                $notes[] = "Serial: {$request->serial_number}";
            }
            if ($request->model && $request->model !== $existing->model) {
                $updates['model'] = $request->model;
                $notes[] = "Model: {$request->model}";
            }
            if (!empty($updates)) {
                $existing->update($updates);
                AssetHistory::record($existing, 'note_added', "Updated manually: " . implode(', ', $notes));
            }
            $results[] = [
                'mac_display' => $macDisplay,
                'serial'      => $request->serial_number,
                'model'       => $request->model,
                'action'      => 'updated',
                'device_id'   => $existing->id,
                'device_name' => $existing->name,
            ];
            return view('admin.devices.import-results', [
                'results' => $results, 'updated' => 1, 'created' => 0, 'source' => 'Manual Add',
            ]);
        }

        $name = $request->model ?: ('Phone ' . strtoupper(substr($mac, -4)));
        $device = Device::create([
            'type'          => 'other',
            'name'          => $name,
            'model'         => $request->model,
            'mac_address'   => $mac,
            'serial_number' => $request->serial_number,
            'status'        => 'active',
            'source'        => 'manual',
        ]);
        AssetHistory::record($device, 'created', "Created manually");
        ActivityLog::log("Device created manually: MAC {$mac}");
        $results[] = [
            'mac_display' => $macDisplay,
            'serial'      => $request->serial_number,
            'model'       => $request->model,
            'action'      => 'created',
            'device_id'   => $device->id,
            'device_name' => $device->name,
        ];

        return view('admin.devices.import-results', [
            'results' => $results, 'updated' => 0, 'created' => 1, 'source' => 'Manual Add',
        ]);
    }

    /**
     * Batch add — multiple devices from pasted text.
     */
    public function batchStore(Request $request)
    {
        $this->authorize('manage-assets');
        set_time_limit(120);

        $request->validate([
            'batch_data' => 'required|string',
        ]);

        $lines = preg_split('/\r?\n/', trim($request->batch_data));
        $created = 0;
        $updated = 0;
        $skipped = 0;

        // First pass: parse all lines and collect MACs
        $parsedLines = [];
        $allMacs = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if (!$line) continue;

            // Split by tab, comma, semicolon, or multiple spaces
            $parts = preg_split('/[\t,;]+|\s{2,}/', $line);
            $rawMac = trim($parts[0] ?? '');
            $serial = trim($parts[1] ?? '');
            $model  = trim($parts[2] ?? '');

            $mac = strtolower(preg_replace('/[^a-fA-F0-9]/', '', $rawMac));
            if (strlen($mac) < 12) {
                $skipped++;
                continue;
            }
            $mac = substr($mac, 0, 12);
            $parsedLines[] = compact('mac', 'serial', 'model');
            $allMacs[] = $mac;
        }

        if (empty($allMacs)) {
            return back()->with('error', 'No valid MAC addresses found in the pasted data.');
        }

        // Batch lookup existing devices
        $existingDevices = Device::whereIn('mac_address', $allMacs)->get()->keyBy('mac_address');

        $results = [];

        DB::transaction(function () use ($parsedLines, $existingDevices, &$created, &$updated, &$results) {
            foreach ($parsedLines as $row) {
                $macDisplay = strtoupper(implode(':', str_split($row['mac'], 2)));
                $existing = $existingDevices[$row['mac']] ?? null;

                if ($existing) {
                    $updates = [];
                    $notes = [];
                    if ($row['serial'] && $row['serial'] !== $existing->serial_number) {
                        $updates['serial_number'] = $row['serial'];
                        $notes[] = "Serial: {$row['serial']}";
                    }
                    if ($row['model'] && $row['model'] !== $existing->model) {
                        $updates['model'] = $row['model'];
                        $notes[] = "Model: {$row['model']}";
                    }
                    if (!empty($updates)) {
                        $existing->update($updates);
                        AssetHistory::record($existing, 'note_added', "Updated via batch: " . implode(', ', $notes));
                    }
                    $results[] = [
                        'mac_display' => $macDisplay,
                        'serial'      => $row['serial'],
                        'model'       => $row['model'],
                        'action'      => 'updated',
                        'device_id'   => $existing->id,
                        'device_name' => $existing->name,
                    ];
                    $updated++;
                } else {
                    $name = $row['model'] ?: ('Phone ' . strtoupper(substr($row['mac'], -4)));
                    $device = Device::create([
                        'type'          => 'other',
                        'name'          => $name,
                        'model'         => $row['model'] ?: null,
                        'mac_address'   => $row['mac'],
                        'serial_number' => $row['serial'] ?: null,
                        'status'        => 'active',
                        'source'        => 'manual',
                    ]);
                    AssetHistory::record($device, 'created', "Created via batch add");
                    $results[] = [
                        'mac_display' => $macDisplay,
                        'serial'      => $row['serial'],
                        'model'       => $row['model'],
                        'action'      => 'created',
                        'device_id'   => $device->id,
                        'device_name' => $device->name,
                    ];
                    $created++;
                }
            }
        });

        ActivityLog::log("Device batch add: {$updated} updated, {$created} created, {$skipped} skipped");

        return view('admin.devices.import-results', [
            'results' => $results,
            'updated' => $updated,
            'created' => $created,
            'skipped' => $skipped,
            'source'  => 'Batch Add',
        ]);
    }
}
