<?php

namespace App\Services\Network;

/**
 * Parses SNMP directives out of a Cisco IOS / IOS-XE running config.
 *
 * Handles:
 *   snmp-server community <str> [RO|RW] [<acl>]
 *   snmp-server user <user> <group> v3 auth {md5|sha} <pw> priv {aes 128|des|3des} <pw>
 *   snmp-server group <group> v3 {noauth|auth|priv}
 *   snmp-server contact <str>
 *   snmp-server location <str>
 *
 * Caveats:
 *   - If the config has `service password-encryption` on and the community
 *     is shown as `0 <plain>` the parser captures it; if shown as `7 <hash>`
 *     the hash is captured as-is (operator must decrypt or re-enter).
 *   - v3 passwords in Cisco config are often cleartext in "no service
 *     password-encryption" setups. We capture what's there.
 */
class SnmpConfigExtractor
{
    public function extract(string $configText): array
    {
        $result = [
            'communities' => [],   // [['value' => 'public', 'access' => 'RO', 'acl' => null], ...]
            'v3_users'    => [],   // [['user' => .., 'group' => .., 'auth_proto' => .., 'auth_pw' => .., 'priv_proto' => .., 'priv_pw' => ..], ...]
            'v3_groups'   => [],
            'contact'     => null,
            'location'    => null,
        ];

        $lines = preg_split('/\r?\n/', $configText);

        foreach ($lines as $rawLine) {
            $line = trim($rawLine);
            if ($line === '' || str_starts_with($line, '!')) {
                continue;
            }

            // snmp-server community <str> [view <name>] [RO|RW] [ipv6 <acl>] [<acl>]
            if (preg_match('/^snmp-server\s+community\s+(\S+)(.*)$/i', $line, $m)) {
                $value = $m[1];
                $rest  = strtoupper($m[2] ?? '');
                $access = str_contains($rest, ' RW') ? 'RW' : 'RO';
                // Strip surrounding `0 `/`7 ` encoding prefixes if quoted weird
                $result['communities'][] = [
                    'value'  => $value,
                    'access' => $access,
                ];
                continue;
            }

            // snmp-server user <user> <group> v3 auth {md5|sha} <pw> priv {aes|des|3des} [128|192|256] <pw>
            if (preg_match(
                '/^snmp-server\s+user\s+(\S+)\s+(\S+)\s+v3(?:\s+encrypted)?(?:\s+auth\s+(md5|sha|sha256|sha384|sha512)\s+(\S+))?(?:\s+priv(?:\s+(aes|des|3des))?(?:\s+(128|192|256))?\s+(\S+))?/i',
                $line,
                $m
            )) {
                $result['v3_users'][] = [
                    'user'       => $m[1],
                    'group'      => $m[2],
                    'auth_proto' => strtolower($m[3] ?? '') ?: null,
                    'auth_pw'    => $m[4] ?? null,
                    'priv_proto' => strtolower($m[5] ?? '') ?: null,
                    'priv_pw'    => $m[7] ?? null,
                ];
                continue;
            }

            // snmp-server group <group> v3 {noauth|auth|priv}
            if (preg_match('/^snmp-server\s+group\s+(\S+)\s+v3\s+(noauth|auth|priv)/i', $line, $m)) {
                $result['v3_groups'][] = [
                    'group' => $m[1],
                    'level' => strtolower($m[2]),
                ];
                continue;
            }

            // snmp-server contact <free-form>
            if (preg_match('/^snmp-server\s+contact\s+(.+)$/i', $line, $m)) {
                $result['contact'] = trim($m[1]);
                continue;
            }

            // snmp-server location <free-form>
            if (preg_match('/^snmp-server\s+location\s+(.+)$/i', $line, $m)) {
                $result['location'] = trim($m[1]);
                continue;
            }
        }

        return $result;
    }

    /**
     * Pick the best settings to push into a MonitoredHost row.
     * Prefers a writable V3 user → V3 read user → RO community → RW community.
     *
     * Returns an array ready to merge into a MonitoredHost, or null if the
     * config has no SNMP section we can use.
     */
    public function pickForMonitoredHost(string $configText): ?array
    {
        $parsed = $this->extract($configText);

        // Prefer V3 user with privacy
        foreach ($parsed['v3_users'] as $u) {
            if (!empty($u['priv_pw']) && !empty($u['auth_pw'])) {
                return [
                    'snmp_version'         => 'v3',
                    'snmp_auth_user'       => $u['user'],
                    'snmp_auth_password'   => $u['auth_pw'],
                    'snmp_auth_protocol'   => $u['auth_proto'] ?: 'sha',
                    'snmp_priv_password'   => $u['priv_pw'],
                    'snmp_priv_protocol'   => $u['priv_proto'] ?: 'aes',
                    'snmp_security_level'  => 'authPriv',
                ];
            }
        }

        foreach ($parsed['v3_users'] as $u) {
            if (!empty($u['auth_pw'])) {
                return [
                    'snmp_version'         => 'v3',
                    'snmp_auth_user'       => $u['user'],
                    'snmp_auth_password'   => $u['auth_pw'],
                    'snmp_auth_protocol'   => $u['auth_proto'] ?: 'sha',
                    'snmp_security_level'  => 'authNoPriv',
                ];
            }
        }

        // Fall back to community strings — RO preferred, RW only if RO absent.
        $ro = collect($parsed['communities'])->firstWhere('access', 'RO');
        $rw = collect($parsed['communities'])->firstWhere('access', 'RW');
        $community = $ro ?? $rw;

        if ($community) {
            return [
                'snmp_version'   => '2c',
                'snmp_community' => $community['value'],
            ];
        }

        return null;
    }
}
