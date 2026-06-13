<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\NocEvent;
use App\Models\Setting;
use App\Models\SophosCentralAccessPoint;
use App\Models\SophosCentralFirewall;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

class SophosCentralController extends Controller
{
    public function index(Request $request)
    {
        $settings = Setting::get();

        $apQuery = SophosCentralAccessPoint::query();
        if ($request->filled('ap_status')) {
            $apQuery->where('status', $request->ap_status);
        }
        if ($request->filled('q')) {
            $q = $request->q;
            $apQuery->where(function ($w) use ($q) {
                $w->where('name', 'like', "%{$q}%")
                    ->orWhere('serial_number', 'like', "%{$q}%")
                    ->orWhere('mac_address', 'like', "%{$q}%")
                    ->orWhere('site_name', 'like', "%{$q}%")
                    ->orWhere('ip_address', 'like', "%{$q}%");
            });
        }

        $accessPoints = $apQuery->orderBy('site_name')->orderBy('name')->get();
        $firewalls = SophosCentralFirewall::with('localFirewall')->orderBy('name')->get();

        $openAlerts = NocEvent::open()
            ->whereIn('source_type', [
                'sophos_central_alert',
                'sophos_central_ap_offline',
                'sophos_central_fw_disconnected',
            ])
            ->orderByDesc('last_seen')
            ->limit(50)
            ->get();

        return view('admin.network.sophos-central.index', [
            'settings' => $settings,
            'accessPoints' => $accessPoints,
            'firewalls' => $firewalls,
            'openAlerts' => $openAlerts,
            'apTotal' => SophosCentralAccessPoint::count(),
            'apOnline' => SophosCentralAccessPoint::where('status', 'online')->count(),
            'apOffline' => SophosCentralAccessPoint::where('status', 'offline')->count(),
            'fwTotal' => SophosCentralFirewall::count(),
            'fwConnected' => SophosCentralFirewall::where('status', 'connected')->count(),
        ]);
    }

    public function sync()
    {
        $settings = Setting::get();

        if (! $settings->sophos_central_client_id) {
            return back()->with('error', 'Sophos Central API credentials are not configured. Set them in Settings first.');
        }

        try {
            $exit = Artisan::call('sophos-central:sync', ['--force' => true]);
            $output = trim(Artisan::output());

            if ($exit === 0) {
                return back()->with('success', "Sophos Central sync completed.\n".$output);
            }

            return back()->with('error', "Sophos Central sync finished with errors.\n".$output);
        } catch (\Throwable $e) {
            return back()->with('error', 'Sophos Central sync failed: '.$e->getMessage());
        }
    }
}
