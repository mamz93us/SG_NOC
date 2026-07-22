<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\DnsAccount;
use App\Services\Dns\GoDaddyService;
use App\Services\Dns\ZoneFileParser;
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
            $this->logDnsFailure($account, 'getRecords', $domain, $e);

            return redirect()->route('admin.network.dns.domains.index', $account)
                ->with('error', "Failed to load records: {$e->getMessage()}");
        }

        return view('admin.dns.records', compact('account', 'domain', 'records'));
    }

    public function store(Request $request, DnsAccount $account, string $domain)
    {
        $validated = $request->validate([
            'type' => 'required|string|in:A,AAAA,CNAME,MX,TXT,NS,SRV,CAA,PTR',
            'name' => 'required|string|max:255',
            'data' => 'required|string|max:1024',
            'ttl' => 'required|integer|min:600|max:604800',
            'priority' => 'nullable|integer|min:0|max:65535',
        ]);

        $record = [
            'type' => $validated['type'],
            'name' => $validated['name'],
            'data' => $validated['data'],
            'ttl' => $validated['ttl'],
        ];

        if (in_array($validated['type'], ['MX', 'SRV'])) {
            $record['priority'] = $validated['priority'] ?? 0;
        }

        $service = new GoDaddyService($account);

        try {
            $service->addRecords($domain, [$record]);

            ActivityLog::create([
                'model_type' => 'DnsAccount',
                'model_id' => $account->id,
                'action' => 'record.created',
                'changes' => ['domain' => $domain, 'record' => $record],
                'user_id' => Auth::id(),
            ]);

            return response()->json(['success' => true, 'message' => 'Record added successfully.']);
        } catch (\Throwable $e) {
            $this->logDnsFailure($account, 'addRecord', $domain, $e, ['record' => $record]);

            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function update(Request $request, DnsAccount $account, string $domain)
    {
        $validated = $request->validate([
            'type' => 'required|string|in:A,AAAA,CNAME,MX,TXT,NS,SRV,CAA,PTR',
            'name' => 'required|string|max:255',
            'data' => 'required|string|max:1024',
            'ttl' => 'required|integer|min:600|max:604800',
            'priority' => 'nullable|integer|min:0|max:65535',
        ]);

        $record = [
            'data' => $validated['data'],
            'ttl' => $validated['ttl'],
        ];

        if (in_array($validated['type'], ['MX', 'SRV'])) {
            $record['priority'] = $validated['priority'] ?? 0;
        }

        $service = new GoDaddyService($account);

        try {
            $service->replaceRecordsByTypeAndName($domain, $validated['type'], $validated['name'], [$record]);

            ActivityLog::create([
                'model_type' => 'DnsAccount',
                'model_id' => $account->id,
                'action' => 'record.updated',
                'changes' => ['domain' => $domain, 'type' => $validated['type'], 'name' => $validated['name'], 'record' => $record],
                'user_id' => Auth::id(),
            ]);

            return response()->json(['success' => true, 'message' => 'Record updated successfully.']);
        } catch (\Throwable $e) {
            $this->logDnsFailure($account, 'updateRecord', $domain, $e, ['type' => $validated['type'], 'name' => $validated['name']]);

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
                'model_id' => $account->id,
                'action' => 'record.deleted',
                'changes' => ['domain' => $domain, 'type' => $validated['type'], 'name' => $validated['name']],
                'user_id' => Auth::id(),
            ]);

            return response()->json(['success' => true, 'message' => 'Record deleted successfully.']);
        } catch (\Throwable $e) {
            $this->logDnsFailure($account, 'deleteRecord', $domain, $e, ['type' => $validated['type'], 'name' => $validated['name']]);

            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * Parse a pasted BIND zone file and return a preview of what would be
     * imported, without touching GoDaddy. Feeds the confirmation step.
     */
    public function importPreview(Request $request, DnsAccount $account, string $domain)
    {
        $validated = $request->validate([
            'zone' => 'required|string|max:262144',
        ]);

        $parsed = (new ZoneFileParser($domain))->parse($validated['zone']);

        return response()->json([
            'success' => true,
            'records' => $parsed['records'],
            'skipped' => $parsed['skipped'],
            'errors' => $parsed['errors'],
            'groups' => count($this->groupByTypeAndName($parsed['records'])),
        ]);
    }

    /**
     * Parse the zone file and push the records to GoDaddy. Records are grouped
     * by (type, name) and each group replaces that type/name set via PUT, so the
     * import is idempotent and re-runnable. Groups are pushed independently: one
     * failing group does not abort the rest.
     */
    public function import(Request $request, DnsAccount $account, string $domain)
    {
        $validated = $request->validate([
            'zone' => 'required|string|max:262144',
        ]);

        $parsed = (new ZoneFileParser($domain))->parse($validated['zone']);
        $groups = $this->groupByTypeAndName($parsed['records']);

        if (empty($groups)) {
            return response()->json([
                'success' => false,
                'message' => 'No importable records were found in the zone file.',
            ], 422);
        }

        $service = new GoDaddyService($account);
        $imported = [];
        $failed = [];

        foreach ($groups as $group) {
            $body = array_map(function (array $record): array {
                unset($record['type'], $record['name']);

                return $record;
            }, $group['records']);

            try {
                $service->replaceRecordsByTypeAndName($domain, $group['type'], $group['name'], $body);
                $imported[] = ['type' => $group['type'], 'name' => $group['name'], 'count' => count($body)];
            } catch (\Throwable $e) {
                $failed[] = ['type' => $group['type'], 'name' => $group['name'], 'message' => $e->getMessage()];
                $this->logDnsFailure($account, 'importZone', $domain, $e, ['type' => $group['type'], 'name' => $group['name']]);
            }
        }

        ActivityLog::create([
            'model_type' => 'DnsAccount',
            'model_id' => $account->id,
            'action' => 'records.imported',
            'changes' => [
                'domain' => $domain,
                'imported_count' => array_sum(array_column($imported, 'count')),
                'group_count' => count($imported),
                'failed' => $failed,
                'skipped' => count($parsed['skipped']),
            ],
            'user_id' => Auth::id(),
        ]);

        $recordCount = array_sum(array_column($imported, 'count'));
        $message = "Imported {$recordCount} record(s) across ".count($imported).' name/type group(s).';
        if ($failed) {
            $message .= ' '.count($failed).' group(s) failed.';
        }

        return response()->json([
            'success' => empty($failed),
            'message' => $message,
            'imported' => $imported,
            'failed' => $failed,
            'skipped' => count($parsed['skipped']),
        ], $failed ? 207 : 200);
    }

    /**
     * Group parsed records by (type, name) so each group can be pushed as one
     * idempotent PUT.
     *
     * @param  array<int,array<string,mixed>>  $records
     * @return array<int,array{type:string,name:string,records:array<int,array<string,mixed>>}>
     */
    private function groupByTypeAndName(array $records): array
    {
        $groups = [];

        foreach ($records as $record) {
            $key = $record['type'].'|'.$record['name'];
            if (! isset($groups[$key])) {
                $groups[$key] = ['type' => $record['type'], 'name' => $record['name'], 'records' => []];
            }
            $groups[$key]['records'][] = $record;
        }

        return array_values($groups);
    }

    private function logDnsFailure(DnsAccount $account, string $operation, string $domain, \Throwable $e, array $extra = []): void
    {
        try {
            ActivityLog::create([
                'model_type' => 'DnsAccount',
                'model_id' => $account->id,
                'action' => 'api_failed',
                'changes' => array_merge([
                    'service' => 'GoDaddy',
                    'operation' => $operation,
                    'domain' => $domain,
                    'message' => mb_substr($e->getMessage(), 0, 1000),
                ], $extra),
                'user_id' => Auth::id(),
            ]);
        } catch (\Throwable) {
            // Never mask the original failure with audit errors.
        }
    }
}
