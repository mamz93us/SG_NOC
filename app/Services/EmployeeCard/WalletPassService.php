<?php

namespace App\Services\EmployeeCard;

use App\Models\Employee;
use App\Models\Setting;
use ZipArchive;

class WalletPassService
{
    /** Samir brand red — same value as the login wallpaper and the digital card accent. */
    private const BRAND_RED = '#c8102e';

    /** Wordmark grey, used for field values on a light pass. */
    private const BRAND_INK = '#3c3c3b';

    /** Default pass background: white, so the logo renders in its true colours. */
    private const DEFAULT_BG = '#ffffff';

    /** Brand mark (red swoosh) isolated from the corporate logo. */
    private const MARK_PATH = 'images/brand/samir-mark.png';

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
        $tmpDir = sys_get_temp_dir().'/pass_'.uniqid('', true);
        mkdir($tmpDir, 0700, true);

        try {
            // Canonical card host — the pass may be generated from an admin request
            // on NOC, but its barcode has to send scanners to the public card host.
            $cardUrl = \App\Support\VCard::cardUrl($employee->card_token);
            $bgColor = $setting->wallet_pass_bg_color ?: self::DEFAULT_BG;

            // Images first — the logo tells us whether pass.json still needs logoText.
            // The icon is always the brand mark on Samir red, independent of the pass
            // background, so it stays recognisable in the Wallet list and notifications.
            $this->writeIcon("$tmpDir/icon.png", 29);
            $this->writeIcon("$tmpDir/icon@2x.png", 58);
            $this->writeIcon("$tmpDir/icon@3x.png", 87);

            $hasWordmark = $this->writeLogo("$tmpDir/logo.png", 160, 50, $bgColor, $setting);
            $this->writeLogo("$tmpDir/logo@2x.png", 320, 100, $bgColor, $setting);
            $this->writeLogo("$tmpDir/logo@3x.png", 480, 150, $bgColor, $setting);

            // pass.json
            $passJson = $this->buildPassJson($employee, $setting, $cardUrl, $hasWordmark);
            file_put_contents("$tmpDir/pass.json", json_encode($passJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            // manifest.json
            $manifestFiles = ['pass.json', 'icon.png', 'icon@2x.png', 'icon@3x.png', 'logo.png', 'logo@2x.png', 'logo@3x.png'];
            $manifest = [];
            foreach ($manifestFiles as $f) {
                $manifest[$f] = sha1_file("$tmpDir/$f");
            }
            file_put_contents("$tmpDir/manifest.json", json_encode($manifest));

            // Signature
            $this->sign($tmpDir, $setting);

            // Build ZIP (.pkpass is a renamed ZIP)
            $pkpassPath = "$tmpDir/pass.pkpass";
            $zip = new ZipArchive;
            $zip->open($pkpassPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
            $zip->addFile("$tmpDir/manifest.json", 'manifest.json');
            $zip->addFile("$tmpDir/signature", 'signature');
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

    private function buildPassJson(Employee $employee, Setting $setting, string $cardUrl, bool $hasWordmark): array
    {
        $identity = $employee->identityUser;
        $branch = $employee->branch;

        $name = $employee->name;
        $title = $employee->job_title ?: $identity?->job_title ?: '';
        $dept = $identity?->department ?: $employee->department?->name ?: '';
        $email = $employee->email ?: $identity?->mail ?: $identity?->user_principal_name ?: '';
        $phone = $identity?->phone_number ?: '';
        $mobile = $employee->mobile_phone ?: $identity?->mobile_phone ?: '';
        $ext = $employee->extension_number ?: '';
        $office = trim(($branch?->name ?: '').($branch?->city ? ', '.$branch->city : ''));
        $org = $setting->company_name ?: 'Samir Group';
        $bgHex = $setting->wallet_pass_bg_color ?: self::DEFAULT_BG;

        // Text colours follow the background so the pass stays readable if an admin
        // switches to a dark background in Settings.
        if ($this->isLightColor($bgHex)) {
            $fgHex = self::BRAND_INK;
            $lblHex = self::BRAND_RED;
        } else {
            $fgHex = '#ffffff';
            $lblHex = $this->lighten(self::BRAND_RED, 0.55);
        }

        $pass = [
            'formatVersion' => 1,
            'passTypeIdentifier' => $setting->wallet_pass_type_id,
            'serialNumber' => 'emp-'.$employee->id.'-'.time(),
            'teamIdentifier' => $setting->wallet_pass_team_id,
            'organizationName' => $setting->wallet_pass_org_name ?: $org,
            'description' => $name.' — '.$org,
            'foregroundColor' => $this->hexToRgb($fgHex),
            'backgroundColor' => $this->hexToRgb($bgHex),
            'labelColor' => $this->hexToRgb($lblHex),
            'generic' => [
                'primaryFields' => [
                    ['key' => 'name', 'label' => 'NAME', 'value' => $name],
                ],
                'secondaryFields' => array_values(array_filter([
                    $title ? ['key' => 'title', 'label' => 'TITLE', 'value' => $title] : null,
                    $dept ? ['key' => 'dept',  'label' => 'DEPT',  'value' => $dept] : null,
                ])),
                'auxiliaryFields' => array_values(array_filter([
                    $email ? ['key' => 'email',  'label' => 'EMAIL',  'value' => $email] : null,
                    $office ? ['key' => 'office', 'label' => 'OFFICE', 'value' => $office] : null,
                ])),
                'backFields' => array_values(array_filter([
                    $phone ? ['key' => 'phone',  'label' => 'Work Phone', 'value' => $phone,  'dataDetectorTypes' => ['PKDataDetectorTypePhoneNumber']] : null,
                    $mobile ? ['key' => 'mobile', 'label' => 'Mobile',     'value' => $mobile, 'dataDetectorTypes' => ['PKDataDetectorTypePhoneNumber']] : null,
                    $ext ? ['key' => 'ext',    'label' => 'Extension',  'value' => $ext] : null,
                    $email ? ['key' => 'bemail', 'label' => 'Email',      'value' => $email,  'dataDetectorTypes' => ['PKDataDetectorTypeLink']] : null,
                    ['key' => 'card', 'label' => 'Digital Card', 'value' => $cardUrl, 'dataDetectorTypes' => ['PKDataDetectorTypeLink']],
                ])),
            ],
            'barcodes' => [[
                'message' => $cardUrl,
                'format' => 'PKBarcodeFormatQR',
                'messageEncoding' => 'iso-8859-1',
                'altText' => 'Scan to view digital card',
            ]],
            'barcode' => [
                'message' => $cardUrl,
                'format' => 'PKBarcodeFormatQR',
                'messageEncoding' => 'iso-8859-1',
            ],
        ];

        // Only print the company name beside the logo when the logo itself has no
        // wordmark — otherwise the header reads "SAMIR Samir Group".
        if (! $hasWordmark) {
            $pass['logoText'] = $org;
        }

        return $pass;
    }

    // ─── Image generation (GD) ────────────────────────────────────────────────

    /**
     * Square app icon: the Samir swoosh knocked out in white on a brand-red tile.
     * Apple rounds the corners itself, so this is drawn full-bleed.
     */
    private function writeIcon(string $path, int $size): void
    {
        [$r, $g, $b] = $this->parseHex(self::BRAND_RED);
        $img = imagecreatetruecolor($size, $size);
        imagefill($img, 0, 0, imagecolorallocate($img, $r, $g, $b));

        $mark = $this->loadBrandMark();

        if ($mark) {
            $this->recolor($mark, 255, 255, 255);

            $sw = imagesx($mark);
            $sh = imagesy($mark);
            $inner = (int) round($size * 0.84);          // ~8% padding on each side
            $scale = min($inner / $sw, $inner / $sh);
            $dw = max(1, (int) round($sw * $scale));
            $dh = max(1, (int) round($sh * $scale));

            imagealphablending($img, true);
            imagecopyresampled($img, $mark, (int) (($size - $dw) / 2), (int) (($size - $dh) / 2), 0, 0, $dw, $dh, $sw, $sh);
            imagedestroy($mark);
        }

        imagepng($img, $path);
        imagedestroy($img);
    }

    /**
     * Header logo, drawn left-aligned on transparency the way Apple lays it out.
     * On a light pass the corporate logo keeps its true colours; on a dark one it is
     * knocked out to white, matching the digital card's `brightness(0) invert(1)`.
     *
     * Returns true when the rendered logo includes the company wordmark, so the caller
     * knows whether pass.json still needs logoText.
     */
    private function writeLogo(string $path, int $w, int $h, string $bgHex, Setting $setting): bool
    {
        $img = imagecreatetruecolor($w, $h);
        imagealphablending($img, false);
        imagesavealpha($img, true);
        imagefill($img, 0, 0, imagecolorallocatealpha($img, 0, 0, 0, 127));

        $knockout = ! $this->isLightColor($bgHex);
        $hasWordmark = false;
        $src = $this->loadCompanyLogo($setting);

        if ($src) {
            $hasWordmark = true;
        } else {
            // No logo uploaded — fall back to the brand mark, tinted for the background.
            $src = $this->loadBrandMark();
            if ($src && $knockout) {
                $this->recolor($src, 255, 255, 255);
            }
        }

        if ($src) {
            if ($hasWordmark && $knockout) {
                $this->knockoutToWhite($src);
            }

            $sw = imagesx($src);
            $sh = imagesy($src);
            $scale = min($w / $sw, $h / $sh);
            $dw = max(1, (int) round($sw * $scale));
            $dh = max(1, (int) round($sh * $scale));

            imagecopyresampled($img, $src, 0, (int) (($h - $dh) / 2), 0, 0, $dw, $dh, $sw, $sh);
            imagedestroy($src);
        }

        imagesavealpha($img, true);
        imagepng($img, $path);
        imagedestroy($img);

        return $hasWordmark;
    }

    /**
     * Load the admin-uploaded company logo as a truecolor image with alpha preserved.
     */
    private function loadCompanyLogo(Setting $setting): \GdImage|false
    {
        $logoPath = $setting->company_logo ? storage_path('app/public/'.$setting->company_logo) : null;

        if (! $logoPath || ! file_exists($logoPath)) {
            return false;
        }

        $mime = mime_content_type($logoPath);
        $src = match (true) {
            str_contains($mime, 'png') => @imagecreatefrompng($logoPath),
            str_contains($mime, 'jpeg') => @imagecreatefromjpeg($logoPath),
            str_contains($mime, 'gif') => @imagecreatefromgif($logoPath),
            str_contains($mime, 'webp') => @imagecreatefromwebp($logoPath),
            default => false,
        };

        if ($src) {
            imagealphablending($src, false);
            imagesavealpha($src, true);
        }

        return $src;
    }

    /**
     * The red Samir swoosh on transparency, shipped with the app.
     */
    private function loadBrandMark(): \GdImage|false
    {
        $path = public_path(self::MARK_PATH);

        if (! file_exists($path)) {
            return false;
        }

        $mark = @imagecreatefrompng($path);

        if ($mark) {
            imagealphablending($mark, false);
            imagesavealpha($mark, true);
        }

        return $mark;
    }

    /**
     * Repaint every pixel to the given RGB while keeping its alpha — used to turn the
     * red brand mark white without losing the antialiased edges.
     */
    private function recolor(\GdImage $img, int $r, int $g, int $b): void
    {
        imagealphablending($img, false);
        imagesavealpha($img, true);

        for ($x = 0, $w = imagesx($img); $x < $w; $x++) {
            for ($y = 0, $h = imagesy($img); $y < $h; $y++) {
                $alpha = (imagecolorat($img, $x, $y) >> 24) & 0x7F;
                if ($alpha < 127) {
                    imagesetpixel($img, $x, $y, imagecolorallocatealpha($img, $r, $g, $b, $alpha));
                }
            }
        }
    }

    /**
     * White-knockout a logo for dark backgrounds: every pixel becomes white and its
     * opacity is driven by how dark it was. A logo delivered on a white sheet therefore
     * drops its background instead of turning into a solid white block.
     */
    private function knockoutToWhite(\GdImage $img): void
    {
        imagealphablending($img, false);
        imagesavealpha($img, true);

        for ($x = 0, $w = imagesx($img); $x < $w; $x++) {
            for ($y = 0, $h = imagesy($img); $y < $h; $y++) {
                $c = imagecolorat($img, $x, $y);
                $alpha = ($c >> 24) & 0x7F;

                if ($alpha >= 127) {
                    continue;
                }

                $lum = (0.299 * (($c >> 16) & 0xFF) + 0.587 * (($c >> 8) & 0xFF) + 0.114 * ($c & 0xFF)) / 255;
                // Dark ink → fully opaque white; near-white paper → transparent.
                $opacity = (1 - $lum) * (1 - $alpha / 127);

                imagesetpixel($img, $x, $y, imagecolorallocatealpha($img, 255, 255, 255, (int) round(127 * (1 - $opacity))));
            }
        }
    }

    // ─── Signing ──────────────────────────────────────────────────────────────

    private function sign(string $tmpDir, Setting $setting): void
    {
        $manifestPath = "$tmpDir/manifest.json";
        $sigPath = "$tmpDir/signature";
        $certPemPath = "$tmpDir/signingCert.pem";
        $keyPemPath = "$tmpDir/signingKey.pem";
        $wwdrPath = "$tmpDir/wwdr.pem";

        // Decode P12 → separate cert + key PEM
        $p12Raw = base64_decode($setting->wallet_pass_cert);
        $password = $setting->wallet_pass_cert_password ?? '';
        $certs = [];

        if (openssl_pkcs12_read($p12Raw, $certs, $password)) {
            file_put_contents($certPemPath, $certs['cert']);
            file_put_contents($keyPemPath, $certs['pkey']);
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
            throw new \RuntimeException('Pass signing failed (exit '.$exit.'): '.implode(' ', $output));
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
        putenv('PKPASS_PWD='.$password);

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
            && file_exists($keyPemPath) && filesize($keyPemPath) > 0;

        if (! $ok) {
            throw new \RuntimeException(
                'Failed to read P12 certificate (legacy fallback): '.trim(implode(' ', array_merge($certOut, $keyOut)))
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

    /**
     * True when text on this background should be dark rather than white.
     */
    private function isLightColor(string $hex): bool
    {
        [$r, $g, $b] = $this->parseHex($hex);

        return (0.299 * $r + 0.587 * $g + 0.114 * $b) > 150;
    }

    /**
     * Mix a colour toward white by $amount (0–1).
     */
    private function lighten(string $hex, float $amount): string
    {
        [$r, $g, $b] = $this->parseHex($hex);

        return sprintf(
            '#%02x%02x%02x',
            (int) round($r + (255 - $r) * $amount),
            (int) round($g + (255 - $g) * $amount),
            (int) round($b + (255 - $b) * $amount)
        );
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
            ."MIIEVTCCAj2gAwIBAgIUZg4NLUA4JMX8OGK2yFH3axfGpS8wDQYJKoZIhvcNAQEL\n"
            ."BQAwYjELMAkGA1UEBhMCVVMxEzARBgNVBAoTCkFwcGxlIEluYy4xJjAkBgNVBAsT\n"
            ."HUFwcGxlIENlcnRpZmljYXRpb24gQXV0aG9yaXR5MRYwFAYDVQQDEw1BcHBsZSBS\n"
            ."b290IENBMB4XDTIwMTIxNjE5MzYwNFoXDTMwMTIxMDAwMDAwMFowdTELMAkGA1UE\n"
            ."BhMCVVMxEzARBgNVBAoTCkFwcGxlIEluYy4xCzAJBgNVBAsTAkc0MUQwQgYDVQQD\n"
            ."EztBcHBsZSBXb3JsZHdpZGUgRGV2ZWxvcGVyIFJlbGF0aW9ucyBDZXJ0aWZpY2F0\n"
            ."aW9uIEF1dGhvcml0eTCCASIwDQYJKoZIhvcNAQEBBQADggEPADCCAQoCggEBANAf\n"
            ."eKp4BQ9bKxFPDW9BCw+RqEBFBqxnFkFZFU+W4NExhFU0DNnOuKJkJhVsJq25vvzl\n"
            ."B6YGKyPMUFDhEHHrWV0pT+OiCVQ8VSFQ7UxFCDJeUMGMNFUrJiPa2sMnJJQ9biPq\n"
            ."YMOOzudCBXFSNm0+C5lZzWyXg2F1O5mJJDAEBLaOSjr/VtAbomRdYoWf/P/YDhXU\n"
            ."cADY5iHPaLNM/kYbTMW8FqUFb/KM2Y4BPzLNXaLPKv48C+IhWXd6d7iXdKJCyZXn\n"
            ."gEgvJ4DxQS2s8p2FhzJ4DNVG0FqN/sAelKqzg6TyI6Yl7x1uRPc7mxQQ7K5XLBP\n"
            ."5ABNJMQNaSfZ5XFUZfECAwEAAaNjMGEwHQYDVR0OBBYEFIgnFwmpthhgi+zruvU\n"
            ."lNmRaQgmMB8GA1UdIwQYMBaAFCvQaUeUdgn+9GuNLkCm90dNfwheMBIGA1UdEwEB\n"
            ."/wQIMAYBAf8CAQAwCwYDVR0PBAQDAgGGMA0GCSqGSIb3DQEBCwUAA4IBAQCBf3P6\n"
            ."CaOmBMOB0Zd2mRW1BRcNRFAnEMjUhvncJc+8Ow/jBO2MFvEeIGi1RYLHA1sNiYZ\n"
            ."mFpE1QLpWwAewVB2mL7KkYEMBfRjMoB3oBIBEpFtIj8+ZVp4xJZ8TByaVo7Z0B5C\n"
            ."-----END CERTIFICATE-----\n";
    }
}
