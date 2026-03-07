<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
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
        return view('admin.network.vpn.create', compact('branches', 'defaultLocalSubnet'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'branch_id'        => 'required|exists:branches,id',
            'name'             => 'required|string|max:255|unique:vpn_tunnels,name|regex:/^[a-zA-Z0-9_]+$/',
            'remote_public_ip' => 'required|ip',
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

            $tunnel = VpnTunnel::create($request->all());

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
        $request->validate([
            'branch_id'        => 'required|exists:branches,id',
            'name'             => 'required|string|max:255|unique:vpn_tunnels,name,' . $tunnel->id . '|regex:/^[a-zA-Z0-9_]+$/',
            'remote_public_ip' => 'required|ip',
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
            $data = $request->all();
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
            return back()->with('success', "Initiating tunnel '{$tunnel->name}'...");
        }

        return back()->with('error', "Failed to initiate tunnel: " . ($result['message'] ?? 'Unknown error'));
    }

    public function terminate(VpnTunnel $tunnel)
    {
        $result = $this->vpnService->down($tunnel->name);
        
        if ($result['status'] === 'success') {
            return back()->with('success', "Terminating tunnel '{$tunnel->name}'...");
        }

        return back()->with('error', "Failed to terminate tunnel: " . ($result['message'] ?? 'Unknown error'));
    }

    public function reload()
    {
        $result = $this->vpnService->reload();
        
        if ($result['status'] === 'success') {
            return back()->with('success', "All VPN configurations reloaded successfully.");
        }

        return back()->with('error', "Reload failed: " . ($result['message'] ?? 'Unknown error'));
    }

    public function checkStatus(VpnTunnel $tunnel)
    {
        try {
            $result = $this->vpnService->status();
            
            // Simple logic to find if our tunnel is in the output
            $isEstablished = false;
            if ($result['status'] === 'success' && isset($result['output'])) {
                $isEstablished = str_contains($result['output'], $tunnel->name) && str_contains($result['output'], 'ESTABLISHED');
            }

            // Update DB status if it changed
            $newStatus = $isEstablished ? 'up' : 'down';
            if ($tunnel->status !== $newStatus) {
                $tunnel->update(['status' => $newStatus, 'last_checked_at' => now()]);
            }

            return response()->json([
                'is_up' => $isEstablished,
                'raw_output' => $this->sanitizeLog($result['output'] ?? 'No status output available.')
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'is_up' => false,
                'raw_output' => 'Error checking status: ' . $e->getMessage()
            ]);
        }
    }

    public function showLogs()
    {
        try {
            $result = $this->vpnService->execute(['logs']);
            
            return response()->json([
                'status' => $result['status'],
                'logs'   => $this->sanitizeLog($result['output'] ?? 'No logs available.')
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'logs'   => "Error retrieving logs: " . $e->getMessage()
            ], 500);
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
