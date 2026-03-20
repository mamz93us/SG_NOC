<?php

namespace App\Services\Snmp;

use App\Models\MonitoredHost;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class SnmpClient
{
    protected ?\SNMP $session = null;
    protected bool $useCliMode = false;
    protected int $oidOutputFormat = 3; // 3 = Numeric OID
    protected int $valueRetrieval = 1; // 1 = Simple value
    protected int $timeout = 1500000; // 1.5 seconds
    protected int $retries = 1;

    public function __construct(protected MonitoredHost $host)
    {
        $this->useCliMode = !extension_loaded('snmp') || !class_exists('\SNMP');
        
        if ($this->useCliMode) {
            Log::warning("SnmpClient: PHP SNMP extension not loaded — falling back to CLI (snmpget/snmpwalk).", [
                'host' => $host->ip,
            ]);
        }
    }

    public function connect(): self
    {
        if ($this->useCliMode) {
            return $this;
        }

        $version = match ($this->host->snmp_version) {
            'v1' => \SNMP::VERSION_1,
            'v3' => \SNMP::VERSION_3,
            default => \SNMP::VERSION_2c,
        };

        $port = (int) ($this->host->snmp_port ?? 161);
        $target = $this->host->ip . ':' . $port;

        if ($version === \SNMP::VERSION_3) {
            $securityName   = $this->host->snmp_auth_user ?? '';
            $securityLevel  = $this->host->snmp_security_level ?? 'authPriv';
            $authProtocol   = strtoupper($this->host->snmp_auth_protocol ?? 'sha');
            $authPassphrase = '';
            $privProtocol   = strtoupper($this->host->snmp_priv_protocol ?? 'aes');
            $privPassphrase = '';

            if (in_array($securityLevel, ['authNoPriv', 'authPriv'])) {
                $authPassphrase = $this->host->snmp_auth_password ?? '';
            }
            if ($securityLevel === 'authPriv') {
                $privPassphrase = $this->host->snmp_priv_password ?? '';
            }

            $this->session = new \SNMP(
                \SNMP::VERSION_3,
                $target,
                $securityName,
                $authProtocol,
                $authPassphrase,
                $privProtocol,
                $privPassphrase,
                $this->timeout,
                $this->retries
            );
        } else {
            $community = $this->host->snmp_community ?? 'public';
            $this->session = new \SNMP($version, $target, $community, $this->timeout, $this->retries);
        }

        $this->session->exceptions_enabled = \SNMP::ERRNO_ANY;
        $this->session->valueretrieval = \SNMP_VALUE_LIBRARY;

        $this->loadMib();

        return $this;
    }

    public function get(string $oid): string|false
    {
        if ($this->useCliMode) {
            return $this->cliGet($oid);
        }

        if (!$this->session) {
            $this->connect();
        }

        try {
            $result = @$this->session->get($oid);
            return $result !== false ? $result : false;
        } catch (\SNMPException $e) {
            Log::debug("SnmpClient::get failed for OID {$oid} on {$this->host->ip}: {$e->getMessage()}");
            return false;
        }
    }

    public function getMultiple(array $oids): array
    {
        if (empty($oids)) return [];

        if ($this->useCliMode) {
            return $this->cliGetMultiple($oids);
        }

        if (!$this->session) {
            $this->connect();
        }

        try {
            // Group get() returns a map of OID => value
            $results = @$this->session->get($oids);
            if ($results === false) return [];
            return (array) $results;
        } catch (\SNMPException $e) {
            Log::debug("SnmpClient::getMultiple partially failed for host {$this->host->ip}: {$e->getMessage()}");
            // If the whole packet failed, try individual gets for safety?
            // For now, return empty or what we can.
            return [];
        }
    }

    public function walk(string $oid): array|false
    {
        if ($this->useCliMode) {
            return $this->cliWalk($oid);
        }

        if (!$this->session) {
            $this->connect();
        }

        try {
            $result = @$this->session->walk($oid);
            return $result !== false ? $result : false;
        } catch (\SNMPException $e) {
            Log::debug("SnmpClient::walk failed for OID {$oid} on {$this->host->ip}: {$e->getMessage()}");
            return false;
        }
    }

    public function setOidOutputFormat(int $format): self
    {
        $this->oidOutputFormat = $format;
        if ($this->session) {
            $this->session->oid_output_format = $format;
        }
        return $this;
    }

    public function setValueRetrieval(int $mode): self
    {
        $this->valueRetrieval = $mode;
        if ($this->session) {
            $this->session->valueretrieval = $mode;
        }
        return $this;
    }

    public function close(): void
    {
        if ($this->session) {
            $this->session->close();
            $this->session = null;
        }
    }

    protected function loadMib(): void
    {
        if (!$this->host->mib_id || !$this->host->mib) {
            return;
        }

        $path = $this->host->mib->file_path;
        $fullPath = Storage::disk('local')->path($path);
        $exists = Storage::disk('local')->exists($path);

        Log::debug("SnmpClient: Loading MIB for host {$this->host->ip}", [
            'mib_name' => $this->host->mib->name,
            'rel_path' => $path,
            'abs_path' => $fullPath,
            'file_exists' => $exists,
        ]);

        if ($exists) {
            $result = @snmp_read_mib($fullPath);
            Log::debug("SnmpClient: snmp_read_mib result", ['success' => $result]);
        } else {
            Log::warning("SnmpClient: MIB file not found on disk", ['path' => $fullPath]);
        }
    }

    protected function cliGetMultiple(array $oids): array
    {
        $oidString = implode(' ', array_map('escapeshellarg', $oids));
        $cmd = $this->buildCliCommand('snmpget', $oidString);
        $output = $this->execShell($cmd);

        if ($output === null || $output === '') {
            return [];
        }

        $results = [];
        // Map individual patterns like "OID = TYPE: VALUE"
        // Since snmpget -v2c ip OID1 OID2 returns one entry per line
        foreach (explode("\n", trim($output)) as $line) {
            $line = trim($line);
            if (preg_match('/^([.\d]+)\s*=\s*(.+)$/', $line, $m)) {
                $results[ltrim($m[1], '.')] = trim($m[2]);
            } elseif (preg_match('/^(?:iso|SNMPv2-SMI|SNMPv2-MIB|IF-MIB|UCD-SNMP-MIB).*?=\s*(.+)$/', $line, $m)) {
                // If it returns names instead of OIDs (but we requested numeric usually)
                // We'll just append it? No, wait.
                // snmpget -Onip returns numeric OIDs. I should ensure we use numeric.
            }
        }

        // If results are few, just try to match them by index order?
        // For simplicity, we return the parsed map.
        return $results;
    }

    protected function cliGet(string $oid): string|false
    {
        $cmd = $this->buildCliCommand('snmpget', escapeshellarg($oid));
        $output = $this->execShell($cmd);

        if ($output === null || $output === '') {
            return false;
        }

        // Strip "OID = " part if present
        if (preg_match('/^.*?=\s*(.+)$/', trim($output), $m)) {
            return trim($m[1]);
        }

        return trim($output);
    }

    protected function cliWalk(string $oid): array|false
    {
        $cmd = $this->buildCliCommand('snmpwalk', escapeshellarg($oid));
        $output = $this->execShell($cmd);

        if ($output === null || $output === '') {
            return false;
        }

        $results = [];
        foreach (explode("\n", trim($output)) as $line) {
            $line = trim($line);
            if ($line === '') continue;

            // Parse "OID = TYPE: VALUE" format
            if (preg_match('/^([.\d]+)\s*=\s*(.+)$/', $line, $m)) {
                $results[ltrim($m[1], '.')] = trim($m[2]);
            } elseif (preg_match('/^(.+?)\s*=\s*(.+)$/', $line, $m)) {
                $results[trim($m[1])] = trim($m[2]);
            }
        }

        return $results ?: false;
    }

    protected function buildCliCommand(string $tool, string $oids): string
    {
        $port = (int) ($this->host->snmp_port ?? 161);
        $ip   = escapeshellarg($this->host->ip);

        if ($this->host->snmp_version === 'v3') {
            $securityName  = escapeshellarg($this->host->snmp_auth_user ?? '');
            $securityLevel = escapeshellarg($this->host->snmp_security_level ?? 'authPriv');
            $authProtocol  = escapeshellarg(strtoupper($this->host->snmp_auth_protocol ?? 'SHA'));
            $privProtocol  = escapeshellarg(strtoupper($this->host->snmp_priv_protocol ?? 'AES'));

            $authPassword = '';
            $privPassword = '';
            $level = $this->host->snmp_security_level ?? 'authPriv';

            if (in_array($level, ['authNoPriv', 'authPriv'])) {
                $authPassword = escapeshellarg($this->host->snmp_auth_password ?? '');
            }
            if ($level === 'authPriv') {
                $privPassword = escapeshellarg($this->host->snmp_priv_password ?? '');
            }

            $args = "-v 3 -u {$securityName} -l {$securityLevel} -a {$authProtocol} -A {$authPassword} -x {$privProtocol} -X {$privPassword}";
        } else {
            $version   = match ($this->host->snmp_version) {
                'v1'    => '1',
                default => '2c',
            };
            $community = escapeshellarg($this->host->snmp_community ?? '');
            $args = "-v {$version} -c {$community}";
        }

        // Handle OID output format in CLI (-On for numeric)
        // 3 is usually numeric
        if ($this->oidOutputFormat == 3) {
            $args .= " -On";
        }

        return "{$tool} {$args} {$ip}:{$port} {$oids} 2>/dev/null";
    }

    protected function execShell(string $cmd): ?string
    {
        $output = @shell_exec($cmd);
        return is_string($output) ? trim($output) : null;
    }

    public function isCliMode(): bool
    {
        return $this->useCliMode;
    }

    public static function isSnmpExtensionLoaded(): bool
    {
        return extension_loaded('snmp');
    }
}
