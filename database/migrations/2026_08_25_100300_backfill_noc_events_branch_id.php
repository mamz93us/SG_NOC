<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Attribute the NOC events that already exist to their branch.
 *
 * Producers stamp branch_id from now on, including on the update path, so a
 * still-open event heals itself within a poll cycle. That is not enough on its
 * own: most producers dedup with firstOrCreate, so a long-running open incident
 * is never re-created and would keep a NULL branch_id indefinitely — leaving the
 * "critical firewall alert" cap silently inert for exactly the incidents that
 * matter most.
 *
 * Resolution is per source_type, back through whichever table owns the id.
 * Anything that cannot be resolved with confidence is left NULL: a genuinely
 * global alert must stay unscoped rather than be guessed onto a branch.
 *
 * Idempotent — only ever touches rows where branch_id IS NULL.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('noc_events', 'branch_id')) {
            return;
        }

        // source_type => [owning table, its branch column]
        $direct = [
            'tunnel_down' => ['branch_tunnels', 'branch_id'],
            'tunnel_degraded' => ['branch_tunnels', 'branch_id'],
            'access_point_down' => ['access_points', 'branch_id'],
            'printer' => ['printers', 'branch_id'],
            'cups_printer' => ['cups_printers', 'branch_id'],
            'host_down' => ['monitored_hosts', 'branch_id'],
            'monitored_host' => ['monitored_hosts', 'branch_id'],
        ];

        foreach ($direct as $sourceType => [$table, $column]) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $this->applyMap(
                DB::table($table)->whereNotNull($column)->pluck($column, 'id'),
                fn ($q) => $q->where('source_type', $sourceType)
            );
        }

        // Sophos Central firewalls carry no branch of their own — they reach one
        // only through the locally-managed firewall they were matched to by serial.
        if (Schema::hasTable('sophos_central_firewalls') && Schema::hasTable('sophos_firewalls')) {
            $this->applyMap(
                DB::table('sophos_central_firewalls as c')
                    ->join('sophos_firewalls as f', 'f.id', '=', 'c.sophos_firewall_id')
                    ->whereNotNull('f.branch_id')
                    ->pluck('f.branch_id', 'c.id'),
                fn ($q) => $q->where('source_type', 'sophos_central_fw_disconnected')
            );
        }

        // NocAlertEngine (Sophos sync failures) writes module/entity_* and never
        // source_type, so those rows need their own pass. entity_id is a string.
        if (Schema::hasTable('sophos_firewalls')) {
            $this->applyMap(
                DB::table('sophos_firewalls')->whereNotNull('branch_id')->pluck('branch_id', 'id'),
                fn ($q) => $q->where('module', 'network')->where('entity_type', 'firewall'),
                'entity_id'
            );
        }
    }

    /**
     * Push an id => branch_id map onto noc_events, grouped so it costs one
     * UPDATE per branch rather than one per event. Written with whereIn instead
     * of UPDATE..JOIN because the latter's syntax differs between MySQL and
     * SQLite and this has to run on both.
     *
     * @param  \Illuminate\Support\Collection<int|string, int>  $map
     */
    private function applyMap($map, callable $scope, string $key = 'source_id'): void
    {
        if ($map->isEmpty()) {
            return;
        }

        // preserveKeys is load-bearing: the map is keyed by the owning row's id
        // with the branch as the value, and groupBy() throws the keys away by
        // default -- which would leave $ids as 0,1,2... and match nothing.
        foreach ($map->groupBy(fn ($branchId) => $branchId, preserveKeys: true) as $branchId => $group) {
            $ids = $group->keys()->all();

            if ($key === 'entity_id') {
                $ids = array_map('strval', $ids);
            }

            DB::table('noc_events')
                ->whereNull('branch_id')
                ->where($scope)
                ->whereIn($key, $ids)
                ->update(['branch_id' => (int) $branchId]);
        }
    }

    public function down(): void
    {
        // Nothing to reverse: the column itself is dropped by
        // 2026_08_25_100100, and there is no prior state to restore.
    }
};
