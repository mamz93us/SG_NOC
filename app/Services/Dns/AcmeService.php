<?php

namespace App\Services\Dns;

use App\Models\ActivityLog;
use App\Models\DnsAccount;
use App\Models\SslCertificate;
use App\Models\SubdomainRecord;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * ACME v2 DNS-01 client — no external library required.
 * Uses PHP OpenSSL + Laravel Http facade.
 */
class AcmeService
{
    private const DIRECTORY_LIVE    = 'https://acme-v02.api.letsencrypt.org/directory';
    private const DIRECTORY_STAGING = 'https://acme-staging-v02.api.letsencrypt.org/directory';

    private array   $directory    = [];
    private ?string $accountUrl   = null;
    private ?string $lastLocation = null;
    private mixed   $privateKey   = null; // OpenSSL key resource

    public function __construct(private GoDaddyService $godaddy) {}

    // ─── Public API ───────────────────────────────────────────────────────

    public function issueCertificate(
        DnsAccount $account,
        string $fqdn,
        string $domain,
        ?User $user = null
    ): SslCertificate {
        $userId = $user?->id ?? auth()->id();

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

        $addedTxtRecords = [];

        try {
            $directoryUrl = config('acme.use_staging', false)
                ? self::DIRECTORY_STAGING
                : self::DIRECTORY_LIVE;

            // 1. Load ACME directory (endpoint URLs)
            $this->loadDirectory($directoryUrl);

            // 2. Load or generate RSA account key
            $accountKeyPem    = $this->loadOrCreateAccountKey($domain);
            $this->privateKey = openssl_pkey_get_private($accountKeyPem);
            if (!$this->privateKey) {
                throw new \RuntimeException('Failed to load RSA account key');
            }

            // 3. Register or retrieve ACME account
            $email            = config('acme.email', 'admin@example.com');
            $this->accountUrl = $this->registerAccount($email);
            Log::info("AcmeService: Account: {$this->accountUrl}");

            // 4. Create ACME order
            $orderData = $this->postKid($this->directory['newOrder'], [
                'identifiers' => [['type' => 'dns', 'value' => $fqdn]],
            ]);
            $orderUrl = $this->lastLocation;
            Log::info("AcmeService: Order created: {$orderUrl}");

            // 5. Collect authorizations and add DNS TXT records
            foreach ($orderData['authorizations'] as $authUrl) {
                $auth      = Http::get($authUrl)->json();
                $challenge = collect($auth['challenges'])->firstWhere('type', 'dns-01');

                if (!$challenge) {
                    throw new \RuntimeException("No DNS-01 challenge available for {$fqdn}");
                }

                // Compute the TXT record digest
                $token      = $challenge['token'];
                $keyAuth    = $token . '.' . $this->jwkThumbprint();
                $dnsValue   = $this->b64u(hash('sha256', $keyAuth, true));

                // _acme-challenge.{subdomain} for subdomains, _acme-challenge for root
                $subPart = rtrim(str_replace('.' . $domain, '', $fqdn), '.');
                $txtName = $subPart ? "_acme-challenge.{$subPart}" : '_acme-challenge';

                Log::info("AcmeService: Adding TXT {$txtName}.{$domain} = {$dnsValue}");

                $this->godaddy->addRecords($domain, [[
                    'type' => 'TXT',
                    'name' => $txtName,
                    'data' => $dnsValue,
                    'ttl'  => 600,
                ]]);
                $addedTxtRecords[] = $txtName;
            }

            // 6. Wait for DNS to propagate
            $wait = (int) config('acme.dns_propagation_wait', 60);
            Log::info("AcmeService: Waiting {$wait}s for DNS propagation...");
            sleep($wait);

            // 7. Signal ACME that challenges are ready
            foreach ($orderData['authorizations'] as $authUrl) {
                $auth      = Http::get($authUrl)->json();
                $challenge = collect($auth['challenges'])->firstWhere('type', 'dns-01');

                if ($challenge && in_array($challenge['status'] ?? '', ['pending', 'processing'])) {
                    $this->postKid($challenge['url'], new \stdClass()); // empty {} payload
                    Log::info("AcmeService: Signalled challenge ready for {$authUrl}");
                }
            }

            // 8. Poll order until ready
            $retries     = (int) config('acme.dns_poll_retries', 12);
            $orderStatus = [];
            for ($i = 0; $i < $retries; $i++) {
                $orderStatus = Http::get($orderUrl)->json();
                $s = $orderStatus['status'] ?? '';
                Log::info("AcmeService: Order status [{$i}] = {$s}");
                if ($s === 'ready') break;
                if ($s === 'invalid') {
                    throw new \RuntimeException('ACME order became invalid: ' . json_encode($orderStatus['error'] ?? $orderStatus));
                }
                sleep(10);
            }

            if (($orderStatus['status'] ?? '') !== 'ready') {
                throw new \RuntimeException('Order not ready after timeout. Last status: ' . ($orderStatus['status'] ?? 'unknown'));
            }

            // 9. Generate certificate key + CSR
            [$certKeyPem, $csrPem] = $this->generateCsr($fqdn);

            // 10. Finalize the order
            $csrDer       = $this->pemToDer($csrPem);
            $finalizeData = $this->postKid($orderStatus['finalize'], ['csr' => $this->b64u($csrDer)]);
            Log::info("AcmeService: Finalize sent, status=" . ($finalizeData['status'] ?? '?'));

            // 11. Poll until valid
            for ($i = 0; $i < $retries; $i++) {
                $orderStatus = Http::get($orderUrl)->json();
                $s = $orderStatus['status'] ?? '';
                Log::info("AcmeService: Order status post-finalize [{$i}] = {$s}");
                if ($s === 'valid') break;
                if ($s === 'invalid') {
                    throw new \RuntimeException('Order invalid after finalize: ' . json_encode($orderStatus));
                }
                sleep(5);
            }

            if (($orderStatus['status'] ?? '') !== 'valid') {
                throw new \RuntimeException('Order did not become valid after finalization');
            }

            // 12. Download certificate chain (POST-as-GET)
            $certPem   = $this->postAsGet($orderStatus['certificate']);
            $expiresAt = $this->parseCertExpiry($certPem);

            $cert->update([
                'status'          => 'valid',
                'certificate'     => $certPem,
                'private_key'     => $certKeyPem,
                'csr'             => $csrPem,
                'acme_account_key'=> $accountKeyPem,
                'issued_at'       => now(),
                'expires_at'      => $expiresAt,
                'failure_reason'  => null,
            ]);

            SubdomainRecord::where('account_id', $account->id)
                ->where('fqdn', $fqdn)
                ->update(['ssl_certificate_id' => $cert->id]);

            ActivityLog::create([
                'model_type' => 'DnsAccount',
                'model_id'   => $account->id,
                'action'     => 'certificate.issued',
                'changes'    => ['fqdn' => $fqdn, 'expires_at' => $expiresAt],
                'user_id'    => $userId,
            ]);

            Log::info("AcmeService: Certificate issued for {$fqdn}, expires {$expiresAt}");

        } catch (\Throwable $e) {
            $cert->update(['status' => 'failed', 'failure_reason' => $e->getMessage()]);
            Log::error("AcmeService: Failed for {$fqdn}: {$e->getMessage()}");
            throw $e;
        } finally {
            foreach ($addedTxtRecords as $txtName) {
                try {
                    $this->godaddy->deleteRecordsByTypeAndName($domain, 'TXT', $txtName);
                    Log::info("AcmeService: Cleaned TXT {$txtName}");
                } catch (\Throwable $ce) {
                    Log::warning("AcmeService: Could not clean TXT {$txtName}: {$ce->getMessage()}");
                }
            }
        }

        return $cert->fresh();
    }

    public function renewCertificate(SslCertificate $cert, DnsAccount $account): SslCertificate
    {
        $oldExpiry = $cert->expires_at;
        $renewed   = $this->issueCertificate($account, $cert->fqdn, $cert->domain);

        $renewed->update([
            'last_renewed_at' => now(),
            'auto_renew'      => $cert->auto_renew,
        ]);

        ActivityLog::create([
            'model_type' => 'DnsAccount',
            'model_id'   => $account->id,
            'action'     => 'certificate.renewed',
            'changes'    => ['fqdn' => $cert->fqdn, 'old_expires' => $oldExpiry, 'new_expires' => $renewed->expires_at],
            'user_id'    => auth()->id(),
        ]);

        return $renewed;
    }

    public function revokeCertificate(SslCertificate $cert): bool
    {
        $cert->update(['status' => 'revoked']);

        ActivityLog::create([
            'model_type' => 'DnsAccount',
            'model_id'   => $cert->account_id,
            'action'     => 'certificate.revoked',
            'changes'    => ['fqdn' => $cert->fqdn],
            'user_id'    => auth()->id(),
        ]);

        return true;
    }

    // ─── ACME Protocol Helpers ────────────────────────────────────────────

    private function loadDirectory(string $url): void
    {
        $res = Http::get($url);
        $this->directory = $res->json() ?? [];

        if (empty($this->directory['newAccount'])) {
            throw new \RuntimeException("Failed to load ACME directory from {$url}: " . $res->body());
        }
    }

    private function getNonce(): string
    {
        $res   = Http::head($this->directory['newNonce']);
        $nonce = $res->header('Replay-Nonce');

        if (!$nonce) {
            // Fall back to GET if HEAD not supported
            $res   = Http::get($this->directory['newNonce']);
            $nonce = $res->header('Replay-Nonce');
        }

        if (!$nonce) {
            throw new \RuntimeException('Could not obtain ACME nonce');
        }

        return $nonce;
    }

    private function registerAccount(string $email): string
    {
        $data = $this->postJwk($this->directory['newAccount'], [
            'termsOfServiceAgreed' => true,
            'contact'              => ['mailto:' . $email],
        ]);

        $url = $this->lastLocation;
        if (!$url) {
            throw new \RuntimeException('No account URL returned from ACME newAccount endpoint');
        }

        return $url;
    }

    private function postJwk(string $url, array $payload): array
    {
        return $this->sendJws($url, $payload, false);
    }

    private function postKid(string $url, mixed $payload): array
    {
        return $this->sendJws($url, $payload, true);
    }

    /** POST-as-GET to download certificate PEM chain */
    private function postAsGet(string $url): string
    {
        $nonce  = $this->getNonce();
        $header = [
            'alg'   => 'RS256',
            'kid'   => $this->accountUrl,
            'nonce' => $nonce,
            'url'   => $url,
        ];

        $protectedB64 = $this->b64u(json_encode($header));
        $payloadB64   = ''; // empty string = POST-as-GET per RFC 8555

        openssl_sign($protectedB64 . '.' . $payloadB64, $sig, $this->privateKey, OPENSSL_ALGO_SHA256);

        $res = Http::withHeaders([
            'Content-Type' => 'application/jose+json',
            'Accept'       => 'application/pem-certificate-chain',
        ])->post($url, [
            'protected' => $protectedB64,
            'payload'   => $payloadB64,
            'signature' => $this->b64u($sig),
        ]);

        if ($res->failed()) {
            throw new \RuntimeException("Failed to download certificate: " . $res->body());
        }

        return (string) $res->body();
    }

    private function sendJws(string $url, mixed $payload, bool $useKid): array
    {
        $nonce  = $this->getNonce();
        $header = ['alg' => 'RS256', 'nonce' => $nonce, 'url' => $url];

        if ($useKid) {
            $header['kid'] = $this->accountUrl;
        } else {
            $header['jwk'] = $this->jwk();
        }

        $protectedB64 = $this->b64u(json_encode($header));

        if ($payload instanceof \stdClass) {
            $payloadB64 = $this->b64u('{}');
        } elseif ($payload === null) {
            $payloadB64 = '';
        } else {
            $payloadB64 = $this->b64u(json_encode($payload));
        }

        openssl_sign($protectedB64 . '.' . $payloadB64, $sig, $this->privateKey, OPENSSL_ALGO_SHA256);

        $res = Http::withHeaders([
            'Content-Type' => 'application/jose+json',
            'Accept'       => 'application/json',
        ])->post($url, [
            'protected' => $protectedB64,
            'payload'   => $payloadB64,
            'signature' => $this->b64u($sig),
        ]);

        $this->lastLocation = $res->header('Location');

        if ($res->status() >= 400) {
            $err = $res->json() ?? [];
            throw new \RuntimeException(
                'ACME ' . $res->status() . ': ' . ($err['detail'] ?? ($err['type'] ?? $res->body()))
            );
        }

        return $res->json() ?? [];
    }

    // ─── Crypto Helpers ──────────────────────────────────────────────────

    private function jwk(): array
    {
        $details = openssl_pkey_get_details($this->privateKey);
        return [
            'e'   => $this->b64u($details['rsa']['e']),
            'kty' => 'RSA',
            'n'   => $this->b64u($details['rsa']['n']),
        ];
    }

    private function jwkThumbprint(): string
    {
        $jwk = $this->jwk();
        // RFC 7638: alphabetically sorted keys, compact JSON
        $canonical = json_encode(['e' => $jwk['e'], 'kty' => $jwk['kty'], 'n' => $jwk['n']]);
        return $this->b64u(hash('sha256', $canonical, true));
    }

    private function b64u(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function loadOrCreateAccountKey(string $domain): string
    {
        $existing = SslCertificate::where('domain', $domain)
            ->whereNotNull('acme_account_key')
            ->value('acme_account_key');

        if ($existing) {
            Log::info("AcmeService: Reusing stored account key for {$domain}");
            return $existing;
        }

        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $pem);
        Log::info("AcmeService: Generated new account key for {$domain}");
        return $pem;
    }

    private function generateCsr(string $fqdn): array
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['CN' => $fqdn], $key, ['digest_alg' => 'sha256']);

        openssl_pkey_export($key, $keyPem);
        openssl_csr_export($csr, $csrPem);

        return [$keyPem, $csrPem];
    }

    private function pemToDer(string $pem): string
    {
        $pem = preg_replace('/-----[^-]+-----/', '', $pem);
        $pem = preg_replace('/\s+/', '', $pem);
        return base64_decode($pem);
    }

    private function parseCertExpiry(string $certPem): ?string
    {
        if (preg_match('/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s', $certPem, $m)) {
            $data = openssl_x509_parse($m[0]);
            if ($data && isset($data['validTo_time_t'])) {
                return date('Y-m-d H:i:s', $data['validTo_time_t']);
            }
        }
        return null;
    }
}
