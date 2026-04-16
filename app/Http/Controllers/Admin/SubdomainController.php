<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DnsAccount;
use App\Services\Dns\GoDaddyService;
use App\Services\Dns\SubdomainService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SubdomainController extends Controller
{
    public function index(DnsAccount $account, string $domain)
    {
        $godaddy  = new GoDaddyService($account);
        $service  = new SubdomainService($godaddy);

        try {
            $subdomains = $service->listSubdomains($account, $domain);
        } catch (\Throwable $e) {
            return redirect()->route('admin.network.dns.domains.index', $account)
                ->with('error', "Failed to load subdomains: {$e->getMessage()}");
        }

        $nocIp = config('noc.server_ip', env('NOC_SERVER_IP', ''));

        return view('admin.dns.subdomains', compact('account', 'domain', 'subdomains', 'nocIp'));
    }

    public function store(Request $request, DnsAccount $account, string $domain)
    {
        $validated = $request->validate([
            'subdomain'   => ['required', 'string', 'max:63', 'regex:/^[a-z0-9]([a-z0-9\-]*[a-z0-9])?$/i'],
            'ip_address'  => 'required|ip',
            'ttl'         => 'required|integer|min:600|max:604800',
            'issue_ssl'   => 'nullable|boolean',
        ]);

        $godaddy = new GoDaddyService($account);
        $service = new SubdomainService($godaddy);

        try {
            $record = $service->createSubdomain(
                $account,
                $domain,
                $validated['subdomain'],
                $validated['ip_address'],
                $validated['ttl'],
                Auth::user()
            );

            $response = ['success' => true, 'message' => "Subdomain {$record->fqdn} created.", 'record' => $record];

            // If SSL requested, dispatch as background job
            if ($request->boolean('issue_ssl')) {
                dispatch(new \App\Jobs\IssueSslCertificateJob($account, $record->fqdn, $domain, Auth::user()));
                $response['ssl_queued'] = true;
                $response['message'] .= ' SSL issuance queued.';
            }

            return response()->json($response);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function destroy(Request $request, DnsAccount $account, string $domain, string $subdomain)
    {
        $godaddy = new GoDaddyService($account);
        $service = new SubdomainService($godaddy);

        try {
            $service->deleteSubdomain($account, $domain, $subdomain, Auth::user());
            return response()->json(['success' => true, 'message' => "Subdomain {$subdomain}.{$domain} deleted."]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function sync(DnsAccount $account, string $domain)
    {
        $godaddy = new GoDaddyService($account);
        $service = new SubdomainService($godaddy);

        try {
            $result = $service->syncFromGoDaddy($account, $domain);
            return response()->json([
                'success' => true,
                'message' => "Sync complete: {$result['added']} added, {$result['updated']} updated, {$result['removed']} unsynced.",
                'result'  => $result,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }
}
