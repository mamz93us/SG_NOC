<?php

namespace App\Services\Deploy;

/**
 * Identifies an uploaded SSH private key well enough to show the admin what
 * they just stored — format, fingerprint, whether it is passphrase-protected.
 *
 * This is deliberately advisory, not a gate. The Node proxy's ssh2 parser is
 * the real authority on whether a key works (it accepts OpenSSH, PEM and PuTTY
 * PPK), so an unrecognised blob is still saved; the admin proves it with the
 * "Test connection" button.
 */
class SshKeyInspector
{
    /**
     * @return array{format:string, fingerprint:?string, encrypted:bool, comment:?string}
     */
    public function inspect(string $contents): array
    {
        $text = trim($contents);

        if ($text === '') {
            return $this->result('unknown');
        }

        if (str_starts_with($text, 'PuTTY-User-Key-File-')) {
            return $this->inspectPpk($text);
        }

        if (str_contains($text, '-----BEGIN OPENSSH PRIVATE KEY-----')) {
            return $this->result('openssh', null, $this->opensshIsEncrypted($text));
        }

        if (preg_match('/-----BEGIN (RSA |DSA |EC |ENCRYPTED )?PRIVATE KEY-----/', $text)) {
            return $this->inspectPem($text);
        }

        return $this->result('unknown');
    }

    /**
     * An OpenSSH key's own header names the cipher, and "none" means
     * unencrypted. Read it properly rather than grepping the base64 for a magic
     * substring — "none" lands at a different base64 offset depending on the
     * key type, so a substring test silently reports every key as encrypted.
     *
     * Layout: "openssh-key-v1\0" then a uint32-length-prefixed cipher name.
     */
    private function opensshIsEncrypted(string $text): bool
    {
        $body = preg_replace('/-----(BEGIN|END) OPENSSH PRIVATE KEY-----|\s+/', '', $text);

        if (! is_string($body) || $body === '') {
            return false;
        }

        $der = base64_decode($body, true);
        $magic = "openssh-key-v1\0";

        if ($der === false || ! str_starts_with($der, $magic) || strlen($der) < strlen($magic) + 4) {
            return false;
        }

        $offset = strlen($magic);
        $length = unpack('N', substr($der, $offset, 4))[1] ?? 0;

        if ($length <= 0 || $length > 64 || strlen($der) < $offset + 4 + $length) {
            return false;
        }

        return substr($der, $offset + 4, $length) !== 'none';
    }

    /**
     * Classic PEM — openssl can read these, so we can derive a real
     * SHA256 fingerprint of the public half.
     */
    private function inspectPem(string $text): array
    {
        $encrypted = str_contains($text, 'ENCRYPTED')
            || str_contains($text, 'Proc-Type: 4,ENCRYPTED');

        if ($encrypted || ! function_exists('openssl_pkey_get_private')) {
            return $this->result('pem', null, $encrypted);
        }

        try {
            $key = @openssl_pkey_get_private($text);
            if ($key === false) {
                return $this->result('pem', null, false);
            }

            $details = @openssl_pkey_get_details($key);
            if (! is_array($details) || empty($details['key'])) {
                return $this->result('pem', null, false);
            }

            return $this->result('pem', $this->sha256OfPublicKey($details['key']), false);
        } catch (\Throwable) {
            return $this->result('pem', null, $encrypted);
        }
    }

    /**
     * PuTTY .ppk — a plain-text header block. ssh2 reads these natively, so we
     * only need the human-facing bits out of the header.
     */
    private function inspectPpk(string $text): array
    {
        $encrypted = (bool) preg_match('/^Encryption:\s*(?!none)\S+/mi', $text);
        $comment = preg_match('/^Comment:\s*(.+)$/mi', $text, $m) ? trim($m[1]) : null;

        return $this->result('ppk', null, $encrypted, $comment);
    }

    /** SHA256 fingerprint of a PEM public key, in the shape ssh-keygen prints. */
    private function sha256OfPublicKey(string $publicKeyPem): ?string
    {
        $body = preg_replace('/-----(BEGIN|END)[^-]+-----|\s+/', '', $publicKeyPem);

        if (! is_string($body) || $body === '') {
            return null;
        }

        $der = base64_decode($body, true);
        if ($der === false) {
            return null;
        }

        return 'SHA256:'.rtrim(base64_encode(hash('sha256', $der, true)), '=');
    }

    private function result(string $format, ?string $fingerprint = null, bool $encrypted = false, ?string $comment = null): array
    {
        return [
            'format' => $format,
            'fingerprint' => $fingerprint,
            'encrypted' => $encrypted,
            'comment' => $comment,
        ];
    }
}
