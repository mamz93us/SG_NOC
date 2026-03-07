<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Contact;
use App\Models\PhoneAccount;
use App\Models\PhoneRequestLog;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class PhoneRequestLogController extends Controller
{
    public function index()
    {
        $logs = PhoneRequestLog::select(
                'mac',
                'model',
                DB::raw('MAX(created_at) as last_request_at'),
                DB::raw('COUNT(*) as total_requests')
            )
            ->whereNotNull('mac')
            ->groupBy('mac', 'model')
            ->orderByDesc('last_request_at')
            ->get();

        // Load SIP accounts for all MACs, grouped by MAC
        $macs     = $logs->pluck('mac');
        $accounts = PhoneAccount::whereIn('mac', $macs)
            ->orderBy('mac')
            ->orderBy('account_index')
            ->get()
            ->groupBy('mac');

        // Build contact lookup: phone → Contact (to resolve sip_user_id → person name)
        $sipUserIds = PhoneAccount::whereIn('mac', $macs)
            ->whereNotNull('sip_user_id')
            ->pluck('sip_user_id')
            ->unique();

        $contactsByPhone = Contact::whereIn('phone', $sipUserIds)
            ->get()
            ->keyBy('phone');

        return view('admin.phone-logs.index', compact('logs', 'accounts', 'contactsByPhone'));
    }

    /**
     * Trigger a full GDMS device-account sync.
     */
    public function sync()
    {
        set_time_limit(0);

        Artisan::call('gdms:sync-device-accounts');

        ActivityLog::create([
            'model_type' => 'PhoneRequestLog',
            'model_id'   => 0,
            'action'     => 'synced',
            'changes'    => ['type' => 'full_sync'],
            'user_id'    => Auth::id(),
        ]);

        return redirect()->route('admin.phone-logs.index')
            ->with('success', 'SIP accounts synced successfully from GDMS.');
    }

    /**
     * Sync only devices that have no entries in phone_accounts yet.
     */
    public function syncUnsynced()
    {
        set_time_limit(0);

        Artisan::call('gdms:sync-device-accounts', ['--unsynced' => true]);

        $output  = Artisan::output();
        $message = str_contains($output, 'Nothing to do')
            ? 'All devices were already synced — nothing to do.'
            : 'Unsynced devices fetched from GDMS successfully.';

        ActivityLog::create([
            'model_type' => 'PhoneRequestLog',
            'model_id'   => 0,
            'action'     => 'synced',
            'changes'    => ['type' => 'unsynced_only', 'result' => $message],
            'user_id'    => Auth::id(),
        ]);

        return redirect()->route('admin.phone-logs.index')
            ->with('success', $message);
    }
}
