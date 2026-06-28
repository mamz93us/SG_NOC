<?php

namespace App\Services\Ticketing;

/**
 * Resolves a client IP to a branch name using the editable CIDR → branch map
 * in config/ticket_tracking.php. Returns 'unknown' when nothing matches.
 *
 * Supports both IPv4 and IPv6 CIDRs.
 */
class BranchResolver
{
    public const UNKNOWN = 'unknown';

    /** @var array<string, string[]> */
    private array $map;

    public function __construct(?array $map = null)
    {
        $this->map = $map ?? (array) config('ticket_tracking.branch_cidrs', []);
    }

    public function resolve(?string $ip): string
    {
        $ip = trim((string) $ip);

        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return self::UNKNOWN;
        }

        foreach ($this->map as $branch => $cidrs) {
            foreach ((array) $cidrs as $cidr) {
                if ($this->ipInCidr($ip, trim((string) $cidr))) {
                    return (string) $branch;
                }
            }
        }

        return self::UNKNOWN;
    }

    public function ipInCidr(string $ip, string $cidr): bool
    {
        if ($cidr === '' || ! str_contains($cidr, '/')) {
            return false;
        }

        [$subnet, $bits] = explode('/', $cidr, 2);
        $bits = (int) $bits;

        $ipBin = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);

        // Both must parse and be the same family (IPv4 vs IPv6).
        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }

        $maxBits = strlen($ipBin) * 8;
        if ($bits < 0 || $bits > $maxBits) {
            return false;
        }

        $bytes = intdiv($bits, 8);
        $rem = $bits % 8;

        // Compare whole bytes.
        if ($bytes > 0 && strncmp($ipBin, $subnetBin, $bytes) !== 0) {
            return false;
        }

        // Compare the remaining partial byte under a mask.
        if ($rem !== 0) {
            $mask = ~((1 << (8 - $rem)) - 1) & 0xFF;

            return (ord($ipBin[$bytes]) & $mask) === (ord($subnetBin[$bytes]) & $mask);
        }

        return true;
    }
}
