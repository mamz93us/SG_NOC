<?php

namespace App\Services\Dns;

use App\Models\SslCertificate;

class CertificateExportService
{
    /**
     * Export as PEM fullchain.
     */
    public function exportPem(SslCertificate $cert): array
    {
        return [
            'content'  => $cert->certificate,
            'filename' => "{$cert->fqdn}.pem",
            'mime'     => 'application/x-pem-file',
        ];
    }

    /**
     * Export as CER (DER-encoded, base64).
     */
    public function exportCer(SslCertificate $cert): array
    {
        $pem     = $cert->certificate;
        $lines   = explode("\n", $pem);
        $cerBody = '';

        $inCert = false;
        foreach ($lines as $line) {
            if (str_contains($line, 'BEGIN CERTIFICATE')) { $inCert = true; continue; }
            if (str_contains($line, 'END CERTIFICATE'))  { break; }
            if ($inCert) $cerBody .= $line;
        }

        $der = base64_decode($cerBody);

        return [
            'content'  => $der,
            'filename' => "{$cert->fqdn}.cer",
            'mime'     => 'application/pkix-cert',
        ];
    }

    /**
     * Export private key only.
     */
    public function exportKey(SslCertificate $cert): array
    {
        return [
            'content'  => $cert->private_key,
            'filename' => "{$cert->fqdn}.key",
            'mime'     => 'application/x-pem-file',
        ];
    }

    /**
     * Export as PKCS#12 bundle (.p12).
     */
    public function exportP12(SslCertificate $cert, string $password = ''): array
    {
        if (!extension_loaded('openssl')) {
            throw new \RuntimeException('OpenSSL PHP extension is required for P12 export.');
        }

        $x509 = openssl_x509_read($cert->certificate);
        $pkey = openssl_pkey_get_private($cert->private_key);

        if (!$x509 || !$pkey) {
            throw new \RuntimeException('Failed to read certificate or private key for P12 export.');
        }

        $p12 = '';
        openssl_pkcs12_export($x509, $p12, $pkey, $password ?: '', [
            'friendly_name' => $cert->fqdn,
        ]);

        if (empty($p12)) {
            throw new \RuntimeException('Failed to generate PKCS#12 bundle.');
        }

        return [
            'content'  => $p12,
            'filename' => "{$cert->fqdn}.p12",
            'mime'     => 'application/x-pkcs12',
        ];
    }

    /**
     * Export all formats as a ZIP bundle.
     */
    public function exportBundle(SslCertificate $cert, string $p12Password = ''): array
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('ZipArchive PHP extension is required for bundle export.');
        }

        $tmpPath = sys_get_temp_dir() . '/' . $cert->fqdn . '-bundle-' . time() . '.zip';
        $zip     = new \ZipArchive();

        if ($zip->open($tmpPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Failed to create ZIP archive.');
        }

        $pem = $this->exportPem($cert);
        $zip->addFromString($pem['filename'], $pem['content']);

        $key = $this->exportKey($cert);
        $zip->addFromString($key['filename'], $key['content']);

        try {
            $cer = $this->exportCer($cert);
            $zip->addFromString($cer['filename'], $cer['content']);
        } catch (\Throwable) {}

        try {
            $p12 = $this->exportP12($cert, $p12Password);
            $zip->addFromString($p12['filename'], $p12['content']);
        } catch (\Throwable) {}

        $zip->close();

        return [
            'path'     => $tmpPath,
            'filename' => "{$cert->fqdn}-bundle.zip",
            'mime'     => 'application/zip',
        ];
    }
}
