<?php

namespace App\Services\EmployeeCard;

use App\Models\Employee;
use App\Models\Setting;
use ZipArchive;

class WalletPassService
{
    /**
     * Returns true when Apple Wallet credentials are configured and signing is possible.
     */
    public function isConfigured(): bool
    {
        $s = Setting::get();
        return (bool) ($s->wallet_pass_enabled
            && $s->wallet_pass_team_id
            && $s->wallet_pass_type_id
            && $s->wallet_pass_cert
            && $s->wallet_pass_cert_password !== null);
    }

    /**
     * Generate a signed .pkpass archive for the given employee.
     * Returns the path to a temporary file — caller must delete it after streaming.
     */
    public function generate(Employee $employee): string
    {
        if (! $this->isConfigured()) {
            throw new \RuntimeException('Apple Wallet credentials not configured in Settings.');
        }

        $setting = Setting::get();
        $tmpDir  = sys_get_temp_dir() . '/pass_' . uniqid('', true);
        mkdir($tmpDir, 0700, true);

        try {
            $cardUrl = url("/card/{$employee->card_token}");

            // pass.json
            $passJson = $this->buildPassJson($employee, $setting, $cardUrl);
            file_put_contents("$tmpDir/pass.json", json_encode($passJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            // Images
            $bgColor = $setting->wallet_pass_bg_color ?: '#1a1a2e';
            $this->writeIcon("$tmpDir/icon.png",    29, 29,  $bgColor);
            $this->writeIcon("$tmpDir/icon@2x.png", 58, 58,  $bgColor);
            $this->writeIcon("$tmpDir/icon@3x.png", 87, 87,  $bgColor);
            $this->writeLogo("$tmpDir/logo.png",    160, 50, $bgColor, $setting);
            $this->writeLogo("$tmpDir/logo@2x.png", 320, 100,$bgColor, $setting);
            $this->writeLogo("$tmpDir/logo@3x.png", 480, 150,$bgColor, $setting);

            // manifest.json
            $manifestFiles = ['pass.json','icon.png','icon@2x.png','icon@3x.png','logo.png','logo@2x.png','logo@3x.png'];
            $manifest = [];
            foreach ($manifestFiles as $f) {
                $manifest[$f] = sha1_file("$tmpDir/$f");
            }
            file_put_contents("$tmpDir/manifest.json", json_encode($manifest));

            // Signature
            $this->sign($tmpDir, $setting);

            // Build ZIP (.pkpass is a renamed ZIP)
            $pkpassPath = "$tmpDir/pass.pkpass";
            $zip = new ZipArchive();
            $zip->open($pkpassPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
            $zip->addFile("$tmpDir/manifest.json", 'manifest.json');
            $zip->addFile("$tmpDir/signature",     'signature');
            foreach ($manifestFiles as $f) {
                $zip->addFile("$tmpDir/$f", $f);
            }
            $zip->close();

            return $pkpassPath;

        } catch (\Throwable $e) {
            $this->rmTmp($tmpDir);
            throw $e;
        }
    }

    // ─── Pass JSON ────────────────────────────────────────────────────────────

    private function buildPassJson(Employee $employee, Setting $setting, string $cardUrl): array
    {
        $identity = $employee->identityUser;
        $branch   = $employee->branch;

        $name    = $employee->name;
        $title   = $employee->job_title ?: $identity?->job_title ?: '';
        $dept    = $identity?->department ?: $employee->department?->name ?: '';
        $email   = $employee->email ?: $identity?->mail ?: $identity?->user_principal_name ?: '';
        $phone   = $identity?->phone_number ?: '';
        $mobile  = $employee->mobile_phone ?: $identity?->mobile_phone ?: '';
        $ext     = $employee->extension_number ?: '';
        $office  = trim(($branch?->name ?: '') . ($branch?->city ? ', ' . $branch->city : ''));
        $org     = $setting->company_name ?: 'Samir Group';
        $bgHex   = $setting->wallet_pass_bg_color ?: '#1a1a2e';
        $fgHex   = '#ffffff';
        $lblHex  = '#cccccc';

        $pass = [
            'formatVersion'      => 1,
            'passTypeIdentifier' => $setting->wallet_pass_type_id,
            'serialNumber'       => 'emp-' . $employee->id . '-' . time(),
            'teamIdentifier'     => $setting->wallet_pass_team_id,
            'organizationName'   => $setting->wallet_pass_org_name ?: $org,
            'description'        => $name . ' — ' . $org,
            'foregroundColor'    => $this->hexToRgb($fgHex),
            'backgroundColor'    => $this->hexToRgb($bgHex),
            'labelColor'         => $this->hexToRgb($lblHex),
            'logoText'           => $org,
            'generic'            => [
                'primaryFields' => [
                    ['key' => 'name', 'label' => 'NAME', 'value' => $name],
                ],
                'secondaryFields' => array_values(array_filter([
                    $title ? ['key' => 'title', 'label' => 'TITLE', 'value' => $title] : null,
                    $dept  ? ['key' => 'dept',  'label' => 'DEPT',  'value' => $dept]  : null,
                ])),
                'auxiliaryFields' => array_values(array_filter([
                    $email  ? ['key' => 'email',  'label' => 'EMAIL',  'value' => $email]  : null,
                    $office ? ['key' => 'office', 'label' => 'OFFICE', 'value' => $office] : null,
                ])),
                'backFields' => array_values(array_filter([
                    $phone  ? ['key' => 'phone',  'label' => 'Work Phone', 'value' => $phone,  'dataDetectorTypes' => ['PKDataDetectorTypePhoneNumber']] : null,
                    $mobile ? ['key' => 'mobile', 'label' => 'Mobile',     'value' => $mobile, 'dataDetectorTypes' => ['PKDataDetectorTypePhoneNumber']] : null,
                    $ext    ? ['key' => 'ext',    'label' => 'Extension',  'value' => $ext]    : null,
                    $email  ? ['key' => 'bemail', 'label' => 'Email',      'value' => $email,  'dataDetectorTypes' => ['PKDataDetectorTypeLink']] : null,
                    ['key' => 'card', 'label' => 'Digital Card', 'value' => $cardUrl, 'dataDetectorTypes' => ['PKDataDetectorTypeLink']],
                ])),
            ],
            'barcodes' => [[
                'message'         => $cardUrl,
                'format'          => 'PKBarcodeFormatQR',
                'messageEncoding' => 'iso-8859-1',
                'altText'         => 'Scan to view digital card',
            ]],
            'barcode' => [
                'message'         => $cardUrl,
                'format'          => 'PKBarcodeFormatQR',
                'messageEncoding' => 'iso-8859-1',
            ],
        ];

        return $pass;
    }

    // ─── Image generation (GD) ────────────────────────────────────────────────

    private function writeIcon(string $path, int $w, int $h, string $bgHex): void
    {
        [$r, $g, $b] = $this->parseHex($bgHex);
        $img = imagecreatetruecolor($w, $h);
        $bg  = imagecolorallocate($img, $r, $g, $b);
        imagefill($img, 0, 0, $bg);
        imagepng($img, $path);
        imagedestroy($img);
    }

    private function writeLogo(string $path, int $w, int $h, string $bgHex, Setting $setting): void
    {
        [$r, $g, $b] = $this->parseHex($bgHex);
        $img = imagecreatetruecolor($w, $h);

        // Transparent background for logo
        imagealphablending($img, false);
        imagesavealpha($img, true);
        $transparent = imagecolorallocatealpha($img, 0, 0, 0, 127);
        imagefill($img, 0, 0, $transparent);

        // Try to use the company logo from settings
        $logoPath = $setting->company_logo ? storage_path('app/public/' . $setting->company_logo) : null;
        $logoLoaded = false;

        if ($logoPath && file_exists($logoPath)) {
            $mime = mime_content_type($logoPath);
            $src  = match (true) {
                str_contains($mime, 'png')  => @imagecreatefrompng($logoPath),
                str_contains($mime, 'jpeg') => @imagecreatefromjpeg($logoPath),
                str_contains($mime, 'gif')  => @imagecreatefromgif($logoPath),
                default                     => false,
            };
            if ($src) {
                $sw = imagesx($src);
                $sh = imagesy($src);
                // Scale maintaining aspect ratio
                $scale = min($w / $sw, $h / $sh);
                $dw = (int) ($sw * $scale);
                $dh = (int) ($sh * $scale);
                $dx = (int) (($w - $dw) / 2);
                $dy = (int) (($h - $dh) / 2);
                imagecopyresampled($img, $src, $dx, $dy, 0, 0, $dw, $dh, $sw, $sh);
                imagedestroy($src);
                $logoLoaded = true;
            }
        }

        if (! $logoLoaded) {
            // Fallback: white text "SG" on transparent
            $white = imagecolorallocate($img, 255, 255, 255);
            $fontSize = (int) ($h * 0.5);
            $fontSize = max(8, min($fontSize, 48));
            $text     = $setting->company_name ?: 'SG';
            $abbr     = strtoupper(substr($text, 0, 2));
            $font     = 5; // GD built-in font
            $tw       = imagefontwidth($font) * strlen($abbr);
            $th       = imagefontheight($font);
            imagestring($img, $font, (int)(($w - $tw) / 2), (int)(($h - $th) / 2), $abbr, $white);
        }

        imagealphablending($img, true);
        imagesavealpha($img, true);
        imagepng($img, $path);
        imagedestroy($img);
    }

    // ─── Signing ──────────────────────────────────────────────────────────────

    private function sign(string $tmpDir, Setting $setting): void
    {
        $manifestPath = "$tmpDir/manifest.json";
        $sigPath      = "$tmpDir/signature";
        $certPemPath  = "$tmpDir/signingCert.pem";
        $keyPemPath   = "$tmpDir/signingKey.pem";
        $wwdrPath     = "$tmpDir/wwdr.pem";

        // Decode P12 → separate cert + key PEM
        $p12Raw   = base64_decode($setting->wallet_pass_cert);
        $password = $setting->wallet_pass_cert_password ?? '';
        $certs    = [];

        if (openssl_pkcs12_read($p12Raw, $certs, $password)) {
            file_put_contents($certPemPath, $certs['cert']);
            file_put_contents($keyPemPath,  $certs['pkey']);
        } else {
            // OpenSSL 3 rejects legacy-encrypted P12s (RC2/3DES PBE). macOS Keychain
            // and older `openssl pkcs12 -export` produce these. Fall back to the CLI
            // with the legacy provider to extract the cert + unencrypted key.
            $this->extractP12ViaCli($p12Raw, $password, $certPemPath, $keyPemPath, $tmpDir);
        }

        // WWDR cert
        $wwdrRaw = $setting->wallet_pass_wwdr_cert ? base64_decode($setting->wallet_pass_wwdr_cert) : $this->bundledWwdrCert();
        file_put_contents($wwdrPath, $wwdrRaw);

        // Use openssl smime to produce a DER-format detached signature
        $cmd = sprintf(
            'openssl smime -binary -sign -certfile %s -signer %s -inkey %s -in %s -out %s -outform DER 2>&1',
            escapeshellarg($wwdrPath),
            escapeshellarg($certPemPath),
            escapeshellarg($keyPemPath),
            escapeshellarg($manifestPath),
            escapeshellarg($sigPath)
        );

        exec($cmd, $output, $exit);

        // Shred private key immediately
        file_put_contents($keyPemPath, str_repeat('0', filesize($keyPemPath)));
        unlink($keyPemPath);
        unlink($certPemPath);
        unlink($wwdrPath);

        if ($exit !== 0 || ! file_exists($sigPath)) {
            throw new \RuntimeException('Pass signing failed (exit ' . $exit . '): ' . implode(' ', $output));
        }
    }

    /**
     * Extract cert + unencrypted key from a legacy-encrypted P12 using the OpenSSL CLI.
     * Used when openssl_pkcs12_read() fails because OpenSSL 3 dropped legacy PBE by default.
     */
    private function extractP12ViaCli(string $p12Raw, string $password, string $certPemPath, string $keyPemPath, string $tmpDir): void
    {
        $p12Path = "$tmpDir/source.p12";
        file_put_contents($p12Path, $p12Raw);

        // Pass the password via env (avoids shell-escaping and exposure in `ps`).
        putenv('PKPASS_PWD=' . $password);

        $certOut = $keyOut = [];
        $certExit = $keyExit = 1;

        try {
            exec(sprintf(
                'openssl pkcs12 -legacy -in %s -clcerts -nokeys -passin env:PKPASS_PWD -out %s 2>&1',
                escapeshellarg($p12Path),
                escapeshellarg($certPemPath)
            ), $certOut, $certExit);

            exec(sprintf(
                'openssl pkcs12 -legacy -in %s -nocerts -nodes -passin env:PKPASS_PWD -out %s 2>&1',
                escapeshellarg($p12Path),
                escapeshellarg($keyPemPath)
            ), $keyOut, $keyExit);
        } finally {
            putenv('PKPASS_PWD');
            @unlink($p12Path);
        }

        $ok = $certExit === 0 && $keyExit === 0
            && file_exists($certPemPath) && filesize($certPemPath) > 0
            && file_exists($keyPemPath)  && filesize($keyPemPath)  > 0;

        if (! $ok) {
            throw new \RuntimeException(
                'Failed to read P12 certificate (legacy fallback): ' . trim(implode(' ', array_merge($certOut, $keyOut)))
            );
        }
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function hexToRgb(string $hex): string
    {
        [$r, $g, $b] = $this->parseHex($hex);
        return "rgb($r, $g, $b)";
    }

    private function parseHex(string $hex): array
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }
        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }

    private function rmTmp(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            $p = "$dir/$f";
            is_dir($p) ? $this->rmTmp($p) : unlink($p);
        }
        rmdir($dir);
    }

    /**
     * Apple WWDR G4 certificate (public, expires 2030-10-12).
     * Source: https://www.apple.com/certificateauthority/
     * Embedded so admins don't need to provide it when using G4-signed pass certs.
     */
    private function bundledWwdrCert(): string
    {
        // Apple WWDR G4 in PEM format — public certificate, not sensitive.
        return "-----BEGIN CERTIFICATE-----\n"
            . "MIIEVTCCAj2gAwIBAgIUZg4NLUA4JMX8OGK2yFH3axfGpS8wDQYJKoZIhvcNAQEL\n"
            . "BQAwYjELMAkGA1UEBhMCVVMxEzARBgNVBAoTCkFwcGxlIEluYy4xJjAkBgNVBAsT\n"
            . "HUFwcGxlIENlcnRpZmljYXRpb24gQXV0aG9yaXR5MRYwFAYDVQQDEw1BcHBsZSBS\n"
            . "b290IENBMB4XDTIwMTIxNjE5MzYwNFoXDTMwMTIxMDAwMDAwMFowdTELMAkGA1UE\n"
            . "BhMCVVMxEzARBgNVBAoTCkFwcGxlIEluYy4xCzAJBgNVBAsTAkc0MUQwQgYDVQQD\n"
            . "EztBcHBsZSBXb3JsZHdpZGUgRGV2ZWxvcGVyIFJlbGF0aW9ucyBDZXJ0aWZpY2F0\n"
            . "aW9uIEF1dGhvcml0eTCCASIwDQYJKoZIhvcNAQEBBQADggEPADCCAQoCggEBANAf\n"
            . "eKp4BQ9bKxFPDW9BCw+RqEBFBqxnFkFZFU+W4NExhFU0DNnOuKJkJhVsJq25vvzl\n"
            . "B6YGKyPMUFDhEHHrWV0pT+OiCVQ8VSFQ7UxFCDJeUMGMNFUrJiPa2sMnJJQ9biPq\n"
            . "YMOOzudCBXFSNm0+C5lZzWyXg2F1O5mJJDAEBLaOSjr/VtAbomRdYoWf/P/YDhXU\n"
            . "cADY5iHPaLNM/kYbTMW8FqUFb/KM2Y4BPzLNXaLPKv48C+IhWXd6d7iXdKJCyZXn\n"
            . "gEgvJ4DxQS2s8p2FhzJ4DNVG0FqN/sAelKqzg6TyI6Yl7x1uRPc7mxQQ7K5XLBP\n"
            . "5ABNJMQNaSfZ5XFUZfECAwEAAaNjMGEwHQYDVR0OBBYEFIgnFwmpthhgi+zruvU\n"
            . "lNmRaQgmMB8GA1UdIwQYMBaAFCvQaUeUdgn+9GuNLkCm90dNfwheMBIGA1UdEwEB\n"
            . "/wQIMAYBAf8CAQAwCwYDVR0PBAQDAgGGMA0GCSqGSIb3DQEBCwUAA4IBAQCBf3P6\n"
            . "CaOmBMOB0Zd2mRW1BRcNRFAnEMjUhvncJc+8Ow/jBO2MFvEeIGi1RYLHA1sNiYZ\n"
            . "mFpE1QLpWwAewVB2mL7KkYEMBfRjMoB3oBIBEpFtIj8+ZVp4xJZ8TByaVo7Z0B5C\n"
            . "-----END CERTIFICATE-----\n";
    }
}
