<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The migrations this change adds, exercised rather than assumed.
 *
 * The backfill in particular has to be right the first time: it runs once
 * against production data, and a mis-resolved source_type would attribute a live
 * incident to the wrong branch and cap the wrong branch's health score.
 *
 * Each migration is required and run directly, so this works without the full
 * migrate that MySQL-only DDL elsewhere in the repo makes impossible on SQLite.
 */
uses(Tests\TestCase::class);

function runMigration(string $glob): void
{
    $matches = glob(database_path('migrations/'.$glob));

    expect($matches)->not->toBeEmpty("no migration matched {$glob}");

    (require $matches[0])->up();
}

// ─────────────────────────────────────────────────────────────────────

it('adds durable health columns to ucm_servers', function () {
    Schema::dropIfExists('ucm_servers');
    Schema::create('ucm_servers', function (Blueprint $t) {
        $t->id();
        $t->string('name');
        $t->boolean('is_active')->default(true);
        $t->timestamps();
    });

    runMigration('*_add_health_columns_to_ucm_servers.php');

    foreach (['last_health_ok', 'last_health_at', 'last_health_error'] as $column) {
        expect(Schema::hasColumn('ucm_servers', $column))->toBeTrue("missing {$column}");
    }

    // Nullable, so existing rows survive the migration unflagged rather than
    // being defaulted to "healthy" or "down" before anything has checked.
    DB::table('ucm_servers')->insert(['name' => 'UCM', 'is_active' => true]);
    expect(DB::table('ucm_servers')->value('last_health_ok'))->toBeNull();
});

it('adds a branch_id to noc_events at the width branches.id actually uses', function () {
    Schema::dropIfExists('noc_events');
    Schema::dropIfExists('branches');

    Schema::create('branches', function (Blueprint $t) {
        $t->unsignedInteger('id')->primary();
        $t->string('name');
        $t->timestamps();
    });
    Schema::create('noc_events', function (Blueprint $t) {
        $t->id();
        $t->string('module', 32)->nullable();
        $t->string('source_type')->nullable();
        $t->string('source_id')->nullable();
        $t->string('status')->default('open');
        $t->timestamps();
    });

    runMigration('*_add_branch_id_to_noc_events.php');

    expect(Schema::hasColumn('noc_events', 'branch_id'))->toBeTrue();

    // branches.id is an unsignedInteger, so the FK must be too — foreignId()
    // emits a bigint and MySQL rejects the constraint with errno 3780.
    DB::table('branches')->insert(['id' => 5, 'name' => 'Jeddah']);
    DB::table('noc_events')->insert(['module' => 'vpn', 'branch_id' => 5, 'status' => 'open']);

    expect(DB::table('noc_events')->value('branch_id'))->toBe(5);
});

it('leaves module writable with the values the codebase emits', function () {
    Schema::dropIfExists('noc_events');
    Schema::create('noc_events', function (Blueprint $t) {
        $t->id();
        $t->string('module', 32)->nullable();
        $t->timestamps();
    });

    // No-ops on SQLite by design; the guard is what keeps the test suite able to
    // run at all, unlike the unguarded 2026_04_27 version of this same change.
    runMigration('*_widen_noc_events_module_to_varchar_50.php');

    DB::table('noc_events')->insert(['module' => 'access_point']);
    expect(DB::table('noc_events')->value('module'))->toBe('access_point');
});

it('registers the biometric asset type idempotently', function () {
    Schema::dropIfExists('asset_types');
    Schema::create('asset_types', function (Blueprint $t) {
        $t->id();
        $t->string('slug', 30)->unique();
        $t->string('label', 60);
        $t->string('icon', 60)->default('bi-cpu');
        $t->string('badge_class', 60)->default('bg-secondary');
        $t->string('category_code', 5);
        $t->boolean('is_user_equipment')->default(false);
        $t->string('group')->default('other');
        $t->unsignedSmallInteger('sort_order')->default(100);
        $t->timestamps();
    });

    // Run twice: asset_types is seeded from a migration rather than a seeder, so
    // this may well meet a database that already has the row.
    runMigration('*_add_biometric_asset_type.php');
    runMigration('*_add_biometric_asset_type.php');

    $rows = DB::table('asset_types')->where('slug', 'biometric')->get();

    expect($rows)->toHaveCount(1);
    expect($rows->first()->label)->toBe('Biometric Device')
        ->and($rows->first()->category_code)->toBe('BIO')
        ->and($rows->first()->group)->toBe('infrastructure')
        ->and($rows->first()->icon)->toBe('bi-fingerprint');
});

// ─────────────────────────────────────────────────────────────────────
// The backfill
// ─────────────────────────────────────────────────────────────────────

it('backfills branch_id from every producer table it can resolve', function () {
    foreach (['noc_events', 'branch_tunnels', 'access_points', 'printers',
        'monitored_hosts', 'sophos_firewalls', 'sophos_central_firewalls', 'branches'] as $t) {
        Schema::dropIfExists($t);
    }

    Schema::create('branches', function (Blueprint $t) {
        $t->unsignedInteger('id')->primary();
        $t->string('name');
    });
    foreach (['branch_tunnels', 'access_points', 'printers', 'monitored_hosts', 'sophos_firewalls'] as $table) {
        Schema::create($table, function (Blueprint $t) {
            $t->id();
            $t->unsignedInteger('branch_id')->nullable();
        });
    }
    Schema::create('sophos_central_firewalls', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('sophos_firewall_id')->nullable();
    });
    Schema::create('noc_events', function (Blueprint $t) {
        $t->id();
        $t->string('module', 50)->nullable();
        $t->unsignedInteger('branch_id')->nullable();
        $t->string('entity_type')->nullable();
        $t->string('entity_id')->nullable();
        $t->string('source_type')->nullable();
        $t->string('source_id')->nullable();
        $t->string('status')->default('open');
    });

    DB::table('branches')->insert([['id' => 1, 'name' => 'One'], ['id' => 2, 'name' => 'Two']]);
    DB::table('branch_tunnels')->insert(['id' => 10, 'branch_id' => 1]);
    DB::table('access_points')->insert(['id' => 20, 'branch_id' => 2]);
    DB::table('printers')->insert(['id' => 30, 'branch_id' => 1]);
    DB::table('monitored_hosts')->insert(['id' => 40, 'branch_id' => 2]);
    DB::table('sophos_firewalls')->insert(['id' => 50, 'branch_id' => 1]);
    DB::table('sophos_central_firewalls')->insert(['id' => 60, 'sophos_firewall_id' => 50]);

    $rows = [
        ['source_type' => 'tunnel_down', 'source_id' => '10'],
        ['source_type' => 'tunnel_degraded', 'source_id' => '10'],
        ['source_type' => 'access_point_down', 'source_id' => '20'],
        ['source_type' => 'printer', 'source_id' => '30'],
        ['source_type' => 'host_down', 'source_id' => '40'],
        // Central firewalls reach a branch only through the local firewall they
        // were matched to by serial.
        ['source_type' => 'sophos_central_fw_disconnected', 'source_id' => '60'],
        // NocAlertEngine writes module/entity_* and never source_type.
        ['module' => 'network', 'entity_type' => 'firewall', 'entity_id' => '50'],
    ];

    foreach ($rows as $row) {
        DB::table('noc_events')->insert($row + [
            'module' => null, 'entity_type' => null, 'entity_id' => null,
            'source_type' => null, 'source_id' => null,
        ]);
    }

    runMigration('*_backfill_noc_events_branch_id.php');

    $resolved = DB::table('noc_events')->pluck('branch_id')->all();

    expect($resolved)->toBe([1, 1, 2, 1, 2, 1, 1]);
});

it('leaves genuinely global events unscoped rather than guessing', function () {
    Schema::dropIfExists('noc_events');
    Schema::create('noc_events', function (Blueprint $t) {
        $t->id();
        $t->string('module', 50)->nullable();
        $t->unsignedInteger('branch_id')->nullable();
        $t->string('entity_type')->nullable();
        $t->string('entity_id')->nullable();
        $t->string('source_type')->nullable();
        $t->string('source_id')->nullable();
    });

    DB::table('noc_events')->insert([
        // A Central advisory with a GUID source_id that maps to no local firewall.
        ['source_type' => 'sophos_central_alert', 'source_id' => 'f4c1-9ab2-guid'],
        // A source type the backfill knows nothing about.
        ['source_type' => 'backup_overdue', 'source_id' => '1'],
    ]);

    runMigration('*_backfill_noc_events_branch_id.php');

    expect(DB::table('noc_events')->whereNotNull('branch_id')->count())->toBe(0);
});

it('never overwrites a branch_id that a producer already stamped', function () {
    Schema::dropIfExists('noc_events');
    Schema::dropIfExists('branch_tunnels');

    Schema::create('branch_tunnels', function (Blueprint $t) {
        $t->id();
        $t->unsignedInteger('branch_id')->nullable();
    });
    Schema::create('noc_events', function (Blueprint $t) {
        $t->id();
        $t->string('module', 50)->nullable();
        $t->unsignedInteger('branch_id')->nullable();
        $t->string('entity_type')->nullable();
        $t->string('entity_id')->nullable();
        $t->string('source_type')->nullable();
        $t->string('source_id')->nullable();
    });

    DB::table('branch_tunnels')->insert(['id' => 10, 'branch_id' => 1]);
    // Already attributed — the migration must be a no-op for this row, which is
    // what makes it safe to re-run.
    DB::table('noc_events')->insert(['source_type' => 'tunnel_down', 'source_id' => '10', 'branch_id' => 9]);

    runMigration('*_backfill_noc_events_branch_id.php');
    runMigration('*_backfill_noc_events_branch_id.php');

    expect(DB::table('noc_events')->value('branch_id'))->toBe(9);
});
