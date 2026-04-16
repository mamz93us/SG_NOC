<?php

namespace App\Services\Dns;

use App\Models\ActivityLog;
use App\Models\DnsAccount;
use App\Models\SslCertificate;
use App\Models\SubdomainRecord;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;

class AcmeService
{
    public function __construct(private GoDaddyService $godaddy) {}

    /**
     * Issue a new SSL certificate via ACME DNS-01 challenge.
     *
     * Requires the `afosto/yaac` package:
     *   composer require afosto/yaac
     */
    public function issueCertificate(
        DnsAccount $account,
        string $fqdn,
        string $domain,
        ?User $user = null
    ): SslCertificate {
        if (!class_exists(\Afosto\Acme\Client::class)) {
            throw new \RuntimeException('ACME client not installed. Run: composer require afosto/yaac');
        }

        $userId = $user?->id ?? \Illuminate\Support\Facades\Auth::id();

        // Create or update the certificate record as pending
        $cert = SslCertificate::updateOrCreate(
            ['account_id' => $account->id, 'fqdn' => $fqdn],
            [
                'domain'         => $domain,
                'issuer'         => 'letsencrypt',
                'status'         => 'pending',
                'challenge_type' => 'dns01',
                'failure_reason' => null,
                'created_by'     => $userId,
            ]
        );

        try {
            // Resolve ACME directory URL
            $directoryUrl = config('acme.use_staging')
                ? config('acme.staging_url', 'https://acme-staging-v02.api.letsencrypt.org/directory')
                : config('acme.directory_url', 'https://acme-v02.api.letsencrypt.org/directory');

            // afosto/yaac requires a Flysystem filesystem to store its account key + data
            $storagePath = storage_path('app/acme/' . preg_replace('/[^a-z0-9_\-]/i', '_', $domain));
            if (!is_dir($storagePath)) {
                mkdir($storagePath, 0755, true);
            }

            $filesystem = new Filesystem(new LocalFilesystemAdapter($storagePath));

            // Bootstrap ACME client — 'fs' is mandatory for afosto/yaac
            $client = new \Afosto\Acme\Client([
                'username'      => config('acme.email', 'admin@example.com'),
                'fs'            => $filesystem,
                'directory_url' => $directoryUrl,
            ]);

            Log::info("AcmeService: Starting certificate issuance for {$fqdn}");

            // Create ACME order
            $order = $client->createOrder([$fqdn]);

            // Get DNS-01 authorizations
            $authorizations = $client->authorize($order);

            $addedTxtRecords      = [];
            $challengesToValidate = [];

            foreach ($authorizations as $authorization) {
                $challenges   = $authorization->getChallenges();
                $dnsChallenge = null;

                foreach ($challenges as $challenge) {
                    if ($challenge->getType() === 'dns-01') {
                        $dnsChallenge = $challenge;
                        break;
                    }
                }

                if (!$dnsChallenge) {
                    throw new \RuntimeException("No DNS-01 challenge found for {$fqdn}");
                }

                $txtName  = $dnsChallenge->getTxtRecord();  // e.g. _acme-challenge
                $txtValue = $dnsChallenge->getDigest();

                Log::info("AcmeService: Adding TXT record {$txtName} = {$txtValue} for {$fqdn}");

                // Add TXT record via GoDaddy
                $this->godaddy->addRecords($domain, [[
                    'type' => 'TXT',
                    'name' => $txtName,
                    'data' => $txtValue,
                    'ttl'  => 600,
                ]]);

                $addedTxtRecords[]      = $txtName;
                $challengesToValidate[] = $dnsChallenge;
            }

            // Wait for DNS propagation before notifying ACME
            $waitSeconds = (int) config('acme.dns_propagation_wait', 60);
            Log::info("AcmeService: Waiting {$waitSeconds}s for DNS propagation...");
            sleep($waitSeconds);

            // Notify ACME to validate each DNS-01 challenge
            foreach ($challengesToValidate as $pendingChallenge) {
                $client->validate($pendingChallenge, false);
            }

            // Poll for order to become ready (ACME is verifying TXT records)
            $retries = (int) config('acme.dns_poll_retries', 12);
            for ($i = 0; $i < $retries; $i++) {
                if ($client->isReady($order)) break;
                Log::info("AcmeService: Waiting for order to be ready ({$i}/{$retries})...");
                sleep(10);
            }

            if (!$client->isReady($order)) {
                throw new \RuntimeException('ACME order did not become ready after validation. Check DNS propagation.');
            }

            // Generate CSR and private key
            [$privateKey, $csr] = $client->generateCsr([$fqdn]);

            // Finalize the order
            $client->finalize($order, $csr);

            // Wait for certificate to be issued
            for ($i = 0; $i < $retries; $i++) {
                if ($client->isFinalized($order)) break;
                sleep(5);
            }

            // Download the certificate
            $certificate = $client->getCertificate($order);
            $fullChain   = $certificate->getFullChainPem();
            $expiresAt   = $certificate->getExpires(); // \DateTime

            // Persist to DB (encrypted fields)
            $cert->update([
                'status'         => 'valid',
                'certificate'    => $fullChain,
                'private_key'    => $privateKey,
                'csr'            => $csr,
                'issued_at'      => now(),
                'expires_at'     => $expiresAt,
                'failure_reason' => null,
            ]);

            // Link to subdomain record if one exists
            SubdomainRecord::where('account_id', $account->id)
                ->where('fqdn', $fqdn)
                ->update(['ssl_certificate_id' => $cert->id]);

            ActivityLog::create([
                'model_type' => 'DnsAccount',
                'model_id'   => $account->id,
                'action'     => 'certificate.issued',
                'changes'    => ['fqdn' => $fqdn, 'issuer' => 'letsencrypt', 'expires_at' => $expiresAt],
                'user_id'    => $userId,
            ]);

            Log::info("AcmeService: Certificate issued successfully for {$fqdn}");

        } catch (\Throwable $e) {
            $cert->update(['status' => 'failed', 'failure_reason' => $e->getMessage()]);
            Log::error("AcmeService: Certificate issuance failed for {$fqdn}: {$e->getMessage()}");
            throw $e;
        } finally {
            // Always clean up TXT records
            foreach ($addedTxtRecords ?? [] as $txtName) {
                try {
                    $this->godaddy->deleteRecordsByTypeAndName($domain, 'TXT', $txtName);
                    Log::info("AcmeService: Cleaned up TXT record {$txtName}");
                } catch (\Throwable $cleanupErr) {
                    Log::warning("AcmeService: Failed to clean up TXT {$txtName}: {$cleanupErr->getMessage()}");
                }
            }
        }

        return $cert->fresh();
    }

    /**
     * Renew an existing certificate.
     */
    public function renewCertificate(SslCertificate $cert, DnsAccount $account): SslCertificate
    {
        $oldExpiry = $cert->expires_at;

        $renewed = $this->issueCertificate($account, $cert->fqdn, $cert->domain);

        $renewed->update([
            'last_renewed_at' => now(),
            'auto_renew'      => $cert->auto_renew,
        ]);

        ActivityLog::create([
            'model_type' => 'DnsAccount',
            'model_id'   => $account->id,
            'action'     => 'certificate.renewed',
            'changes'    => ['fqdn' => $cert->fqdn, 'old_expires' => $oldExpiry, 'new_expires' => $renewed->expires_at],
            'user_id'    => \Illuminate\Support\Facades\Auth::id(),
        ]);

        return $renewed;
    }

    /**
     * Revoke a certificate (marks as revoked in DB).
     */
    public function revokeCertificate(SslCertificate $cert): bool
    {
        $cert->update(['status' => 'revoked']);

        ActivityLog::create([
            'model_type' => 'DnsAccount',
            'model_id'   => $cert->account_id,
            'action'     => 'certificate.revoked',
            'changes'    => ['fqdn' => $cert->fqdn],
            'user_id'    => \Illuminate\Support\Facades\Auth::id(),
        ]);

        return true;
    }
}
