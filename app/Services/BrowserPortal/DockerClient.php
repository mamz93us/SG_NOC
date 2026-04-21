<?php

namespace App\Services\BrowserPortal;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * Thin wrapper around `sudo docker ...`. Mirrors VpnControlService's shell-out
 * pattern: Symfony Process, sudo-prefixed, arg array (not a single string so
 * there's nothing to shell-escape). All methods return process stdout.
 *
 * On Windows (local dev), calls are mocked so the Blade pages are still testable.
 */
class DockerClient
{
    protected string $dockerBin = '/usr/bin/docker';

    public function isWindows(): bool
    {
        return strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
    }

    /**
     * Run `docker run` with a prepared argv list. Returns the container id on success.
     */
    public function run(array $args): string
    {
        return trim($this->execute(array_merge(['run'], $args), timeout: 60));
    }

    public function stop(string $containerName, int $timeout = 10): void
    {
        $this->execute(['stop', '-t', (string) $timeout, $containerName], timeout: $timeout + 10);
    }

    public function rm(string $containerName, bool $force = false): void
    {
        $args = ['rm'];
        if ($force) $args[] = '-f';
        $args[] = $containerName;
        $this->execute($args, timeout: 20);
    }

    /**
     * `docker inspect -f '{{json .}}' <name>` parsed into an array.
     * Returns null if the container no longer exists.
     */
    public function inspect(string $containerName): ?array
    {
        try {
            $out = $this->execute(['inspect', '-f', '{{json .}}', $containerName], timeout: 10);
        } catch (\RuntimeException) {
            return null;
        }
        $decoded = json_decode(trim($out), true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Read the bridge IP assigned to the container on the browser-net network.
     * Returns null while the container is still in 'starting' state and hasn't
     * been allocated an IP yet (Docker sometimes takes ~200ms).
     */
    public function bridgeIp(string $containerName, string $network = 'browser-net'): ?string
    {
        try {
            $out = $this->execute([
                'inspect', '-f',
                '{{with index .NetworkSettings.Networks "' . $network . '"}}{{.IPAddress}}{{end}}',
                $containerName,
            ], timeout: 10);
        } catch (\RuntimeException) {
            return null;
        }
        $ip = trim($out);
        return $ip === '' ? null : $ip;
    }

    /**
     * `docker ps --filter name=^neko- --format '{{.Names}}'` → list of running neko containers.
     */
    public function listNekoContainers(): array
    {
        try {
            $out = $this->execute([
                'ps',
                '--filter', 'name=^neko-',
                '--format', '{{.Names}}',
            ], timeout: 10);
        } catch (\RuntimeException) {
            return [];
        }
        return array_values(array_filter(array_map('trim', explode("\n", $out))));
    }

    /**
     * `docker stats --no-stream --format <json>` for the given container names.
     * Returns an array keyed by container name with cpu/memory strings.
     */
    public function stats(array $names): array
    {
        if (empty($names)) return [];
        try {
            $out = $this->execute(array_merge([
                'stats', '--no-stream',
                '--format', '{{json .}}',
            ], $names), timeout: 15);
        } catch (\RuntimeException) {
            return [];
        }
        $result = [];
        foreach (preg_split("/\r\n|\n|\r/", trim($out)) as $line) {
            if ($line === '') continue;
            $row = json_decode($line, true);
            if (!is_array($row) || empty($row['Name'])) continue;
            $result[$row['Name']] = [
                'cpu' => $row['CPUPerc'] ?? '-',
                'mem' => $row['MemUsage'] ?? '-',
            ];
        }
        return $result;
    }

    /**
     * Core execute. Prepends `sudo <dockerBin>` unless running on Windows
     * (where we can't/won't actually touch Docker — returns a mock).
     */
    public function execute(array $args, int $timeout = 30): string
    {
        if ($this->isWindows()) {
            Log::debug('DockerClient (Windows mock)', ['args' => $args]);
            return $this->windowsMock($args);
        }

        $command = array_merge(['sudo', $this->dockerBin], $args);
        $process = new Process($command);
        $process->setTimeout($timeout);
        $process->run();

        if (!$process->isSuccessful()) {
            $err = trim($process->getErrorOutput() ?: $process->getOutput());
            Log::error('DockerClient: command failed', [
                'command' => $command,
                'error'   => $err,
            ]);
            throw new \RuntimeException("docker command failed: $err");
        }

        return $process->getOutput();
    }

    protected function windowsMock(array $args): string
    {
        $verb = $args[0] ?? '';
        return match (true) {
            $verb === 'run'     => 'mock-container-id',
            $verb === 'inspect' => '{"Id":"mock","NetworkSettings":{"Networks":{"browser-net":{"IPAddress":"172.30.0.99"}}}}',
            $verb === 'ps'      => '',
            $verb === 'stats'   => '',
            default             => '',
        };
    }
}
