<?php

namespace App\Services\Dns;

use App\Exceptions\GoDaddyApiException;
use App\Models\ActivityLog;
use App\Models\DnsAccount;
use App\Models\SslCertificate;
use App\Models\SubdomainRecord;
use App\Models\User;
use Illuminate\Support\Facades\Log;

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
            // Resolve ACME directory
            $directoryUrl = config('acme.use_staging')
                ? config('acme.staging_url')
                : config('acme.directory_url');

            // Get or create ACME account key
            $accountKey = $this->getOrCreateAccountKey($domain);

            // Bootstrap ACME client (afosto/yaac uses directory_url to determine live vs staging)
            $client = new \Afosto\Acme\Client([
                'username'      => config('acme.email'),
                'account_key'   => $accountKey,
                'directory_url' => $directoryUrl,
            ]);

            Log::info("AcmeService: Starting certificate issuance for {$fqdn}");

            // Create order
            $order = $client->createOrder([$fqdn]);

            // Get DNS-01 authorizations
            $authorizations = $client->authorize($order);

            $addedTxtRecords      = [];
            $challengesToValidate = [];

            foreach ($authorizations as $authorization) {
                /** @var \Afosto\Acme\Data\Authorization $authorization */
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

                // TXT record name & digest value from ACME
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
            $waitSeconds = config('acme.dns_propagation_wait', 30);
            Log::info("AcmeService: Waiting {$waitSeconds}s for DNS propagation...");
            sleep($waitSeconds);

            // Notify ACME to validate each DNS-01 challenge
            foreach ($challengesToValidate as $pendingChallenge) {
                $client->validate($pendingChallenge, false);
            }

            // Poll for order to become ready (ACME is verifying TXT records)
            $retries = config('acme.dns_poll_retries', 12);
            for ($i = 0; $i < $retries; $i++) {
                if ($client->isReady($order)) break;
                sleep(10);
            }

            if (!$client->isReady($order)) {
                throw new \RuntimeException('ACME order did not become ready after validation');
            }

            // Finalize and get certificate
            [$privateKey, $csr] = $client->generateCsr([$fqdn]);
            $client->finalize($order, $csr);

            // Wait for finalization
            for ($i = 0; $i < $retries; $i++) {
                if ($client->isFinalized($order)) break;
                sleep(5);
            }

            $certificate = $client->getCertificate($order);
            $fullChain   = $certificate->getFullChainPem();
            $expiresAt   = $certificate->getExpires();

            // Store the account key on the certificate for reuse
            $cert->update([
                'status'          => 'valid',
                'certificate'     => $fullChain,
                'private_key'     => $privateKey,
                'csr'             => $csr,
                'acme_account_key'=> $accountKey,
                'issued_at'       => now(),
                'expires_at'      => $expiresAt,
                'failure_reason'  => null,
            ]);

            // Link to subdomain record if exists
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

            Log::info("AcmeService: Certificate issued successfully for {$fqdn}, expires {$expiresAt}");

        } catch (\Throwable $e) {
            $cert->update(['status' => 'failed', 'failure_reason' => $e->getMessage()]);
            Log::error("AcmeService: Certificate issuance failed for {$fqdn}: {$e->getMessage()}");
            throw $e;
        } finally {
            // Clean up TXT records regardless of success/failure
            foreach ($addedTxtRecords ?? [] as $txtName) {
                try {
                    $this->godaddy->deleteRecordsByTypeAndName($domain, 'TXT', $txtName);
                    Log::info("AcmeService: Cleaned up TXT record {$txtName}");
                } catch (\Throwable $cleanupErr) {
                    Log::warning("AcmeService: Failed to clean up TXT record {$txtName}: {$cleanupErr->getMessage()}");
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

        // Re-issue using same fqdn/domain
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
     * Revoke a certificate.
     */
    public function revokeCertificate(SslCertificate $cert): bool
    {
        // Mark as revoked in DB (ACME revocation requires the cert PEM — optional)
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

    /**
     * Get or create ACME account key for a domain.
     * Looks for an existing key stored on any certificate for this domain.
     */
    private function getOrCreateAccountKey(string $domain): string
    {
        // Try to reuse existing account key from a previous cert on this domain
        $existing = SslCertificate::where('domain', $domain)
            ->whereNotNull('acme_account_key')
            ->value('acme_account_key');

        if ($existing) {
            return $existing;
        }

        // Generate new RSA account key
        $key = openssl_pkey_new([
            'private_key_bits' => 4096,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        openssl_pkey_export($key, $privateKeyPem);

        return $privateKeyPem;
    }
}
