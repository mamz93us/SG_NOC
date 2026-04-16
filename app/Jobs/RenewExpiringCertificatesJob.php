<?php

namespace App\Jobs;

use App\Models\SslCertificate;
use App\Services\Dns\AcmeService;
use App\Services\Dns\GoDaddyService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RenewExpiringCertificatesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;
    public int $tries   = 1;

    public function handle(): void
    {
        $expiring = SslCertificate::with('account')
            ->where('status', 'valid')
            ->where('auto_renew', true)
            ->where('expires_at', '<=', now()->addDays(14))
            ->get();

        if ($expiring->isEmpty()) {
            Log::info('RenewExpiringCertificatesJob: No certificates expiring within 14 days.');
            return;
        }

        Log::info("RenewExpiringCertificatesJob: Found {$expiring->count()} certificate(s) to renew.");

        foreach ($expiring as $cert) {
            try {
                $account = $cert->account;
                if (!$account || !$account->is_active) {
                    Log::warning("RenewExpiringCertificatesJob: Skipping {$cert->fqdn} — account inactive or missing.");
                    continue;
                }

                $godaddy = new GoDaddyService($account);
                $acme    = new AcmeService($godaddy);

                Log::info("RenewExpiringCertificatesJob: Renewing {$cert->fqdn}");
                $acme->renewCertificate($cert, $account);

                \App\Models\ActivityLog::create([
                    'model_type' => 'DnsAccount',
                    'model_id'   => $account->id,
                    'action'     => 'certificate.auto_renewed',
                    'changes'    => ['fqdn' => $cert->fqdn, 'new_expires' => $cert->fresh()->expires_at],
                    'user_id'    => null,
                ]);

                Log::info("RenewExpiringCertificatesJob: Renewed {$cert->fqdn} successfully.");
            } catch (\Throwable $e) {
                $cert->update(['status' => 'failed', 'failure_reason' => $e->getMessage()]);
                Log::error("RenewExpiringCertificatesJob: Failed to renew {$cert->fqdn}: {$e->getMessage()}");
            }
        }
    }
}
