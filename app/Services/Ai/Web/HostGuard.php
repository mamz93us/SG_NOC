<?php

namespace App\Services\Ai\Web;

use Closure;
use RuntimeException;
use Throwable;

/**
 * Where the website crawler may connect. The NOC reaches every internal
 * network — firewalls, UCMs, the AD servers — so a website is read only at
 * public addresses unless an admin allowed internal ones for it, and every
 * address a host resolves to is checked, not just the first.
 *
 * The address checked is the address connected to: WebCrawler pins it with
 * CURLOPT_RESOLVE, so a name that resolves somewhere else a moment later
 * changes nothing.
 */
class HostGuard
{
    /** @param  (Closure(string): array<int, string>)|null  $resolver  for tests; DNS otherwise */
    public function __construct(private ?Closure $resolver = null) {}

    /**
     * @return string the address to connect to
     *
     * @throws RuntimeException when the host does not resolve, or resolves to an address not allowed
     */
    public function check(string $host, bool $allowInternal): string
    {
        $host = trim($host, '[]');
        $addresses = filter_var($host, FILTER_VALIDATE_IP)
            ? [$host]
            : ($this->resolver ? ($this->resolver)($host) : self::resolve($host));

        if ($addresses === []) {
            throw new RuntimeException("{$host} does not resolve from the NOC.");
        }

        if (! $allowInternal) {
            foreach ($addresses as $address) {
                if (! filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE)) {
                    throw new RuntimeException("{$host} resolves to an internal address ({$address}). Allow internal addresses on this website if it is meant to be read.");
                }
            }
        }

        return $addresses[0];
    }

    /** @return array<int, string> IPv4 first, then IPv6 */
    public static function resolve(string $host): array
    {
        $addresses = gethostbynamel($host) ?: [];

        try {
            foreach (dns_get_record($host, DNS_AAAA) ?: [] as $record) {
                if (! empty($record['ipv6'])) {
                    $addresses[] = $record['ipv6'];
                }
            }
        } catch (Throwable) {
            // No IPv6 answer is not a failure.
        }

        return array_values(array_unique($addresses));
    }
}
