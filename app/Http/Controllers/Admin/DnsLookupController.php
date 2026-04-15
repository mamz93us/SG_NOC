<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DnsAccount;
use App\Services\Dns\GoDaddyService;
use Illuminate\Http\Request;

class DnsLookupController extends Controller
{
    public function index()
    {
        $accounts = DnsAccount::where('is_active', true)->orderBy('label')->get();

        return view('admin.dns.lookup', compact('accounts'));
    }

    public function check(Request $request)
    {
        $validated = $request->validate([
            'account_id' => 'required|exists:dns_accounts,id',
            'domain'     => 'required|string|max:255',
        ]);

        $account = DnsAccount::findOrFail($validated['account_id']);
        $service = new GoDaddyService($account);

        try {
            $result = $service->checkAvailability($validated['domain']);
            return response()->json(['success' => true, 'data' => $result]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }
}
