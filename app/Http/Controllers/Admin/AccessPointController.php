<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AccessPoint;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Services\AccessPointImporter;
use App\Services\PingService;
use Illuminate\Http\Request;

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

    public function pingNow(AccessPoint $accessPoint, PingService $ping)
    {
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

    public function update(Request $request, AccessPoint $accessPoint)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'ip_address' => 'nullable|ip',
            'branch_id' => 'nullable|exists:branches,id',
            'vendor' => 'nullable|string|max:50',
        ]);

        $accessPoint->update($validated);

        return back()->with('success', "{$accessPoint->name} updated.");
    }

    public function destroy(AccessPoint $accessPoint)
    {
        $name = $accessPoint->name;
        $accessPoint->delete();

        return back()->with('success', "Access point '{$name}' removed.");
    }
}
