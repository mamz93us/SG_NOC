<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DnsAccount;
use App\Models\ActivityLog;
use App\Services\Dns\GoDaddyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DnsRecordsController extends Controller
{
    public function index(DnsAccount $account, string $domain)
    {
        $service = new GoDaddyService($account);

        try {
            $records = $service->getRecords($domain);
        } catch (\Throwable $e) {
            return redirect()->route('admin.network.dns.domains.index', $account)
                ->with('error', "Failed to load records: {$e->getMessage()}");
        }

        return view('admin.dns.records', compact('account', 'domain', 'records'));
    }

    public function store(Request $request, DnsAccount $account, string $domain)
    {
        $validated = $request->validate([
            'type'     => 'required|string|in:A,AAAA,CNAME,MX,TXT,NS,SRV,CAA,PTR',
            'name'     => 'required|string|max:255',
            'data'     => 'required|string|max:1024',
            'ttl'      => 'required|integer|min:600|max:604800',
            'priority' => 'nullable|integer|min:0|max:65535',
        ]);

        $record = [
            'type' => $validated['type'],
            'name' => $validated['name'],
            'data' => $validated['data'],
            'ttl'  => $validated['ttl'],
        ];

        if (in_array($validated['type'], ['MX', 'SRV'])) {
            $record['priority'] = $validated['priority'] ?? 0;
        }

        $service = new GoDaddyService($account);

        try {
            $service->addRecords($domain, [$record]);

            ActivityLog::create([
                'model_type' => 'DnsAccount',
                'model_id'   => $account->id,
                'action'     => 'record.created',
                'changes'    => ['domain' => $domain, 'record' => $record],
                'user_id'    => Auth::id(),
            ]);

            return response()->json(['success' => true, 'message' => 'Record added successfully.']);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function update(Request $request, DnsAccount $account, string $domain)
    {
        $validated = $request->validate([
            'type'     => 'required|string|in:A,AAAA,CNAME,MX,TXT,NS,SRV,CAA,PTR',
            'name'     => 'required|string|max:255',
            'data'     => 'required|string|max:1024',
            'ttl'      => 'required|integer|min:600|max:604800',
            'priority' => 'nullable|integer|min:0|max:65535',
        ]);

        $record = [
            'data' => $validated['data'],
            'ttl'  => $validated['ttl'],
        ];

        if (in_array($validated['type'], ['MX', 'SRV'])) {
            $record['priority'] = $validated['priority'] ?? 0;
        }

        $service = new GoDaddyService($account);

        try {
            $service->replaceRecordsByTypeAndName($domain, $validated['type'], $validated['name'], [$record]);

            ActivityLog::create([
                'model_type' => 'DnsAccount',
                'model_id'   => $account->id,
                'action'     => 'record.updated',
                'changes'    => ['domain' => $domain, 'type' => $validated['type'], 'name' => $validated['name'], 'record' => $record],
                'user_id'    => Auth::id(),
            ]);

            return response()->json(['success' => true, 'message' => 'Record updated successfully.']);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function destroy(Request $request, DnsAccount $account, string $domain)
    {
        $validated = $request->validate([
            'type' => 'required|string|in:A,AAAA,CNAME,MX,TXT,NS,SRV,CAA,PTR',
            'name' => 'required|string|max:255',
        ]);

        $service = new GoDaddyService($account);

        try {
            $service->deleteRecordsByTypeAndName($domain, $validated['type'], $validated['name']);

            ActivityLog::create([
                'model_type' => 'DnsAccount',
                'model_id'   => $account->id,
                'action'     => 'record.deleted',
                'changes'    => ['domain' => $domain, 'type' => $validated['type'], 'name' => $validated['name']],
                'user_id'    => Auth::id(),
            ]);

            return response()->json(['success' => true, 'message' => 'Record deleted successfully.']);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }
}
