<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DnsAccount;
use App\Models\ActivityLog;
use App\Services\Dns\GoDaddyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DnsNameserversController extends Controller
{
    public function show(DnsAccount $account, string $domain)
    {
        $service = new GoDaddyService($account);

        try {
            $domainInfo = $service->getDomain($domain);
            $nameservers = $domainInfo['nameServers'] ?? [];
        } catch (\Throwable $e) {
            return redirect()->route('admin.network.dns.domains.index', $account)
                ->with('error', "Failed to load nameservers: {$e->getMessage()}");
        }

        return view('admin.dns.nameservers', compact('account', 'domain', 'nameservers'));
    }

    public function update(Request $request, DnsAccount $account, string $domain)
    {
        $validated = $request->validate([
            'nameservers'   => 'required|array|min:1|max:10',
            'nameservers.*' => 'required|string|max:255',
        ]);

        $service = new GoDaddyService($account);

        try {
            $service->updateNameservers($domain, $validated['nameservers']);

            ActivityLog::create([
                'model_type' => 'DnsAccount',
                'model_id'   => $account->id,
                'action'     => 'nameservers.updated',
                'changes'    => ['domain' => $domain, 'nameservers' => $validated['nameservers']],
                'user_id'    => Auth::id(),
            ]);

            return response()->json(['success' => true, 'message' => 'Nameservers updated successfully.']);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }
}
