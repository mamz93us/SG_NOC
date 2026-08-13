<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\VoiceMeshNode;
use Illuminate\Database\Seeder;

/**
 * Seeds the four branches the standalone prober was already configured for.
 * The UCM addresses match the targets TunnelProbeSeeder already probes, so the
 * NOC is known to reach them over the tunnels.
 *
 * Idempotent — firstOrCreate on `code`, so re-running never overwrites an entry
 * an operator has since edited in the UI.
 *
 * Passwords are NOT in this file. Set VOICE_MESH_SEED_PASSWORD in .env before
 * running, or leave it unset and fill each password in from the admin page —
 * a node with a placeholder password fails its own validation at the config
 * endpoint rather than silently producing a red matrix.
 *
 *     php artisan db:seed --class=VoiceMeshNodeSeeder
 */
class VoiceMeshNodeSeeder extends Seeder
{
    /** code => [name, ivr_ext, sip_server, sip_user, branch name to link] */
    private const NODES = [
        'CAI' => ['Cairo', '7076', '10.9.8.10', '6150', 'Cairo'],
        'JED' => ['Jeddah', '7071', '10.1.8.10', '1999', 'Jeddah'],
        'RYD' => ['Riyadh', '7072', '10.2.88.10', '2999', 'Riyadh'],
        'KBR' => ['Khobar', '7073', '10.3.0.10', '3999', 'Khobar'],
    ];

    public function run(): void
    {
        $password = (string) env('VOICE_MESH_SEED_PASSWORD', '');
        $branches = Branch::all();
        $order = 0;

        foreach (self::NODES as $code => [$name, $ivrExt, $sipServer, $sipUser, $branchName]) {
            $order++;

            if (VoiceMeshNode::where('code', $code)->exists()) {
                $this->command?->line("  · {$code} already present — left alone.");

                continue;
            }

            $branch = $branches->first(
                fn (Branch $b) => strcasecmp(trim($b->name), $branchName) === 0
            );

            VoiceMeshNode::create([
                'code' => $code,
                'name' => $name,
                'branch_id' => $branch?->id,
                'ivr_ext' => $ivrExt,
                'sip_server' => $sipServer,
                'sip_port' => 5060,
                'sip_user' => $sipUser,
                'sip_pass' => $password,
                'is_active' => $password !== '',
                'sort_order' => $order,
            ]);

            $this->command?->info(
                "  + {$code} ({$name}) — {$sipServer} as {$sipUser}, IVR {$ivrExt}"
                .($branch ? " → branch #{$branch->id}" : ' [no matching branch row]')
                .($password === '' ? ' [inactive: no password set]' : '')
            );
        }

        if ($password === '') {
            $this->command?->warn(
                'VOICE_MESH_SEED_PASSWORD was not set — nodes were created inactive. '
                .'Set each SIP password at /admin/network/voice-mesh, then activate them.'
            );
        }
    }
}
