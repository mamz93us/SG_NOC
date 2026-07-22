<?php

namespace App\Services\Dns;

/**
 * Parses a BIND-style zone file export into GoDaddy-shaped DNS record arrays.
 *
 * The parser is deliberately forgiving: it understands the subset of zone-file
 * syntax that GoDaddy / cPanel exports produce ($ORIGIN, $TTL, per-record TTL and
 * class, quoted TXT strings containing semicolons, parenthesised multi-line records)
 * and normalises everything into the shape GoDaddy's /v1/domains/{domain}/records
 * API expects.
 *
 * Records GoDaddy manages itself are not importable and are reported as "skipped"
 * rather than parsed: the SOA record, and the apex NS records (nameservers are
 * changed through GoDaddy's dedicated nameservers endpoint, not the records API).
 */
class ZoneFileParser
{
    /** Record types GoDaddy accepts through the records API. */
    private const SUPPORTED = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS', 'SRV', 'CAA', 'PTR'];

    /** Types that are never importable (registrar-managed). */
    private const SKIP_TYPES = ['SOA'];

    /** GoDaddy's minimum TTL. */
    private const MIN_TTL = 600;

    private string $origin;

    private int $defaultTtl = 3600;

    private string $lastName = '@';

    public function __construct(string $domain)
    {
        $this->origin = rtrim(strtolower(trim($domain)), '.').'.';
    }

    /**
     * @return array{records: array<int,array<string,mixed>>, skipped: array<int,array<string,mixed>>, errors: array<int,string>}
     */
    public function parse(string $zone): array
    {
        $records = [];
        $skipped = [];
        $errors = [];

        foreach ($this->logicalLines($zone) as [$lineNo, $line]) {
            $stripped = trim($this->stripComment($line));
            if ($stripped === '') {
                continue;
            }

            // Directives.
            if (preg_match('/^\$ORIGIN\s+(\S+)/i', $stripped, $m)) {
                $this->origin = strtolower(str_ends_with($m[1], '.') ? $m[1] : $m[1].'.');

                continue;
            }
            if (preg_match('/^\$TTL\s+(\S+)/i', $stripped, $m)) {
                $this->defaultTtl = $this->parseTtl($m[1]) ?? $this->defaultTtl;

                continue;
            }
            if (str_starts_with($stripped, '$')) {
                continue; // Unknown directive — ignore.
            }

            try {
                $result = $this->parseRecord($line);
            } catch (\Throwable $e) {
                $errors[] = 'Line '.$lineNo.': '.$e->getMessage();

                continue;
            }

            if ($result === null) {
                continue;
            }

            [$record, $skipReason] = $result;

            if ($skipReason !== null) {
                $skipped[] = ['record' => $record, 'reason' => $skipReason];

                continue;
            }

            $records[] = $record;
        }

        return ['records' => $records, 'skipped' => $skipped, 'errors' => $errors];
    }

    // ─── Line handling ────────────────────────────────────────────────

    /**
     * Split the zone into logical lines, joining parenthesised multi-line
     * records (e.g. SOA) into a single line. Returns [originalLineNumber, text].
     *
     * @return array<int,array{0:int,1:string}>
     */
    private function logicalLines(string $zone): array
    {
        $zone = str_replace(["\r\n", "\r"], "\n", $zone);
        $physical = explode("\n", $zone);

        $out = [];
        $buffer = '';
        $startLine = 0;
        $depth = 0;

        foreach ($physical as $i => $raw) {
            $noComment = $this->stripComment($raw);
            $depth += substr_count($noComment, '(') - substr_count($noComment, ')');

            if ($buffer === '') {
                $buffer = $raw;
                $startLine = $i + 1;
            } else {
                $buffer .= ' '.trim($raw);
            }

            if ($depth <= 0) {
                $out[] = [$startLine, $buffer];
                $buffer = '';
                $depth = 0;
            }
        }

        if ($buffer !== '') {
            $out[] = [$startLine, $buffer];
        }

        return $out;
    }

    /**
     * Remove an unquoted trailing comment (a ';' that is not inside a quoted
     * string). Critical for TXT records (e.g. DKIM) whose value contains ';'.
     */
    private function stripComment(string $line): string
    {
        $out = '';
        $inQuote = false;
        $len = strlen($line);

        for ($i = 0; $i < $len; $i++) {
            $ch = $line[$i];
            if ($ch === '"') {
                $inQuote = ! $inQuote;
                $out .= $ch;

                continue;
            }
            if ($ch === ';' && ! $inQuote) {
                break;
            }
            $out .= $ch;
        }

        return $out;
    }

    // ─── Record parsing ───────────────────────────────────────────────

    /**
     * @return array{0:array<string,mixed>,1:?string}|null [record, skipReason]
     */
    private function parseRecord(string $line): ?array
    {
        $hasExplicitName = (bool) preg_match('/^\S/', $this->stripComment($line));
        $tokens = $this->tokenize(trim($this->stripComment($line)));

        if (empty($tokens)) {
            return null;
        }

        $i = 0;
        if ($hasExplicitName) {
            $name = $tokens[$i++];
            $this->lastName = $name;
        } else {
            $name = $this->lastName;
        }

        // Optional TTL and class (IN/CH/HS), in either order, before the type.
        $ttl = $this->defaultTtl;
        for ($k = 0; $k < 2 && $i < count($tokens); $k++) {
            $tok = $tokens[$i];
            if (($t = $this->parseTtl($tok)) !== null) {
                $ttl = $t;
                $i++;
            } elseif (in_array(strtoupper($tok), ['IN', 'CH', 'HS'], true)) {
                $i++;
            } else {
                break;
            }
        }

        if ($i >= count($tokens)) {
            throw new \RuntimeException('Record is missing a type.');
        }

        $type = strtoupper($tokens[$i++]);
        $rdata = array_slice($tokens, $i);
        $gdName = $this->normalizeName($name);

        if (in_array($type, self::SKIP_TYPES, true)) {
            return [['type' => $type, 'name' => $gdName], 'Registrar-managed ('.$type.') — cannot be imported'];
        }

        if (! in_array($type, self::SUPPORTED, true)) {
            return [['type' => $type, 'name' => $gdName], 'Unsupported record type'];
        }

        if ($type === 'NS' && $gdName === '@') {
            $data = $this->normalizeTarget($rdata[0] ?? '');

            return [['type' => 'NS', 'name' => '@', 'data' => $data], 'Apex nameservers are set through GoDaddy, not the records API'];
        }

        return [$this->buildRecord($type, $gdName, max(self::MIN_TTL, $ttl), $rdata), null];
    }

    /**
     * @param  array<int,string>  $rdata
     * @return array<string,mixed>
     */
    private function buildRecord(string $type, string $name, int $ttl, array $rdata): array
    {
        $record = ['type' => $type, 'name' => $name, 'ttl' => $ttl];

        switch ($type) {
            case 'A':
            case 'AAAA':
                $record['data'] = $rdata[0] ?? '';
                break;

            case 'CNAME':
            case 'NS':
            case 'PTR':
                $record['data'] = $this->normalizeTarget($rdata[0] ?? '');
                break;

            case 'MX':
                $record['priority'] = (int) ($rdata[0] ?? 0);
                $record['data'] = $this->normalizeTarget($rdata[1] ?? '');
                break;

            case 'CAA':
                // flags tag "value"
                $record['data'] = trim(implode(' ', array_map(fn ($t) => $this->unquote($t), $rdata)));
                break;

            case 'TXT':
                $record['data'] = $this->joinTxt($rdata);
                break;

            case 'SRV':
                // rdata: priority weight port target
                [$service, $protocol, $host] = $this->splitSrvName($name);
                $record['name'] = $host;
                $record['service'] = $service;
                $record['protocol'] = $protocol;
                $record['priority'] = (int) ($rdata[0] ?? 0);
                $record['weight'] = (int) ($rdata[1] ?? 0);
                $record['port'] = (int) ($rdata[2] ?? 0);
                $record['data'] = $this->normalizeTarget($rdata[3] ?? '');
                break;
        }

        return $record;
    }

    // ─── Tokenising & normalisation ───────────────────────────────────

    /**
     * Split on whitespace, keeping double-quoted strings as single tokens.
     *
     * @return array<int,string>
     */
    private function tokenize(string $line): array
    {
        preg_match_all('/"(?:[^"\\\\]|\\\\.)*"|\S+/', $line, $m);

        return $m[0];
    }

    /** Normalise an owner name to a GoDaddy relative name ('@' for the apex). */
    private function normalizeName(string $name): string
    {
        $name = trim($name);
        if ($name === '' || $name === '@') {
            return '@';
        }

        if (str_ends_with($name, '.')) {
            $fqdn = strtolower($name);
            if ($fqdn === $this->origin) {
                return '@';
            }
            if (str_ends_with($fqdn, '.'.$this->origin)) {
                return substr($fqdn, 0, -strlen('.'.$this->origin));
            }

            // Outside the current origin — keep the label without the trailing dot.
            return rtrim($name, '.');
        }

        return $name;
    }

    /** Normalise a record target (CNAME/MX/NS/SRV/PTR). GoDaddy stores FQDNs without a trailing dot. */
    private function normalizeTarget(string $target): string
    {
        $target = trim($target);
        if ($target === '' || $target === '@') {
            return $target;
        }

        return rtrim($target, '.');
    }

    /**
     * Split an SRV owner name into [service, protocol, host].
     * e.g. "_autodiscover._tcp" → ['_autodiscover', '_tcp', '@'].
     *
     * @return array{0:string,1:string,2:string}
     */
    private function splitSrvName(string $name): array
    {
        if ($name === '@') {
            return ['', '', '@'];
        }

        $parts = explode('.', $name);
        $service = $parts[0] ?? '';
        $protocol = $parts[1] ?? '';
        $host = count($parts) > 2 ? implode('.', array_slice($parts, 2)) : '@';

        return [$service, $protocol, $host];
    }

    /**
     * Concatenate the character-strings of a TXT record into one value.
     *
     * @param  array<int,string>  $rdata
     */
    private function joinTxt(array $rdata): string
    {
        return implode('', array_map(fn ($t) => $this->unquote($t), $rdata));
    }

    private function unquote(string $token): string
    {
        if (strlen($token) >= 2 && $token[0] === '"' && substr($token, -1) === '"') {
            $inner = substr($token, 1, -1);

            return str_replace(['\\"', '\\\\'], ['"', '\\'], $inner);
        }

        return $token;
    }

    private function parseTtl(string $value): ?int
    {
        if (ctype_digit($value)) {
            return (int) $value;
        }

        if (preg_match('/^(\d+)([smhdw])$/i', $value, $m)) {
            $mult = ['s' => 1, 'm' => 60, 'h' => 3600, 'd' => 86400, 'w' => 604800][strtolower($m[2])];

            return (int) $m[1] * $mult;
        }

        return null;
    }
}
