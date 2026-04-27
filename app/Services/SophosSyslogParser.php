<?php

namespace App\Services;

/**
 * Parses the key-value structured payload that Sophos firewalls emit
 * over syslog. A typical packet body looks like:
 *
 *   device_name="jed.samirgroup.net" timestamp="2026-04-27T19:47:47+0300"
 *   log_type="Firewall" log_component="Firewall Rule" log_subtype="Allowed"
 *   fw_rule_id="33" fw_rule_name="ORACLE_B & Internet_Med"
 *   src_ip="10.1.1.23" dst_ip="108.177.119.84" src_port=51536 dst_port=443
 *   protocol="TCP" bytes_sent=2820 bytes_received=3191 ...
 *
 * Values can be either quoted strings (with embedded spaces) or unquoted
 * tokens (typically numbers or single words). Keys are alphanumeric +
 * underscore. The parser is deliberately permissive — Sophos has dozens
 * of log subtypes, each emitting a different subset of keys.
 */
class SophosSyslogParser
{
    /**
     * Parse a Sophos syslog payload into an associative array.
     * Numeric-looking values are coerced to int/float for nicer display
     * and JSON storage. Unparseable input returns [].
     */
    public function parse(?string $body): array
    {
        if ($body === null || $body === '') return [];

        $body = ltrim($body);

        // Strip leading priority tag ("<30>") if present — some rsyslog
        // configurations leave it in the message body.
        $body = preg_replace('/^<\d+>/', '', $body);

        $out = [];

        // Match key=value pairs. Value is either:
        //   "quoted, may contain \" escapes"
        //   or unquoted run of non-whitespace.
        $pattern = '/([A-Za-z][A-Za-z0-9_]*)=(?:"((?:[^"\\\\]|\\\\.)*)"|(\S+))/';

        if (!preg_match_all($pattern, $body, $matches, PREG_SET_ORDER)) {
            return [];
        }

        foreach ($matches as $m) {
            $key = $m[1];
            // $m[2] is captured when value was quoted; $m[3] when unquoted.
            $val = isset($m[2]) && $m[2] !== '' ? $m[2] : ($m[3] ?? '');

            // Unescape \" and \\ inside quoted values.
            if (isset($m[2])) {
                $val = str_replace(['\\"', '\\\\'], ['"', '\\'], $val);
            }

            $out[$key] = $this->coerce($val);
        }

        return $out;
    }

    /**
     * Coerce numeric strings to int/float so JSON storage doesn't
     * stringify ports, byte counts, etc. Leaves anything ambiguous
     * (IP-like strings, IDs with leading zeros, MACs) as a string.
     */
    private function coerce(string $val): int|float|string
    {
        if ($val === '') return '';

        // Pure integer (no leading zeros that would imply a string ID).
        if (preg_match('/^-?[1-9]\d*$/', $val) || $val === '0') {
            // Guard against int overflow on 32-bit systems.
            if ($val >= PHP_INT_MIN && $val <= PHP_INT_MAX) {
                return (int) $val;
            }
        }

        // Float.
        if (preg_match('/^-?\d+\.\d+$/', $val)) {
            return (float) $val;
        }

        return $val;
    }

    // ─── Convenience helpers used by views ────────────────────────────────

    /**
     * Pull a flattened "summary" view of the most operationally useful
     * fields out of the parsed array, for the Log Viewer-style table.
     * Returns an array indexed by display label so the view stays dumb.
     */
    public function summary(array $parsed): array
    {
        $get = fn(string $k, $default = null) => $parsed[$k] ?? $default;

        return [
            'log_component' => $get('log_component'),
            'log_subtype'   => $get('log_subtype'),
            'username'      => $get('user_name', $get('username', $get('user_group'))),
            'fw_rule'       => $get('fw_rule_id'),
            'fw_rule_name'  => $get('fw_rule_name'),
            'nat_rule'      => $get('nat_rule_id'),
            'nat_rule_name' => $get('nat_rule_name'),
            'in_iface'      => $get('in_interface', $get('in_display_interface')),
            'out_iface'     => $get('out_interface', $get('out_display_interface')),
            'src_ip'        => $get('src_ip'),
            'dst_ip'        => $get('dst_ip'),
            'src_port'      => $get('src_port'),
            'dst_port'      => $get('dst_port'),
            'protocol'      => $get('protocol'),
            'bytes_sent'    => $get('bytes_sent'),
            'bytes_received'=> $get('bytes_received'),
            'severity'      => $get('severity'),
            'log_type'      => $get('log_type'),
            'src_country'   => $get('src_country'),
            'dst_country'   => $get('dst_country'),
        ];
    }
}
