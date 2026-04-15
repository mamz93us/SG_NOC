<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DnsAccount;
use App\Services\Dns\GoDaddyService;
use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DnsDomainsController extends Controller
{
    public function index(DnsAccount $account)
    {
        $service = new GoDaddyService($account);

        try {
            $domains = $service->getDomains(['limit' => 999]);
        } catch (\Throwable $e) {
            return redirect()->route('admin.network.dns.index')
                ->with('error', "Failed to load domains: {$e->getMessage()}");
        }

        return view('admin.dns.domains', compact('account', 'domains'));
    }

    public function show(DnsAccount $account, string $domain)
    {
        $service = new GoDaddyService($account);

        try {
            $domainInfo = $service->getDomain($domain);
        } catch (\Throwable $e) {
            return redirect()->route('admin.network.dns.domains.index', $account)
                ->with('error', "Failed to load domain details: {$e->getMessage()}");
        }

        return view('admin.dns.settings', compact('account', 'domain', 'domainInfo'));
    }

    public function update(Request $request, DnsAccount $account, string $domain)
    {
        $validated = $request->validate([
            'renewAuto'   => 'nullable|boolean',
            'privacy'     => 'nullable|boolean',
            'exposeWhois' => 'nullable|boolean',
        ]);

        $data = [
            'renewAuto'   => $request->boolean('renewAuto'),
            'privacy'     => $request->boolean('privacy'),
            'exposeWhois' => $request->boolean('exposeWhois'),
        ];

        $service = new GoDaddyService($account);

        try {
            $service->updateDomain($domain, $data);

            ActivityLog::create([
                'model_type' => 'DnsAccount',
                'model_id'   => $account->id,
                'action'     => 'domain.settings_updated',
                'changes'    => ['domain' => $domain, 'settings' => $data],
                'user_id'    => Auth::id(),
            ]);

            return response()->json(['success' => true, 'message' => 'Domain settings updated.']);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }
}
