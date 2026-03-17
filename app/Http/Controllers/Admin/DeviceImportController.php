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
     * Show the upload form.
     */
    public function showForm()
    {
        $this->authorize('manage-assets');

        return view('admin.devices.import');
    }

    /**
     * Parse uploaded Excel and show preview.
     */
    public function preview(Request $request)
    {
        $this->authorize('manage-assets');

        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv|max:5120',
        ]);

        $rows = Excel::toArray([], $request->file('file'));
        $data = $rows[0] ?? [];

        if (count($data) < 2) {
            return back()->with('error', 'The file appears to be empty or has no data rows.');
        }

        $header = array_shift($data);

        // Find MAC and Serial columns by header name (case-insensitive)
        $macCol    = null;
        $serialCol = null;
        foreach ($header as $i => $h) {
            $h = strtolower(trim($h ?? ''));
            if ($macCol === null && str_contains($h, 'mac')) {
                $macCol = $i;
            }
            if ($serialCol === null && str_contains($h, 'serial')) {
                $serialCol = $i;
            }
        }

        if ($macCol === null || $serialCol === null) {
            return back()->with('error', 'Could not find MAC and Serial columns in the header row. Make sure the header contains "MAC" and "Serial".');
        }

        $preview = [];
        foreach ($data as $row) {
            $rawMac = trim($row[$macCol] ?? '');
            $serial = trim($row[$serialCol] ?? '');

            // Normalize MAC: strip all non-hex characters, lowercase
            $mac = strtolower(preg_replace('/[^a-fA-F0-9]/', '', $rawMac));

            if (!$mac) {
                continue;
            }

            $existingDevice = Device::where('mac_address', $mac)->first();
            $phoneLog       = PhoneRequestLog::where('mac', $mac)->latest()->first();

            $preview[] = [
                'mac'             => $mac,
                'mac_display'     => strtoupper(implode(':', str_split($mac, 2))),
                'serial'          => $serial,
                'existing_device' => $existingDevice ? [
                    'id'             => $existingDevice->id,
                    'name'           => $existingDevice->name,
                    'current_serial' => $existingDevice->serial_number,
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

        DB::transaction(function () use ($preview, $selectedIndices, &$updated, &$created) {
            foreach ($selectedIndices as $idx) {
                $row = $preview[$idx] ?? null;
                if (!$row) {
                    continue;
                }

                if ($row['action'] === 'update' && $row['existing_device']) {
                    $device = Device::find($row['existing_device']['id']);
                    if ($device) {
                        $device->update(['serial_number' => $row['serial']]);
                        AssetHistory::record($device, 'note_added',
                            "Serial number updated via import: {$row['serial']}");
                        $updated++;
                    }
                } else {
                    $name = $row['model_from_log']
                        ?? ('Phone ' . strtoupper(substr($row['mac'], -4)));

                    $device = Device::create([
                        'type'          => 'other',
                        'name'          => $name,
                        'model'         => $row['model_from_log'],
                        'mac_address'   => $row['mac'],
                        'serial_number' => $row['serial'],
                        'status'        => 'active',
                        'source'        => 'manual',
                    ]);

                    AssetHistory::record($device, 'created',
                        "Created via MAC/Serial import");
                    $created++;
                }
            }
        });

        session()->forget('device_import_preview');

        ActivityLog::log("Device import: {$updated} updated, {$created} created");

        return redirect()->route('admin.devices.import')
            ->with('success', "Import complete: {$updated} device(s) updated, {$created} device(s) created.");
    }
}
