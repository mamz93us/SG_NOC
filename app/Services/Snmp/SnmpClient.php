<?php

namespace App\Services\Snmp;

use App\Models\MonitoredHost;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class SnmpClient
{
    protected MonitoredHost $host;
    protected ?\SNMP $session = null;
    protected bool $useCliMode = false;

    public function __construct(MonitoredHost $host)
    {
        $this->host = $host;
        $this->useCliMode = !extension_loaded('snmp');

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

        $community = $this->host->snmp_community ?? '';
        $port = (int) ($this->host->snmp_port ?? 161);
        // Only append port if non-default — some PHP SNMP builds are picky about host:port
        $target = $port !== 161 ? $this->host->ip . ':' . $port : $this->host->ip;

        $this->session = new \SNMP($version, $target, $community, 1000000, 2);
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
            // OID not found, timeout, or other SNMP error — return false instead of throwing
            Log::debug("SnmpClient::get failed for OID {$oid} on {$this->host->ip}: {$e->getMessage()}");
            return false;
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
        if ($this->session) {
            $this->session->oid_output_format = $format;
        }
        return $this;
    }

    public function setValueRetrieval(int $mode): self
    {
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

    protected function cliGet(string $oid): string|false
    {
        $cmd = $this->buildCliCommand('snmpget', $oid);
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
        $cmd = $this->buildCliCommand('snmpwalk', $oid);
        $output = $this->execShell($cmd);

        if ($output === null || $output === '') {
            return false;
        }

        $results = [];
        foreach (explode("\n", trim($output)) as $line) {
            $line = trim($line);
            if ($line === '') continue;

            // Parse "OID = TYPE: VALUE" format
            if (preg_match('/^([\d.]+)\s*=\s*(.+)$/', $line, $m)) {
                $results[$m[1]] = trim($m[2]);
            } elseif (preg_match('/^(.+?)\s*=\s*(.+)$/', $line, $m)) {
                $results[trim($m[1])] = trim($m[2]);
            }
        }

        return $results ?: false;
    }

    protected function buildCliCommand(string $tool, string $oid): string
    {
        $version = match ($this->host->snmp_version) {
            'v1' => '1',
            'v3' => '3',
            default => '2c',
        };

        $community = escapeshellarg($this->host->snmp_community ?? '');
        $port = (int) ($this->host->snmp_port ?? 161);
        $ip = escapeshellarg($this->host->ip);
        $oid = escapeshellarg($oid);

        return "{$tool} -v {$version} -c {$community} {$ip}:{$port} {$oid} 2>/dev/null";
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
