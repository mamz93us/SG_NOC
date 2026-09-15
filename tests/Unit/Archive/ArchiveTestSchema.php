<?php

namespace Tests\Unit\Archive;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The archive tables, built for tests on the `:memory:` connection.
 *
 * Not RefreshDatabase: 18 migrations in this repo issue raw MySQL DDL that
 * SQLite rejects, so the full set cannot run here — the same reason
 * Tests\Unit\Rbac\RbacTestSchema exists.
 *
 * Unlike that one, this does NOT restate the schema. It runs the archive
 * migration itself, so a column added there is covered here automatically and
 * the tests cannot drift from the real tables. Only the two NOC tables the
 * migration and the models lean on are built by hand:
 *
 *  - `users`, because archive_members has a foreign key to it;
 *  - `activity_logs`, because the AuditObserver is attached to every model at
 *    boot and writes there whenever a model is saved outside a
 *    withoutAuditing() block.
 *
 * The migration's FULLTEXT index is already guarded by a driver check, so it is
 * skipped on SQLite without anything special here.
 */
class ArchiveTestSchema
{
    /** @var array<int,string> Dropped newest-first so foreign keys unwind cleanly. */
    private const TABLES = [
        'archive_transfer_runs',
        'archive_tasks',
        'archive_access_logs',
        'archive_file_texts',
        'archive_files',
        'archive_document_values',
        'archive_documents',
        'archive_members',
        'archive_fields',
        'archives',
        'archive_sources',
    ];

    public static function create(): void
    {
        self::drop();

        if (! Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('email')->unique();
                $table->string('password')->nullable();
                $table->string('role', 50)->default('viewer');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('activity_logs')) {
            Schema::create('activity_logs', function (Blueprint $table) {
                $table->id();
                $table->string('model_type');
                $table->unsignedBigInteger('model_id');
                $table->string('model_label', 150)->nullable();
                $table->string('action');
                $table->json('changes')->nullable();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('actor_label', 100)->nullable();
                $table->string('ip_address')->nullable();
                $table->text('user_agent')->nullable();
                $table->timestamps();
            });
        }

        // Every archive migration, in file order, so a column added to the real
        // schema is covered here without anyone remembering to restate it.
        foreach (glob(database_path('migrations/*_archive_*.php')) ?: [] as $migration) {
            if (str_contains($migration, 'permissions')) {
                continue; // needs role_permissions, which these tests do not build
            }

            (require $migration)->up();
        }
    }

    public static function drop(): void
    {
        foreach (self::TABLES as $table) {
            Schema::dropIfExists($table);
        }
    }
}
