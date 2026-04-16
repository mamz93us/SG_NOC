<?php

namespace App\Services\Dns;

use App\Models\ActivityLog;
use App\Models\DnsAccount;
use App\Models\SubdomainRecord;
use App\Models\SslCertificate;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

class SubdomainService
{
    public function __construct(private GoDaddyService $godaddy) {}

    /**
     * List all subdomains for a domain by reading A records from GoDaddy
     * and merging with local subdomain_records table.
     */
    public function listSubdomains(DnsAccount $account, string $domain): Collection
    {
        // Fetch A records from GoDaddy
        $records = collect();
        try {
            $rawRecords = $this->godaddy->getRecords($domain, 'A');
            $records = collect($rawRecords)->filter(fn($r) => ($r['name'] ?? '') !== '@');
        } catch (\Throwable) {
            // Fall back to local records only
        }

        // Build lookup of local records
        $local = SubdomainRecord::where('account_id', $account->id)
            ->where('domain', $domain)
            ->with('sslCertificate')
            ->get()
            ->keyBy('subdomain');

        // Merge GoDaddy A records with local DB
        $merged = $records->map(function ($rec) use ($domain, $account, $local) {
            $sub    = $rec['name'];
            $fqdn   = "{$sub}.{$domain}";
            $localRec = $local->get($sub);

            return [
                'subdomain'   => $sub,
                'fqdn'        => $fqdn,
                'ip_address'  => $rec['data'] ?? '',
                'ttl'         => $rec['ttl'] ?? 3600,
                'is_noc_ip'   => ($rec['data'] ?? '') === config('noc.server_ip', env('NOC_SERVER_IP', '')),
                'local_id'    => $localRec?->id,
                'ssl'         => $localRec?->sslCertificate,
                'godaddy_synced' => true,
            ];
        });

        // Add any local records not found in GoDaddy (unsynced)
        $local->each(function ($localRec) use (&$merged) {
            if (!$merged->firstWhere('subdomain', $localRec->subdomain)) {
                $merged->push([
                    'subdomain'      => $localRec->subdomain,
                    'fqdn'           => $localRec->fqdn,
                    'ip_address'     => $localRec->ip_address,
                    'ttl'            => $localRec->ttl,
                    'is_noc_ip'      => $localRec->isNocIp(),
                    'local_id'       => $localRec->id,
                    'ssl'            => $localRec->sslCertificate,
                    'godaddy_synced' => false,
                ]);
            }
        });

        return $merged->sortBy('subdomain')->values();
    }

    /**
     * Create a subdomain: push A record to GoDaddy + save locally.
     */
    public function createSubdomain(
        DnsAccount $account,
        string $domain,
        string $subdomain,
        string $ip,
        int $ttl = 3600,
        ?User $user = null
    ): SubdomainRecord {
        // Validate subdomain format
        if (!preg_match('/^[a-z0-9]([a-z0-9\-]*[a-z0-9])?$/i', $subdomain)) {
            throw new \InvalidArgumentException('Invalid subdomain name. Use only letters, numbers, and hyphens.');
        }

        // Push A record to GoDaddy
        $this->godaddy->addRecords($domain, [[
            'type' => 'A',
            'name' => $subdomain,
            'data' => $ip,
            'ttl'  => $ttl,
        ]]);

        $fqdn = "{$subdomain}.{$domain}";

        // Upsert local record
        $record = SubdomainRecord::updateOrCreate(
            ['account_id' => $account->id, 'domain' => $domain, 'subdomain' => $subdomain],
            [
                'fqdn'           => $fqdn,
                'ip_address'     => $ip,
                'ttl'            => $ttl,
                'godaddy_synced' => true,
                'created_by'     => $user?->id ?? Auth::id(),
            ]
        );

        ActivityLog::create([
            'model_type' => 'DnsAccount',
            'model_id'   => $account->id,
            'action'     => 'subdomain.created',
            'changes'    => ['subdomain' => $subdomain, 'fqdn' => $fqdn, 'ip' => $ip, 'ttl' => $ttl],
            'user_id'    => $user?->id ?? Auth::id(),
        ]);

        return $record;
    }

    /**
     * Delete a subdomain from GoDaddy + local DB + revoke any SSL.
     */
    public function deleteSubdomain(
        DnsAccount $account,
        string $domain,
        string $subdomain,
        ?User $user = null
    ): bool {
        try {
            $this->godaddy->deleteRecordsByTypeAndName($domain, 'A', $subdomain);
        } catch (\Throwable $e) {
            // Log but continue — remove locally even if GoDaddy fails
            \Illuminate\Support\Facades\Log::warning("GoDaddy delete failed for {$subdomain}.{$domain}: {$e->getMessage()}");
        }

        // Revoke SSL if exists
        $local = SubdomainRecord::where('account_id', $account->id)
            ->where('domain', $domain)
            ->where('subdomain', $subdomain)
            ->with('sslCertificate')
            ->first();

        if ($local?->sslCertificate) {
            $local->sslCertificate->update(['status' => 'revoked']);
        }

        $local?->delete();

        ActivityLog::create([
            'model_type' => 'DnsAccount',
            'model_id'   => $account->id,
            'action'     => 'subdomain.deleted',
            'changes'    => ['subdomain' => $subdomain, 'domain' => $domain],
            'user_id'    => $user?->id ?? Auth::id(),
        ]);

        return true;
    }

    /**
     * Sync: read all A records from GoDaddy and upsert subdomain_records.
     */
    public function syncFromGoDaddy(DnsAccount $account, string $domain): array
    {
        $records = $this->godaddy->getRecords($domain, 'A');
        $added = $updated = 0;

        foreach ($records as $rec) {
            $sub = $rec['name'] ?? '';
            if ($sub === '@' || $sub === '') continue;

            $fqdn = "{$sub}.{$domain}";
            $existing = SubdomainRecord::where('account_id', $account->id)
                ->where('domain', $domain)
                ->where('subdomain', $sub)
                ->first();

            if ($existing) {
                $existing->update([
                    'ip_address'     => $rec['data'],
                    'ttl'            => $rec['ttl'] ?? 3600,
                    'godaddy_synced' => true,
                ]);
                $updated++;
            } else {
                SubdomainRecord::create([
                    'account_id'     => $account->id,
                    'domain'         => $domain,
                    'subdomain'      => $sub,
                    'fqdn'           => $fqdn,
                    'ip_address'     => $rec['data'],
                    'ttl'            => $rec['ttl'] ?? 3600,
                    'godaddy_synced' => true,
                ]);
                $added++;
            }
        }

        // Mark any local records not found in GoDaddy as unsynced
        $godaddySubs = collect($records)->pluck('name')->filter(fn($n) => $n !== '@')->all();
        $removed = SubdomainRecord::where('account_id', $account->id)
            ->where('domain', $domain)
            ->whereNotIn('subdomain', $godaddySubs)
            ->update(['godaddy_synced' => false]);

        return compact('added', 'updated', 'removed');
    }
}
