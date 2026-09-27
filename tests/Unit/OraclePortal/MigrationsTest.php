<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The four migrations this integration adds, applied for real.
 *
 * Worth a test of its own because they cannot be exercised the usual way: 18
 * migrations in this repo issue raw MySQL `ALTER TABLE … MODIFY COLUMN`, which
 * SQLite rejects, so `migrate` never completes here and a broken migration
 * would only show up on the production deploy.
 *
 * Each one is run twice. They are all guarded with `Schema::hasColumn` for the
 * reason the Samsung Wallet migration records: a partially-applied migration
 * once left production with the column present and no `migrations` row, and
 * without the guard the re-run dies on a duplicate column and the deploy stays
 * stuck.
 */
uses(Tests\TestCase::class);

function runPortalMigration(string $needle): void
{
    $matches = glob(database_path('migrations/*'.$needle.'*.php'));

    expect($matches)->not->toBeEmpty("migration matching {$needle} was not found");

    foreach ($matches as $path) {
        (require $path)->up();
    }
}

beforeEach(function () {
    foreach (['announcements', 'employees', 'hr_import_rows', 'hr_import_batches',
        'oracle_portal_settings', 'users'] as $table) {
        Schema::dropIfExists($table);
    }

    // hr_import_batches.uploaded_by is a real foreign key.
    Schema::create('users', function (Blueprint $t) {
        $t->id();
        $t->string('name');
        $t->timestamps();
    });
});

it('creates the portal settings table, and survives a re-run', function () {
    runPortalMigration('create_oracle_portal_settings_table');

    expect(Schema::hasTable('oracle_portal_settings'))->toBeTrue();
    expect(Schema::hasColumns('oracle_portal_settings', [
        'enabled', 'base_url', 'api_key',
        'sync_announcements', 'sync_employees', 'sync_vacations',
        'last_announcements_sync_at', 'last_employees_count',
    ]))->toBeTrue();

    runPortalMigration('create_oracle_portal_settings_table');

    expect(Schema::hasTable('oracle_portal_settings'))->toBeTrue();
});

it('adds the sync columns to announcements, and survives a re-run', function () {
    Schema::create('announcements', function (Blueprint $t) {
        $t->id();
        $t->string('title', 200);
        $t->text('body');
        $t->timestamps();
    });

    runPortalMigration('add_oracle_sync_to_announcements_table');
    // The unique index and the hasColumn guards both have to be idempotent.
    runPortalMigration('add_oracle_sync_to_announcements_table');

    expect(Schema::hasColumns('announcements', [
        'source', 'external_id', 'external_image_name', 'synced_at', 'synced_fields', 'removed_at',
    ]))->toBeTrue();
});

it('defaults existing announcements to manual, so the sync cannot reach them', function () {
    // The whole safety of the announcement sync rests on this default: every
    // row that already exists, and every row the admin form creates, is manual
    // without a line of controller code changing.
    Schema::create('announcements', function (Blueprint $t) {
        $t->id();
        $t->string('title', 200);
        $t->text('body');
        $t->timestamps();
    });

    DB::table('announcements')->insert(['title' => 'Written by hand', 'body' => 'x',
        'created_at' => now(), 'updated_at' => now()]);

    runPortalMigration('add_oracle_sync_to_announcements_table');

    expect(DB::table('announcements')->value('source'))->toBe('manual');
});

it('adds the Oracle fields to employees, and survives a re-run', function () {
    Schema::create('employees', function (Blueprint $t) {
        $t->id();
        $t->string('name');
        $t->timestamps();
    });

    runPortalMigration('add_oracle_portal_fields_to_employees_table');
    runPortalMigration('add_oracle_portal_fields_to_employees_table');

    expect(Schema::hasColumns('employees', [
        'name_ar', 'oracle_employee_category', 'oracle_person_id',
        'oracle_assignment_status', 'oracle_person_type', 'oracle_leaver_ignored_at',
    ]))->toBeTrue();
});

it('adds the Oracle fields to the staging tables, and survives a re-run', function () {
    foreach (['create_hr_import_batches', 'create_hr_import_rows'] as $name) {
        runPortalMigration($name);
    }

    runPortalMigration('add_oracle_portal_fields_to_hr_import_tables');
    runPortalMigration('add_oracle_portal_fields_to_hr_import_tables');

    expect(Schema::hasColumns('hr_import_batches', ['source', 'source_digest']))->toBeTrue();
    expect(Schema::hasColumns('hr_import_rows', [
        'person_id', 'person_name_ar', 'employee_category', 'assignment_status',
        'person_type', 'assignment_id', 'supervisor_name', 'manager_name', 'hire_date',
    ]))->toBeTrue();
});

it('defaults existing import batches to the sheet source', function () {
    foreach (['create_hr_import_batches', 'create_hr_import_rows'] as $name) {
        runPortalMigration($name);
    }

    DB::table('hr_import_batches')->insert(['filename' => 'empsg.xlsx', 'status' => 'parsed',
        'created_at' => now(), 'updated_at' => now()]);

    runPortalMigration('add_oracle_portal_fields_to_hr_import_tables');

    expect(DB::table('hr_import_batches')->value('source'))->toBe('sheet');
});
