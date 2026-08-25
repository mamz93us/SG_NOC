<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AccessPoint;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Services\AccessPointImporter;
use App\Services\PingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AccessPointController extends Controller
{
    public function index(Request $request)
    {
        $query = AccessPoint::with('branch', 'device');

        if ($request->filled('vendor')) {
            $query->where('vendor', $request->vendor);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }
        if ($request->filled('q')) {
            $q = $request->q;
            $query->where(function ($w) use ($q) {
                $w->where('name', 'like', "%{$q}%")
                    ->orWhere('serial_number', 'like', "%{$q}%")
                    ->orWhere('mac_address', 'like', "%{$q}%")
                    ->orWhere('ip_address', 'like', "%{$q}%")
                    ->orWhere('site', 'like', "%{$q}%");
            });
        }

        $accessPoints = $query->orderBy('site')->orderBy('name')->get();

        return view('admin.network.access-points.index', [
            'accessPoints' => $accessPoints,
            'branches' => Branch::orderBy('name')->get(),
            'total' => AccessPoint::count(),
            'up' => AccessPoint::where('status', 'up')->count(),
            'down' => AccessPoint::where('status', 'down')->count(),
            'unknown' => AccessPoint::where('status', 'unknown')->count(),
            'vendors' => AccessPoint::query()->distinct()->pluck('vendor')->filter()->values(),
        ]);
    }

    /**
     * Add an access point by hand and register it as an asset in one step.
     *
     * Most APs arrive through the Sophos Central CSV, but anything the
     * controller does not manage — a TP-Link/Omada unit, a spare, an AP fitted
     * before it was enrolled — otherwise never reaches the asset register at
     * all. This goes through the same AccessPointImporter::ensureAsset() the
     * import uses, so a manually added AP gets an asset code from the same
     * sequence and cannot drift from an imported one.
     */
    public function store(Request $request, AccessPointImporter $importer)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'vendor' => 'required|string|max:50',
            'model' => 'nullable|string|max:100',
            'serial_number' => 'nullable|string|max:100',
            'mac_address' => ['nullable', 'string', 'max:20', 'regex:/^([0-9a-fA-F]{2}[:-]?){5}[0-9a-fA-F]{2}$/'],
            'ip_address' => 'nullable|ip',
            'site' => 'nullable|string|max:100',
            'branch_id' => 'nullable|exists:branches,id',
            'firmware' => 'nullable|string|max:50',
            'monitor_enabled' => 'nullable|boolean',
        ], [
            'mac_address.regex' => 'Enter the MAC as 12 hex digits, with or without separators.',
        ]);

        $serial = trim((string) ($validated['serial_number'] ?? '')) ?: null;
        // Match the CSV importer's format so manual and imported rows compare
        // against each other: lower-case, colon-separated.
        $mac = $this->normaliseMac($validated['mac_address'] ?? null);

        if ($clash = $this->findClash($serial, $mac)) {
            return back()->withInput()->with(
                'error',
                "That serial/MAC already belongs to \"{$clash->name}\". Edit that access point instead of adding a second one."
            );
        }

        $ap = AccessPoint::create([
            'name' => $validated['name'],
            'vendor' => $validated['vendor'],
            'controller' => 'manual',
            'model' => $validated['model'] ?? null,
            'serial_number' => $serial,
            'mac_address' => $mac,
            'ip_address' => $validated['ip_address'] ?? null,
            'site' => $validated['site'] ?? null,
            'branch_id' => $validated['branch_id'] ?? null,
            'firmware' => $validated['firmware'] ?? null,
            'monitor_enabled' => (bool) ($validated['monitor_enabled'] ?? true),
            'status' => 'unknown',
        ]);

        // Asset registration is best-effort, exactly as in the CSV import: an AP
        // the NOC can already monitor is worth keeping even if the asset side
        // fails, and the row can be linked afterwards from the table.
        try {
            $device = $importer->ensureAsset($ap);
            $note = $device->wasRecentlyCreated
                ? "Asset {$device->asset_code} created."
                : "Linked to existing asset {$device->asset_code}.";
        } catch (\Throwable $e) {
            Log::error('Access point asset registration failed: '.$e->getMessage(), ['ap' => $ap->id, 'exception' => $e]);
            $note = 'Asset registration failed ('.$e->getMessage().') — use the asset button on its row to retry.';
        }

        // The access point is already saved and monitored; an audit-write failure
        // must not turn that into a 500 for the operator.
        try {
            ActivityLog::create([
                'model_type' => 'AccessPoint',
                'model_id' => $ap->id,
                'action' => 'created',
                'changes' => ['name' => $ap->name, 'serial' => $serial, 'mac' => $mac],
                'user_id' => $request->user()?->id,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Access point audit log failed: '.$e->getMessage());
        }

        return back()->with('success', "Added {$ap->name}. {$note}");
    }

    /**
     * Register an already-listed access point as an asset. Covers rows that
     * predate the asset link, and any row whose registration failed at creation.
     */
    public function createAsset(AccessPoint $accessPoint, AccessPointImporter $importer)
    {
        try {
            $device = $importer->ensureAsset($accessPoint);
        } catch (\Throwable $e) {
            return back()->with('error', "Could not register {$accessPoint->name}: ".$e->getMessage());
        }

        return back()->with('success', $device->wasRecentlyCreated
            ? "{$accessPoint->name} registered as asset {$device->asset_code}."
            : "{$accessPoint->name} linked to existing asset {$device->asset_code}.");
    }

    public function import(Request $request, AccessPointImporter $importer)
    {
        $request->validate([
            'csv' => 'required|file|mimes:csv,txt|max:5120',
        ]);

        try {
            $result = $importer->importSophosCsv($request->file('csv')->getRealPath());
        } catch (\Throwable $e) {
            return back()->with('error', 'Import failed: '.$e->getMessage());
        }

        ActivityLog::create([
            'model_type' => 'AccessPoint',
            'model_id' => 0,
            'action' => 'imported',
            'changes' => $result,
            'user_id' => $request->user()?->id,
        ]);

        $msg = "Import done — {$result['created']} created, {$result['updated']} updated, "
            ."{$result['assets']} assets linked, {$result['skipped']} skipped.";

        if (! empty($result['errors'])) {
            return back()->with('error', $msg.' Errors: '.implode(' | ', array_slice($result['errors'], 0, 5)));
        }

        return back()->with('success', $msg);
    }

    public function pingAll()
    {
        try {
            \Illuminate\Support\Facades\Artisan::call('access-points:ping');
            $out = trim(\Illuminate\Support\Facades\Artisan::output());
            // Surface the command's summary line (last non-empty line)
            $lines = array_filter(array_map('trim', preg_split('/\r?\n/', $out)));
            $summary = end($lines) ?: 'Done.';

            return back()->with('success', "Checked all access points — {$summary}");
        } catch (\Throwable $e) {
            return back()->with('error', 'Check-all failed: '.$e->getMessage());
        }
    }

    public function pingNow(AccessPoint $accessPoint, PingService $ping)
    {
        // PingService::ping() takes a non-nullable string, so an AP with no IP
        // used to 500 here. The scheduled sweep has always skipped those
        // (`AccessPoint::monitored()->whereNotNull('ip_address')`); this button
        // now agrees with it instead of blowing up.
        if (! $accessPoint->ip_address) {
            return back()->with('error',
                "{$accessPoint->name} has no IP address, so there is nothing to ping. Edit it and add one.");
        }

        $result = $ping->ping($accessPoint->ip_address, 2);
        $alive = (bool) ($result['success'] ?? false);
        $latency = $alive && isset($result['latency']) ? (int) round((float) $result['latency']) : null;

        $accessPoint->forceFill([
            'status' => $alive ? 'up' : 'down',
            'ping_latency_ms' => $latency,
            'last_ping_at' => now(),
            'last_seen_at' => $alive ? now() : $accessPoint->last_seen_at,
        ])->saveQuietly();

        return back()->with('success', "{$accessPoint->name}: ".($alive ? "UP ({$latency} ms)" : 'DOWN'));
    }

    public function toggleMonitor(AccessPoint $accessPoint)
    {
        $accessPoint->update(['monitor_enabled' => ! $accessPoint->monitor_enabled]);

        return back()->with('success', "{$accessPoint->name}: monitoring "
            .($accessPoint->monitor_enabled ? 'enabled' : 'disabled').'.');
    }

    /**
     * Edit an access point. Accepts the same fields as the add form — an AP
     * created without an IP could otherwise never be given one, which left it
     * permanently unpingable with no way out of the UI.
     */
    public function update(Request $request, AccessPoint $accessPoint)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'vendor' => 'required|string|max:50',
            'model' => 'nullable|string|max:100',
            'serial_number' => 'nullable|string|max:100',
            'mac_address' => ['nullable', 'string', 'max:20', 'regex:/^([0-9a-fA-F]{2}[:-]?){5}[0-9a-fA-F]{2}$/'],
            'ip_address' => 'nullable|ip',
            'site' => 'nullable|string|max:100',
            'branch_id' => 'nullable|exists:branches,id',
            'firmware' => 'nullable|string|max:50',
        ], [
            'mac_address.regex' => 'Enter the MAC as 12 hex digits, with or without separators.',
        ]);

        $serial = trim((string) ($validated['serial_number'] ?? '')) ?: null;
        $mac = $this->normaliseMac($validated['mac_address'] ?? null);

        if ($clash = $this->findClash($serial, $mac, $accessPoint->id)) {
            return back()->with('error',
                "That serial/MAC already belongs to \"{$clash->name}\".");
        }

        $accessPoint->update([
            ...$validated,
            'serial_number' => $serial,
            'mac_address' => $mac,
        ]);

        return back()->with('success', "{$accessPoint->name} updated.");
    }

    /**
     * Another access point already using this serial or MAC. Those two fields are
     * how every other part of the system recognises an AP, so a duplicate would
     * quietly split its history across two rows.
     */
    private function findClash(?string $serial, ?string $mac, ?int $excludeId = null): ?AccessPoint
    {
        if (! $serial && ! $mac) {
            return null;
        }

        return AccessPoint::query()
            ->when($excludeId, fn ($q) => $q->whereKeyNot($excludeId))
            ->where(fn ($q) => $q
                ->when($serial, fn ($w) => $w->orWhere('serial_number', $serial))
                ->when($mac, fn ($w) => $w->orWhere('mac_address', $mac)))
            ->first();
    }

    /** Lower-case, colon-separated — the format the CSV importer stores. */
    private function normaliseMac(?string $mac): ?string
    {
        $hex = strtolower(preg_replace('/[^a-f0-9]/i', '', (string) $mac));

        return strlen($hex) === 12 ? implode(':', str_split($hex, 2)) : null;
    }

    public function destroy(AccessPoint $accessPoint)
    {
        $name = $accessPoint->name;
        $accessPoint->delete();

        return back()->with('success', "Access point '{$name}' removed.");
    }
}
