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
     */
    public function up(string $tunnelName): array
    {
        return $this->execute(['up', $tunnelName]);
    }

    /**
     * Terminate a specific tunnel (child SA).
     */
    public function down(string $tunnelName): array
    {
        return $this->execute(['down', $tunnelName]);
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
            '19' => 'ecp256',
        ];
        $dh = $dhMap[$tunnel->dh_group] ?? "modp{$tunnel->dh_group}";

        $proposal = "{$encryption}-{$hash}-{$dh}";

        $config = "connections {\n";
        $config .= "    {$tunnel->name} {\n";
        $config .= "        remote_addrs = {$tunnel->remote_public_ip}\n";
        $config .= "        version = " . ($tunnel->ike_version === 'IKEv2' ? '2' : '1') . "\n";
        $config .= "        proposals = {$proposal}\n";
        $config .= "        rekey_time = {$tunnel->lifetime}\n";
        $config .= "        local {\n";
        $config .= "            auth = psk\n";
        if ($tunnel->local_id) {
            $config .= "            id = {$tunnel->local_id}\n";
        }
        $config .= "        }\n";
        $config .= "        remote {\n";
        $config .= "            auth = psk\n";
        if ($tunnel->remote_id) {
            $config .= "            id = {$tunnel->remote_id}\n";
        }
        $config .= "        }\n";
        $config .= "        children {\n";
        $config .= "            {$tunnel->name} {\n";
        $config .= "                local_ts = {$tunnel->local_subnet}\n";
        $config .= "                remote_ts = {$tunnel->remote_subnet}\n";
        $config .= "                esp_proposals = {$proposal}\n";
        $config .= "                dpd_action = restart\n";
        $config .= "                start_action = start\n";
        $config .= "            }\n";
        $config .= "        }\n";
        $config .= "    }\n";
        $config .= "}\n\n";
        
        $config .= "secrets {\n";
        $config .= "    ike-{$tunnel->name} {\n";
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
     * Execute the wrapper script with given arguments.
     */
    public function execute(array $args): array
    {
        // On Windows development environment, we mock the output
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            return ['status' => 'success', 'output' => 'Mocked output for ' . implode(' ', $args)];
        }

        $command = array_merge(['sudo', $this->wrapperPath], $args);
        $process = new Process($command);
        $process->setTimeout(10);
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
