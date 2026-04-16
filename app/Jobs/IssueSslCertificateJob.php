<?php

namespace App\Jobs;

use App\Models\DnsAccount;
use App\Models\User;
use App\Services\Dns\AcmeService;
use App\Services\Dns\GoDaddyService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class IssueSslCertificateJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;
    public int $tries   = 1;

    public function __construct(
        public DnsAccount $account,
        public string $fqdn,
        public string $domain,
        public ?User $user = null,
    ) {}

    public function handle(): void
    {
        try {
            $godaddy = new GoDaddyService($this->account);
            $acme    = new AcmeService($godaddy);
            $acme->issueCertificate($this->account, $this->fqdn, $this->domain, $this->user);
            Log::info("IssueSslCertificateJob: Completed for {$this->fqdn}");
        } catch (\Throwable $e) {
            Log::error("IssueSslCertificateJob: Failed for {$this->fqdn}: {$e->getMessage()}");
            throw $e;
        }
    }
}
