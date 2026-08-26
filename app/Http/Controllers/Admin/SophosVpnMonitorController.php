<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SnmpSensor;
use App\Models\SophosFirewall;
use App\Services\Sophos\SophosVpnBoard;
use Illuminate\Http\Request;

/**
 * Operator control over which Sophos VPN tunnels are monitored.
 *
 * Two things live here:
 *
 *  - Muting a tunnel. The firewall's own "Active" flag already covers a tunnel
 *    switched off there, but a tunnel can be legitimately enabled and still not
 *    be something anyone wants paging them -- a spare link, a partner circuit
 *    someone else owns. That is an operator decision, so it needs a switch.
 *
 *  - Forgetting a retired tunnel. Discovery retires sensors it stops seeing;
 *    this is how you delete one for good once you are sure, accepting that its
 *    metric history goes with it.
 */
class SophosVpnMonitorController extends Controller
{
    public function __construct(private SophosVpnBoard $board) {}

    /**
     * Mute or unmute a tunnel.
     *
     * Both halves of the pair are set together. The Connection sensor is what
     * the board reads, but leaving the Active sensor polling on its own would be
     * a confusing half-state for anyone looking at the raw sensor list.
     */
    public function toggle(Request $request, SnmpSensor $sensor)
    {
        abort_unless($sensor->sensor_group === 'VPN', 404);

        $enabled = $request->boolean('monitor_enabled');

        foreach ($this->pairFor($sensor) as $row) {
            $row->forceFill(['monitor_enabled' => $enabled])->saveQuietly();
        }

        return back()->with('success', sprintf(
            'Tunnel "%s" is %s.',
            $sensor->vpnTunnelName() ?? $sensor->name,
            $enabled ? 'now monitored' : 'no longer monitored'
        ));
    }

    /**
     * Permanently delete a retired tunnel's sensors.
     *
     * Guarded on retired_at: a tunnel the firewall still reports must not be
     * deletable from here, because discovery would simply recreate it and the
     * only lasting effect would be the loss of its history.
     */
    public function forget(SnmpSensor $sensor)
    {
        abort_unless($sensor->sensor_group === 'VPN', 404);
        abort_unless($sensor->isRetired(), 422, 'Only retired tunnels can be removed.');

        $name = $sensor->vpnTunnelName() ?? $sensor->name;

        // Cascades to sensor_metrics and the rollups -- which is the point, and
        // why this is a deliberate action rather than something discovery does.
        foreach ($this->pairFor($sensor) as $row) {
            $row->delete();
        }

        return back()->with('success', "Removed retired tunnel \"{$name}\" and its history.");
    }

    /** The management view for one firewall's tunnels. */
    public function index(Request $request, SophosFirewall $firewall)
    {
        $hostId = $firewall->monitored_host_id;

        $tunnels = $hostId
            ? $this->board->tunnels(includeRetired: true, hostId: $hostId)
            : collect();

        return view('admin.network.sophos.vpn-monitoring', [
            'firewall' => $firewall,
            'tunnels' => $tunnels,
            'summary' => $this->board->summary($tunnels),
            // Without a linked monitored host there are no SNMP sensors to
            // manage, and saying so beats an empty table.
            'hasHost' => $hostId !== null,
        ]);
    }

    /**
     * Both sensors for the tunnel this one belongs to.
     *
     * @return \Illuminate\Support\Collection<int, SnmpSensor>
     */
    private function pairFor(SnmpSensor $sensor)
    {
        $name = $sensor->vpnTunnelName();

        if ($name === null) {
            return collect([$sensor]);
        }

        return SnmpSensor::where('host_id', $sensor->host_id)
            ->where('sensor_group', 'VPN')
            ->get()
            ->filter(fn (SnmpSensor $s) => $s->vpnTunnelName() === $name)
            ->values();
    }
}
