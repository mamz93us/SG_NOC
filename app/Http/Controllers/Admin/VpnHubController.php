<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\SophosVpnTunnel;
use App\Models\VpnLog;
use App\Models\VpnTunnel;
use App\Services\VpnControlService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class VpnHubController extends Controller
{
    protected VpnControlService $vpnService;

    public function __construct(VpnControlService $vpnService)
    {
        $this->vpnService = $vpnService;
    }

    public function index()
    {
        $tunnels = VpnTunnel::with('branch')->orderBy('name')->get();
        return view('admin.network.vpn.index', compact('tunnels'));
    }

    public function create()
    {
        $branches = Branch::orderBy('name')->get();
        $defaultLocalSubnet = config('vpn.local_subnet');
        $defaultLocalId = config('vpn.local_id');
        return view('admin.network.vpn.create', compact('branches', 'defaultLocalSubnet', 'defaultLocalId'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'branch_id'        => 'required|exists:branches,id',
            'name'             => 'required|string|max:255|unique:vpn_tunnels,name|regex:/^[a-zA-Z0-9_]+$/',
            'remote_public_ip' => 'required|string|max:255',
            'local_id'         => 'nullable|string|max:255',
            'remote_id'        => 'nullable|string|max:255',
            'remote_subnet'    => 'required|string', // Comma separated allowed
            'local_subnet'     => 'required|string',  // Comma separated allowed
            'pre_shared_key'   => 'required|string',
            'ike_version'      => 'required|in:IKEv2,IKEv1',
            'encryption'       => 'required|string',
            'hash'             => 'required|string',
            'dh_group'         => 'required|integer',
            'dpd_delay'        => 'required|integer',
            'lifetime'         => 'required|string',
        ]);

        try {
            DB::beginTransaction();

            $tunnel = VpnTunnel::create($data);

            // Generate and save swanctl config
            $config = $this->vpnService->generateConfig($tunnel);
            if (!$this->vpnService->saveConfig($tunnel, $config)) {
                throw new \Exception("Failed to save swanctl configuration file.");
            }

            // Reload swanctl
            $reload = $this->vpnService->reload();
            if ($reload['status'] === 'error') {
                $this->vpnService->removeConfig($tunnel); // Rollback file
                throw new \Exception("swanctl reload failed: " . ($reload['message'] ?? 'Unknown error'));
            }

            // Log attempt
            VpnLog::create([
                'vpn_id' => $tunnel->id,
                'event_type' => 'reload',
                'message' => 'Tunnel created and configuration reloaded.'
            ]);

            \App\Models\ActivityLog::log('VPN', "Created new VPN tunnel '{$tunnel->name}'", 'success', $tunnel->id);

            DB::commit();

            // Try to initiate tunnel
            $this->vpnService->up($tunnel->name);

            return redirect()->route('admin.network.vpn.index')
                ->with('success', "VPN Tunnel '{$tunnel->name}' created and initiated.");

        } catch (\Exception $e) {
            DB::rollBack();
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    public function edit(VpnTunnel $tunnel)
    {
        $branches = Branch::orderBy('name')->get();
        return view('admin.network.vpn.edit', compact('tunnel', 'branches'));
    }

    public function update(Request $request, VpnTunnel $tunnel)
    {
        $data = $request->validate([
            'branch_id'        => 'required|exists:branches,id',
            'name'             => 'required|string|max:255|unique:vpn_tunnels,name,' . $tunnel->id . '|regex:/^[a-zA-Z0-9_]+$/',
            'remote_public_ip' => 'required|string|max:255',
            'local_id'         => 'nullable|string|max:255',
            'remote_id'        => 'nullable|string|max:255',
            'remote_subnet'    => 'required|string', // Comma separated allowed
            'local_subnet'     => 'required|string',  // Comma separated allowed
            'pre_shared_key'   => 'nullable|string',
            'ike_version'      => 'required|in:IKEv2,IKEv1',
            'encryption'       => 'required|string',
            'hash'             => 'required|string',
            'dh_group'         => 'required|integer',
            'dpd_delay'        => 'required|integer',
            'lifetime'         => 'required|string',
        ]);

        try {
            DB::beginTransaction();

            $oldName = $tunnel->name;
            if (empty($data['pre_shared_key'])) {
                unset($data['pre_shared_key']);
            }

            $tunnel->update($data);

            // Re-generate and save config
            $config = $this->vpnService->generateConfig($tunnel);
            
            // If name changed, remove old file
            if ($oldName !== $tunnel->name) {
                // Mock VpnTunnel object for removal
                $oldTunnel = new VpnTunnel(['name' => $oldName]);
                $this->vpnService->removeConfig($oldTunnel);
            }

            if (!$this->vpnService->saveConfig($tunnel, $config)) {
                throw new \Exception("Failed to save swanctl configuration file.");
            }

            $this->vpnService->reload();

            VpnLog::create([
                'vpn_id' => $tunnel->id,
                'event_type' => 'reload',
                'message' => 'Tunnel configuration updated.'
            ]);

            \App\Models\ActivityLog::log('VPN', "Updated configuration for VPN tunnel '{$tunnel->name}'", 'info', $tunnel->id);

            DB::commit();

            return redirect()->route('admin.network.vpn.index')
                ->with('success', "VPN Tunnel '{$tunnel->name}' updated.");

        } catch (\Exception $e) {
            DB::rollBack();
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    public function destroy(VpnTunnel $tunnel)
    {
        try {
            $name = $tunnel->name;
            $this->vpnService->down($name);
            $this->vpnService->removeConfig($tunnel);
            $tunnel->delete();
            $this->vpnService->reload();

            \App\Models\ActivityLog::log('VPN', "Deleted VPN tunnel '{$name}'", 'danger');

            return redirect()->route('admin.network.vpn.index')
                ->with('success', "VPN Tunnel '{$name}' deleted.");
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function initiate(VpnTunnel $tunnel)
    {
        $result = $this->vpnService->up($tunnel->name);
        
        if ($result['status'] === 'success') {
            VpnLog::create([
                'vpn_id' => $tunnel->id,
                'event_type' => 'manual_up',
                'message' => "Initiated tunnel '{$tunnel->name}' manually."
            ]);

            \App\Models\ActivityLog::log('VPN', "Initiated VPN tunnel '{$tunnel->name}'", 'info', $tunnel->id);

            return back()->with('success', "Initiating tunnel '{$tunnel->name}'...");
        }

        return back()->with('error', "Failed to initiate tunnel: " . ($result['message'] ?? 'Unknown error'));
    }

    public function terminate(VpnTunnel $tunnel)
    {
        $result = $this->vpnService->down($tunnel->name);
        
        if ($result['status'] === 'success') {
            VpnLog::create([
                'vpn_id' => $tunnel->id,
                'event_type' => 'manual_down',
                'message' => "Terminated tunnel '{$tunnel->name}' manually."
            ]);

            \App\Models\ActivityLog::log('VPN', "Terminated VPN tunnel '{$tunnel->name}'", 'warning', $tunnel->id);

            return back()->with('success', "Terminating tunnel '{$tunnel->name}'...");
        }

        return back()->with('error', "Failed to terminate tunnel: " . ($result['message'] ?? 'Unknown error'));
    }

    public function reload()
    {
        $result = $this->vpnService->reload();

        // Redirect to index, not back() — a browser refresh on /reload would
        // re-issue a GET against the POST-only route and 405.
        $redirect = redirect()->route('admin.network.vpn.index');

        if ($result['status'] === 'success') {
            \App\Models\ActivityLog::log('VPN', "Reloaded all VPN configurations", 'info');
            return $redirect->with('success', "All VPN configurations reloaded successfully.");
        }

        return $redirect->with('error', "Reload failed: " . ($result['message'] ?? 'Unknown error'));
    }

    public function checkStatus(VpnTunnel $tunnel)
    {
        try {
            $result = $this->vpnService->status();

            // If swanctl is unavailable, don't mark tunnel as 'down' — preserve last known status
            if (($result['status'] ?? '') === 'unavailable' || ($result['swanctl_available'] ?? true) === false) {
                $tunnel->update(['last_checked_at' => now()]);

                // Check if the branch has a Sophos firewall with matching VPN data
                $sophosInfo = $this->getSophosVpnInfo($tunnel);

                return response()->json([
                    'is_up'             => $tunnel->status === 'up',
                    'swanctl_available' => false,
                    'last_known_status' => $tunnel->status,
                    'last_checked_at'   => $tunnel->last_checked_at?->diffForHumans(),
                    'sophosVpn'         => $sophosInfo,
                    'raw_output'        => '⚠️ IPSec service (swanctl) is not responding on this server. ' .
                                          'Check that strongSwan is installed and running. ' .
                                          'Displaying last known status: ' . strtoupper($tunnel->status ?? 'unknown'),
                ]);
            }

            $isEstablished = false;
            if ($result['status'] === 'success' && isset($result['output'])) {
                // More robust check: Look for the tunnel name on a line that also contains 'ESTABLISHED'
                // Typically: "tunnel_name: #1, ESTABLISHED, ..."
                $lines = explode("\n", $result['output']);
                foreach ($lines as $line) {
                    if (str_contains($line, $tunnel->name . ':') && str_contains($line, 'ESTABLISHED')) {
                        $isEstablished = true;
                        break;
                    }
                }
            }

            // Update DB status if it changed
            $newStatus = $isEstablished ? 'up' : 'down';
            if ($tunnel->status !== $newStatus) {
                $oldStatus = $tunnel->status;
                $tunnel->update(['status' => $newStatus, 'last_checked_at' => now()]);

                // Log to VpnLog for history
                VpnLog::create([
                    'vpn_id' => $tunnel->id,
                    'event_type' => 'status_change',
                    'message' => "Tunnel status changed from {$oldStatus} to {$newStatus}."
                ]);

                // Also log to general ActivityLog
                if (class_exists('App\Models\ActivityLog')) {
                    \App\Models\ActivityLog::log(
                        'VPN',
                        "VPN Tunnel '{$tunnel->name}' status changed to {$newStatus}",
                        'info',
                        $tunnel->id
                    );
                }
            } else {
                // Just update the last_checked_at
                $tunnel->update(['last_checked_at' => now()]);
            }

            return response()->json([
                'is_up'             => $isEstablished,
                'swanctl_available' => true,
                'sophosVpn'         => $this->getSophosVpnInfo($tunnel),
                'raw_output'        => $this->sanitizeLog($result['output'] ?? 'No status output available.'),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'is_up'             => false,
                'swanctl_available' => false,
                'raw_output'        => 'Error checking status: ' . $e->getMessage(),
            ]);
        }
    }

    public function showLogs()
    {
        try {
            $result = $this->vpnService->execute(['logs']);

            // Detect swanctl unavailable
            if (($result['status'] ?? '') === 'unavailable' || ($result['swanctl_available'] ?? true) === false) {
                return response()->json([
                    'status' => 'unavailable',
                    'logs'   => "⚠️ IPSec service (swanctl) is not responding on this server.\n" .
                                "Check that strongSwan is installed and running.\n" .
                                "Run: sudo systemctl status strongswan",
                ]);
            }

            $logText = $result['output'] ?? '';
            if (empty(trim($logText))) {
                $logText = 'No IPSec logs found. The log file may be empty or swanctl has not generated any output yet.';
            }

            return response()->json([
                'status' => $result['status'],
                'logs'   => $this->sanitizeLog($logText),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'logs'   => "Error retrieving logs: " . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Check if the branch linked to a VpnTunnel has a Sophos VPN tunnel that
     * can act as a secondary status indicator.
     */
    protected function getSophosVpnInfo(VpnTunnel $tunnel): ?array
    {
        try {
            if (!$tunnel->branch_id) return null;

            // Find Sophos firewalls for this branch
            $sophosVpn = SophosVpnTunnel::whereHas('firewall', function ($q) use ($tunnel) {
                $q->where('branch_id', $tunnel->branch_id);
            })->first();

            if (!$sophosVpn) return null;

            return [
                'name'           => $sophosVpn->name,
                'status'         => $sophosVpn->status,
                'remote_gateway' => $sophosVpn->remote_gateway,
                'last_checked'   => $sophosVpn->last_checked_at?->diffForHumans(),
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected function sanitizeLog($text)
    {
        if (!$text) return '';
        if (is_array($text)) $text = print_r($text, true);
        
        // Force UTF-8 and discard invalid sequences (aggressive)
        // Using //IGNORE to drop characters that can't be represented
        $clean = iconv('UTF-8', 'UTF-8//IGNORE', $text);
        
        // Final safety net to ensure JSON encoding never fails
        return mb_convert_encoding($clean, 'UTF-8', 'UTF-8');
    }
}
