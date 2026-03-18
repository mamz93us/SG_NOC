<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\AssetHistory;
use App\Models\Device;
use App\Models\PhoneRequestLog;
use App\Services\AssetCodeService;
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

        // Find columns by header name (case-insensitive, strip invisible chars)
        $macCol    = null;
        $serialCol = null;
        $modelCol  = null;
        $ipCol     = null;
        foreach ($header as $i => $h) {
            $h = strtolower(trim(preg_replace('/[\x00-\x1F\x7F\xA0]/u', '', $h ?? '')));
            if ($macCol === null && str_contains($h, 'mac')) {
                $macCol = $i;
            }
            if ($serialCol === null && str_contains($h, 'serial')) {
                $serialCol = $i;
            }
            if ($modelCol === null && str_contains($h, 'model')) {
                $modelCol = $i;
            }
            if ($ipCol === null && str_contains($h, 'ip')) {
                $ipCol = $i;
            }
        }

        if ($macCol === null && $ipCol === null) {
            return back()->with('error', 'Could not find a MAC or IP column in the header row. Make sure the header contains "MAC" or "IP".');
        }

        // First pass: extract and normalize
        $parsedRows = [];
        $allMacs = [];
        $allIps  = [];
        foreach ($data as $row) {
            $rawMac = $macCol !== null ? trim($row[$macCol] ?? '') : '';
            $serial = $serialCol !== null ? trim($row[$serialCol] ?? '') : '';
            $model  = $modelCol !== null ? trim($row[$modelCol] ?? '') : '';
            $ip     = $ipCol !== null ? trim($row[$ipCol] ?? '') : '';

            $mac = strtolower(preg_replace('/[^a-fA-F0-9]/', '', $rawMac));
            if ($mac && strlen($mac) >= 12) {
                $mac = substr($mac, 0, 12);
            } else {
                $mac = '';
            }

            // Validate IP
            if ($ip && !filter_var($ip, FILTER_VALIDATE_IP)) {
                $ip = '';
            }

            // Skip rows with neither MAC nor IP
            if (!$mac && !$ip) continue;

            $parsedRows[] = ['mac' => $mac, 'serial' => $serial, 'model' => $model, 'ip' => $ip];
            if ($mac) $allMacs[] = $mac;
            if ($ip) $allIps[] = $ip;
        }

        if (empty($parsedRows)) {
            return back()->with('error', 'No valid MAC addresses or IP addresses found in the file.');
        }

        // Batch lookup: devices by MAC and by IP
        $devicesByMac = !empty($allMacs)
            ? Device::whereIn('mac_address', $allMacs)->get()->keyBy('mac_address')
            : collect();

        $devicesByIp = !empty($allIps)
            ? Device::whereIn('ip_address', $allIps)->get()->keyBy('ip_address')
            : collect();

        $phoneLogsByMac = !empty($allMacs)
            ? PhoneRequestLog::whereIn('mac', $allMacs)
                ->select('mac', 'model', DB::raw('MAX(created_at) as last_at'))
                ->groupBy('mac', 'model')
                ->get()
                ->keyBy('mac')
            : collect();

        $preview = [];
        foreach ($parsedRows as $parsed) {
            $mac    = $parsed['mac'];
            $serial = $parsed['serial'];
            $model  = $parsed['model'];
            $ip     = $parsed['ip'];

            // Find existing device: MAC first, then IP fallback
            $existingDevice = null;
            if ($mac) {
                $existingDevice = $devicesByMac[$mac] ?? null;
            }
            if (!$existingDevice && $ip) {
                $existingDevice = $devicesByIp[$ip] ?? null;
            }

            $phoneLog = $mac ? ($phoneLogsByMac[$mac] ?? null) : null;
            $finalModel = $model ?: ($phoneLog?->model ?? '');

            $preview[] = [
                'mac'             => $mac,
                'mac_display'     => $mac ? strtoupper(implode(':', str_split($mac, 2))) : '',
                'ip'              => $ip,
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
            return back()->with('error', 'No valid data found in the file.');
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

        $assetCodeSvc = new AssetCodeService();

        DB::transaction(function () use ($preview, $selectedIndices, &$updated, &$created, &$results, $assetCodeSvc) {
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
                        // Update MAC if provided and device doesn't have one
                        if ($row['mac'] && !$device->mac_address) {
                            $updates['mac_address'] = $row['mac'];
                            $notes[] = "MAC: {$row['mac']}";
                        }
                        // Update IP if provided and device doesn't have one
                        if (($row['ip'] ?? '') && !$device->ip_address) {
                            $updates['ip_address'] = $row['ip'];
                            $notes[] = "IP: {$row['ip']}";
                        }
                        // Fix type/manufacturer for previously imported phones
                        if ($device->type !== 'phone') {
                            $updates['type'] = 'phone';
                            $notes[] = "Type: phone";
                        }
                        if (!$device->manufacturer || $device->manufacturer !== 'Grandstream') {
                            $updates['manufacturer'] = 'Grandstream';
                            $notes[] = "Manufacturer: Grandstream";
                        }
                        // Auto-generate asset code if missing
                        if (!$device->asset_code) {
                            $updates['asset_code'] = $assetCodeSvc->generate($updates['type'] ?? $device->type);
                            $notes[] = "Asset code: {$updates['asset_code']}";
                        }

                        if (!empty($updates)) {
                            $device->update($updates);
                            AssetHistory::record($device, 'note_added',
                                "Updated via import: " . implode(', ', $notes));
                        }
                        $results[] = [
                            'mac_display' => $row['mac_display'],
                            'ip'          => $row['ip'] ?? '',
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
                        ?: ($row['model_from_log'] ?? ('Phone ' . strtoupper(substr($row['mac'] ?: 'NOMC', -4))));

                    $assetCode = $assetCodeSvc->generate('phone');

                    $device = Device::create([
                        'type'          => 'phone',
                        'name'          => $name,
                        'manufacturer'  => 'Grandstream',
                        'model'         => $row['model'] ?: $row['model_from_log'],
                        'mac_address'   => $row['mac'] ?: null,
                        'ip_address'    => $row['ip'] ?? null,
                        'serial_number' => $row['serial'],
                        'asset_code'    => $assetCode,
                        'status'        => 'active',
                        'source'        => 'manual',
                    ]);

                    AssetHistory::record($device, 'created',
                        "Created via MAC/Serial import");
                    $results[] = [
                        'mac_display' => $row['mac_display'],
                        'ip'          => $row['ip'] ?? '',
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
            'mac_address'   => 'nullable|string',
            'ip_address'    => 'nullable|ip',
            'serial_number' => 'nullable|string|max:100',
            'model'         => 'nullable|string|max:100',
        ]);

        // At least MAC or IP required
        if (!$request->mac_address && !$request->ip_address) {
            return back()->with('error', 'Please provide at least a MAC address or IP address.');
        }

        $mac = '';
        $macDisplay = '';
        if ($request->mac_address) {
            $mac = strtolower(preg_replace('/[^a-fA-F0-9]/', '', $request->mac_address));
            if (strlen($mac) >= 12) {
                $mac = substr($mac, 0, 12);
                $macDisplay = strtoupper(implode(':', str_split($mac, 2)));
            } else {
                $mac = '';
            }
        }

        $ip = $request->ip_address ?: '';

        // Find existing: MAC first, then IP
        $existing = null;
        if ($mac) {
            $existing = Device::where('mac_address', $mac)->first();
        }
        if (!$existing && $ip) {
            $existing = Device::where('ip_address', $ip)->first();
        }

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
            if ($mac && !$existing->mac_address) {
                $updates['mac_address'] = $mac;
                $notes[] = "MAC: {$mac}";
            }
            if ($ip && !$existing->ip_address) {
                $updates['ip_address'] = $ip;
                $notes[] = "IP: {$ip}";
            }
            if ($existing->type !== 'phone') {
                $updates['type'] = 'phone';
                $notes[] = "Type: phone";
            }
            if (!$existing->manufacturer || $existing->manufacturer !== 'Grandstream') {
                $updates['manufacturer'] = 'Grandstream';
                $notes[] = "Manufacturer: Grandstream";
            }
            if (!$existing->asset_code) {
                $assetCodeSvc = new AssetCodeService();
                $updates['asset_code'] = $assetCodeSvc->generate($updates['type'] ?? $existing->type);
                $notes[] = "Asset code: {$updates['asset_code']}";
            }
            if (!empty($updates)) {
                $existing->update($updates);
                AssetHistory::record($existing, 'note_added', "Updated manually: " . implode(', ', $notes));
            }
            $results[] = [
                'mac_display' => $macDisplay ?: strtoupper(implode(':', str_split($existing->mac_address ?? '', 2))),
                'ip'          => $ip ?: $existing->ip_address,
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

        $assetCodeSvc = new AssetCodeService();
        $name = $request->model ?: ('Phone ' . ($mac ? strtoupper(substr($mac, -4)) : $ip));
        $device = Device::create([
            'type'          => 'phone',
            'name'          => $name,
            'manufacturer'  => 'Grandstream',
            'model'         => $request->model,
            'mac_address'   => $mac ?: null,
            'ip_address'    => $ip ?: null,
            'serial_number' => $request->serial_number,
            'asset_code'    => $assetCodeSvc->generate('phone'),
            'status'        => 'active',
            'source'        => 'manual',
        ]);
        AssetHistory::record($device, 'created', "Created manually");
        ActivityLog::log("Device created manually: " . ($mac ? "MAC {$mac}" : "IP {$ip}"));
        $results[] = [
            'mac_display' => $macDisplay,
            'ip'          => $ip,
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

        // First pass: parse all lines and collect MACs + IPs
        $parsedLines = [];
        $allMacs = [];
        $allIps  = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if (!$line) continue;

            // Split by tab, comma, semicolon, or multiple spaces
            $parts = preg_split('/[\t,;]+|\s{2,}/', $line);
            $rawMac = trim($parts[0] ?? '');
            $serial = trim($parts[1] ?? '');
            $model  = trim($parts[2] ?? '');
            $ip     = trim($parts[3] ?? '');

            $mac = strtolower(preg_replace('/[^a-fA-F0-9]/', '', $rawMac));
            if ($mac && strlen($mac) >= 12) {
                $mac = substr($mac, 0, 12);
            } else {
                $mac = '';
            }

            // Validate IP
            if ($ip && !filter_var($ip, FILTER_VALIDATE_IP)) {
                $ip = '';
            }

            // Check if column 1 is actually an IP (no MAC column)
            if (!$mac && !$ip && filter_var($rawMac, FILTER_VALIDATE_IP)) {
                $ip = $rawMac;
                // Shift columns: serial=parts[1], model=parts[2]
            }

            if (!$mac && !$ip) {
                $skipped++;
                continue;
            }

            $parsedLines[] = compact('mac', 'serial', 'model', 'ip');
            if ($mac) $allMacs[] = $mac;
            if ($ip) $allIps[] = $ip;
        }

        if (empty($parsedLines)) {
            return back()->with('error', 'No valid MAC addresses or IP addresses found in the pasted data.');
        }

        // Batch lookup existing devices by MAC and IP
        $existingByMac = !empty($allMacs)
            ? Device::whereIn('mac_address', $allMacs)->get()->keyBy('mac_address')
            : collect();

        $existingByIp = !empty($allIps)
            ? Device::whereIn('ip_address', $allIps)->get()->keyBy('ip_address')
            : collect();

        $results = [];

        $assetCodeSvc = new AssetCodeService();

        DB::transaction(function () use ($parsedLines, $existingByMac, $existingByIp, &$created, &$updated, &$results, $assetCodeSvc) {
            foreach ($parsedLines as $row) {
                $macDisplay = $row['mac'] ? strtoupper(implode(':', str_split($row['mac'], 2))) : '';

                // Find existing: MAC first, then IP
                $existing = null;
                if ($row['mac']) {
                    $existing = $existingByMac[$row['mac']] ?? null;
                }
                if (!$existing && $row['ip']) {
                    $existing = $existingByIp[$row['ip']] ?? null;
                }

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
                    if ($row['mac'] && !$existing->mac_address) {
                        $updates['mac_address'] = $row['mac'];
                        $notes[] = "MAC: {$row['mac']}";
                    }
                    if ($row['ip'] && !$existing->ip_address) {
                        $updates['ip_address'] = $row['ip'];
                        $notes[] = "IP: {$row['ip']}";
                    }
                    if ($existing->type !== 'phone') {
                        $updates['type'] = 'phone';
                        $notes[] = "Type: phone";
                    }
                    if (!$existing->manufacturer || $existing->manufacturer !== 'Grandstream') {
                        $updates['manufacturer'] = 'Grandstream';
                        $notes[] = "Manufacturer: Grandstream";
                    }
                    if (!$existing->asset_code) {
                        $updates['asset_code'] = $assetCodeSvc->generate($updates['type'] ?? $existing->type);
                        $notes[] = "Asset code: {$updates['asset_code']}";
                    }
                    if (!empty($updates)) {
                        $existing->update($updates);
                        AssetHistory::record($existing, 'note_added', "Updated via batch: " . implode(', ', $notes));
                    }
                    $results[] = [
                        'mac_display' => $macDisplay,
                        'ip'          => $row['ip'],
                        'serial'      => $row['serial'],
                        'model'       => $row['model'],
                        'action'      => 'updated',
                        'device_id'   => $existing->id,
                        'device_name' => $existing->name,
                    ];
                    $updated++;
                } else {
                    $name = $row['model'] ?: ('Phone ' . ($row['mac'] ? strtoupper(substr($row['mac'], -4)) : $row['ip']));
                    $device = Device::create([
                        'type'          => 'phone',
                        'name'          => $name,
                        'manufacturer'  => 'Grandstream',
                        'model'         => $row['model'] ?: null,
                        'mac_address'   => $row['mac'] ?: null,
                        'ip_address'    => $row['ip'] ?: null,
                        'serial_number' => $row['serial'] ?: null,
                        'asset_code'    => $assetCodeSvc->generate('phone'),
                        'status'        => 'active',
                        'source'        => 'manual',
                    ]);
                    AssetHistory::record($device, 'created', "Created via batch add");
                    $results[] = [
                        'mac_display' => $macDisplay,
                        'ip'          => $row['ip'],
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
