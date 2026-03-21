<?php

namespace App\Services;

use App\Models\VpnLog;
use App\Models\VpnTunnel;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class VpnControlService
{
    protected string $wrapperPath = '/usr/local/bin/sg-vpn-control';

    /**
     * Reload all swanctl configurations.
     */
    public function reload(): array
    {
        return $this->execute(['reload']);
    }

    /**
     * Initiate a specific tunnel (child SA).
     * Runs non-blocking — returns immediately so the HTTP request doesn't time out
     * while strongSwan negotiates IKE with the remote peer.
     */
    public function up(string $tunnelName): array
    {
        return $this->executeAsync(['up', $tunnelName]);
    }

    /**
     * Terminate a specific tunnel (child SA).
     * Runs non-blocking — swanctl sends the DELETE notification and returns quickly.
     */
    public function down(string $tunnelName): array
    {
        return $this->executeAsync(['down', $tunnelName]);
    }

    /**
     * List all Security Associations (SAs) and their statuses.
     */
    public function status(): array
    {
        return $this->execute(['status']);
    }

    /**
     * Generate the swanctl configuration file for a tunnel.
     */
    public function generateConfig(VpnTunnel $tunnel): string
    {
        $encryption = strtolower($tunnel->encryption);
        $hash = strtolower($tunnel->hash);
        
        // Map DH groups to modp names
        $dhMap = [
            '14' => 'modp2048',
            '15' => 'modp3072',
            '16' => 'modp4096',
            '18' => 'modp8192',
            '19' => 'ecp256',
            '20' => 'ecp384',
            '21' => 'ecp521',
            '31' => 'curve25519',
        ];
        $dh = $dhMap[$tunnel->dh_group] ?? "modp{$tunnel->dh_group}";

        // Main proposal based on user selection
        $proposal = "{$encryption}-{$hash}-{$dh}";
        
        // Build unique proposals list with standard fallbacks
        $fallbackList = [
            $proposal,
            "aes256-sha512-curve25519",
            "aes256-sha256-curve25519",
            "aes256-sha512-modp4096",
            "aes256-sha256-modp2048",
            "aes256-sha1-modp2048",
            "aes128-sha1-modp1024"
        ];
        $proposals = implode(',', array_unique($fallbackList));

        $config = "connections {\n";
        $config .= "    {$tunnel->name} {\n";
        $config .= "        remote_addrs = {$tunnel->remote_public_ip}\n";
        $config .= "        version = " . ($tunnel->ike_version === 'IKEv2' ? '2' : '1') . "\n";
        $config .= "        proposals = {$proposals}\n";
        $config .= "        rekey_time = {$tunnel->lifetime}\n";
        $localId = $tunnel->local_id ?: config('vpn.local_id', 'noc.samirgroup.net');
        $remoteId = $tunnel->remote_id ?: $tunnel->remote_public_ip;

        $config .= "        local {\n";
        $config .= "            auth = psk\n";
        $config .= "            id = {$localId}\n";
        $config .= "        }\n";
        $config .= "        remote {\n";
        $config .= "            auth = psk\n";
        $config .= "            id = {$remoteId}\n";
        $config .= "        }\n";
        $config .= "        children {\n";
        
        $localSubnets = array_filter(array_map('trim', explode(',', $tunnel->local_subnet)));
        $remoteSubnets = array_filter(array_map('trim', explode(',', $tunnel->remote_subnet)));
        
        if (empty($localSubnets)) $localSubnets = ['0.0.0.0/0'];
        if (empty($remoteSubnets)) $remoteSubnets = ['0.0.0.0/0'];
        
        $childCounter = 1;
        foreach ($localSubnets as $localTs) {
            foreach ($remoteSubnets as $remoteTs) {
                // First child gets the tunnel name, subsequent get -2, -3, etc.
                $childName = $childCounter === 1 ? $tunnel->name : "{$tunnel->name}-{$childCounter}";
                
                $config .= "            {$childName} {\n";
                $config .= "                local_ts = {$localTs}\n";
                $config .= "                remote_ts = {$remoteTs}\n";
                $config .= "                esp_proposals = {$proposals}\n";
                $config .= "                dpd_action = restart\n";
                $config .= "                start_action = start\n";
                $config .= "            }\n";
                
                $childCounter++;
            }
        }
        $config .= "        }\n";
        $config .= "    }\n";
        $config .= "}\n\n";
        
        $config .= "secrets {\n";
        $config .= "    ike-{$tunnel->name} {\n";
        $config .= "        id-0 = {$localId}\n";
        $config .= "        id-1 = {$remoteId}\n";
        $config .= "        secret = \"{$tunnel->pre_shared_key}\"\n";
        $config .= "    }\n";
        $config .= "}\n";

        return $config;
    }

    /**
     * Save swanctl config to file.
     */
    public function saveConfig(VpnTunnel $tunnel, string $config): bool
    {
        $path = "/etc/swanctl/conf.d/{$tunnel->name}.conf";
        
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            Log::info("VpnControlService: Mocking config save for {$tunnel->name}", ['path' => $path]);
            return true;
        }

        try {
            // Use sudo wrapper if needed, but for now try direct write
            // (Assumes permissions are handled or directory is writable by www-data)
            return file_put_contents($path, $config) !== false;
        } catch (\Exception $e) {
            Log::error("VpnControlService: Failed to save config for {$tunnel->name}", ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Remove swanctl config file.
     */
    public function removeConfig(VpnTunnel $tunnel): bool
    {
        $path = "/etc/swanctl/conf.d/{$tunnel->name}.conf";
        
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            Log::info("VpnControlService: Mocking config removal for {$tunnel->name}", ['path' => $path]);
            return true;
        }

        if (file_exists($path)) {
            return unlink($path);
        }
        
        return true;
    }

    /**
     * Execute the wrapper script asynchronously (fire-and-forget).
     * Used for `up` and `down` which can take 30+ seconds to negotiate IKE.
     * Waits up to 5 seconds for an immediate error, then returns success so
     * the HTTP request isn't blocked waiting for the full negotiation.
     */
    public function executeAsync(array $args): array
    {
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            return ['status' => 'success', 'swanctl_available' => true, 'output' => 'Mocked async for ' . implode(' ', $args)];
        }

        $command = array_merge(['sudo', $this->wrapperPath], $args);
        $process = new Process($command);
        $process->setTimeout(120); // generous upper bound in case swanctl stalls
        $process->start();         // non-blocking — launches process and returns

        // Give the process up to 5 seconds to produce an immediate error
        // (e.g. tunnel name not found, swanctl not installed).
        $waited = 0;
        while ($process->isRunning() && $waited < 5) {
            usleep(500_000); // 0.5 s
            $waited += 0.5;
        }

        if (!$process->isRunning()) {
            // Process finished within 5 s — check for failure
            if (!$process->isSuccessful()) {
                $error = trim($process->getErrorOutput() ?: $process->getOutput());
                Log::error('VpnControlService: Async command failed quickly', [
                    'command' => $command,
                    'error'   => $error,
                ]);
                return ['status' => 'error', 'message' => $error ?: 'Command failed.'];
            }
            // Finished quickly and successfully (e.g. tunnel was already up)
            $output  = $process->getOutput();
            $decoded = json_decode($output, true);
            return $decoded ?: ['status' => 'success', 'swanctl_available' => true, 'output' => $output];
        }

        // Still running after 5 s — negotiation is in progress in background.
        // Return optimistically; the status-check polling will reflect the real state.
        Log::info('VpnControlService: Async command still running (background negotiation)', [
            'command' => $command,
        ]);
        return ['status' => 'success', 'swanctl_available' => true, 'output' => 'Negotiation in progress…'];
    }

    /**
     * Execute the wrapper script synchronously.
     * Used for status queries and reload operations which return quickly.
     */
    public function execute(array $args): array
    {
        // On Windows development environment, we mock the output
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            return ['status' => 'success', 'output' => 'Mocked output for ' . implode(' ', $args)];
        }

        $command = array_merge(['sudo', $this->wrapperPath], $args);
        $process = new Process($command);
        $process->setTimeout(30); // 30 s is plenty for status / reload
        $process->run();

        if (!$process->isSuccessful()) {
            $error = $process->getErrorOutput() ?: $process->getOutput();
            Log::error('VpnControlService: Command failed', [
                'command' => $command,
                'error' => $error
            ]);
            return ['status' => 'error', 'message' => $error];
        }

        $output = $process->getOutput();

        // Detect swanctl not responding (empty combined output)
        if (empty(trim($output))) {
            Log::warning('VpnControlService: swanctl returned empty output', ['args' => $args]);
            return [
                'status'            => 'unavailable',
                'swanctl_available' => false,
                'output'            => '',
                'message'           => 'swanctl service is not responding. Check that strongSwan is installed and running.',
            ];
        }

        $decoded = json_decode($output, true);

        if ($decoded) {
            return $decoded;
        }

        // Check for raw log delimiters - use more flexible whitespace matching
        if (preg_match('/RAW_LOGS_START\s+(.*)\s+RAW_LOGS_END/s', $output, $matches)) {
            return ['status' => 'success', 'swanctl_available' => true, 'output' => trim($matches[1])];
        }

        // Check for raw output delimiters (used by status action)
        if (preg_match('/RAW_OUTPUT_START\s+(.*)\s+RAW_OUTPUT_END/s', $output, $matches)) {
            return ['status' => 'success', 'swanctl_available' => true, 'output' => trim($matches[1])];
        }

        return ['status' => 'success', 'swanctl_available' => true, 'output' => $output];
    }
}
