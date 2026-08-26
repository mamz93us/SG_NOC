<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
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
    /** Rows per UPDATE. Small enough that each statement is over in microseconds. */
    private const UPDATE_CHUNK = 200;

    /** Owning-table ids per lookup query. */
    private const LOOKUP_CHUNK = 500;

    private const MAX_ATTEMPTS = 5;

    private const RETRY_BASE_MICROSECONDS = 100_000;

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
     * Push an id => branch_id map onto noc_events.
     *
     * Deliberately does NOT update by predicate. The obvious form --
     * `WHERE branch_id IS NULL AND source_type = ? AND source_id IN (...)` --
     * deadlocked against the live scheduler in production: before the backfill
     * runs `branch_id IS NULL` matches essentially every row, so InnoDB locks a
     * wide range while tunnel-health:watch and check-host-ping (both every
     * minute) plus the printer and access-point monitors are inserting into the
     * same table.
     *
     * Instead: resolve the target primary keys with a plain consistent read
     * (which takes no locks), then update small batches BY PRIMARY KEY. Each
     * statement then locks exactly the rows it changes and holds them for
     * microseconds.
     *
     * The `branch_id IS NULL` guard stays in the UPDATE as well as the SELECT,
     * so a producer that stamps a row between the two does not get overwritten,
     * and re-running after a partial failure is still a no-op for rows already
     * done.
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

            // Owning-table ids are chunked too: `IN (...)` over a few thousand
            // printers would make the read itself unwieldy.
            foreach (array_chunk($ids, self::LOOKUP_CHUNK) as $idChunk) {
                $eventIds = DB::table('noc_events')
                    ->whereNull('branch_id')
                    ->where($scope)
                    ->whereIn($key, $idChunk)
                    ->orderBy('id')
                    ->pluck('id');

                foreach ($eventIds->chunk(self::UPDATE_CHUNK) as $batch) {
                    $this->updateBatch($batch->all(), (int) $branchId);
                }
            }
        }
    }

    /**
     * One short, primary-key-scoped UPDATE, retried if it loses a deadlock.
     *
     * A deadlock is not an error here -- it means a producer touched one of
     * these rows at the same instant, and MySQL picked us as the victim. The
     * work is idempotent, so the correct response is to try again rather than
     * abort a migration that is already half applied.
     */
    private function updateBatch(array $eventIds, int $branchId): void
    {
        if ($eventIds === []) {
            return;
        }

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                DB::table('noc_events')
                    ->whereIn('id', $eventIds)
                    ->whereNull('branch_id')
                    ->update(['branch_id' => $branchId]);

                return;
            } catch (QueryException $e) {
                // 1213 deadlock, 1205 lock wait timeout. Anything else is a real
                // failure and must surface.
                if (! in_array((int) ($e->errorInfo[1] ?? 0), [1213, 1205], true)
                    || $attempt === self::MAX_ATTEMPTS) {
                    throw $e;
                }

                usleep(self::RETRY_BASE_MICROSECONDS * $attempt);
            }
        }
    }

    public function down(): void
    {
        // Nothing to reverse: the column itself is dropped by
        // 2026_08_25_100100, and there is no prior state to restore.
    }
};
