<?php

namespace App\Http\Controllers;

use App\Jobs\SyncGdmsDeviceAccountsJob;
use App\Models\Contact;
use App\Models\PhoneAccount;
use App\Models\PhoneRequestLog;
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
     * Trigger a full GDMS device-account sync (queued with 30-min timeout).
     */
    public function sync()
    {
        SyncGdmsDeviceAccountsJob::dispatch(Auth::id(), false);

        return redirect()->route('admin.phone-logs.index')
            ->with('success', 'GDMS device-account sync started in background. Refresh in a few minutes to see results.');
    }

    /**
     * Sync only devices that have no entries in phone_accounts yet (queued).
     */
    public function syncUnsynced()
    {
        SyncGdmsDeviceAccountsJob::dispatch(Auth::id(), true);

        return redirect()->route('admin.phone-logs.index')
            ->with('success', 'Unsynced device sync started in background. Refresh in a few minutes to see results.');
    }
}
