<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets announcements come from Oracle's Employee Portal as well as from a
 * person typing one here.
 *
 * `source` defaults to 'manual', which is what makes this safe: every row that
 * already exists and every row the admin form creates from now on is manual
 * without a line of controller code changing, and the sync's queries are all
 * scoped to source='oracle', so a hand-written notice is structurally out of
 * its reach.
 *
 * The unique key is (source, external_id) rather than external_id alone:
 * MySQL allows many NULLs in a unique index, so manual rows are unaffected,
 * and a second feed later cannot collide with Oracle's ids.
 */
return new class extends Migration
{
    private const COLUMNS = [
        'source' => 'string',
        'external_id' => 'string',
        'external_image_name' => 'string',
        'synced_at' => 'timestamp',
        'synced_fields' => 'json',
        'removed_at' => 'timestamp',
    ];

    public function up(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            foreach (self::COLUMNS as $name => $type) {
                if (Schema::hasColumn('announcements', $name)) {
                    continue;
                }

                match ($name) {
                    // 'manual' | 'oracle'
                    'source' => $table->string('source', 20)->default('manual')->index(),
                    // Oracle's announcementId: 15 numeric digits, as a string.
                    'external_id' => $table->string('external_id', 40)->nullable(),
                    // The bare filename Oracle gives, Arabic and all. The bytes
                    // are not reachable — every non-/api path on that host is
                    // 403 — so this is the only record of which designed image
                    // the notice actually is.
                    'external_image_name' => $table->string('external_image_name', 255)->nullable(),
                    'synced_at' => $table->timestamp('synced_at')->nullable(),
                    // What the sync last wrote, so a later pull can tell its own
                    // handiwork from an edit someone made here and leave the
                    // edit alone.
                    'synced_fields' => $table->json('synced_fields')->nullable(),
                    // Gone from Oracle. Never deleted: announcement_reads has no
                    // FK cascade, so a delete would orphan read state, and an
                    // edited row is somebody's work.
                    'removed_at' => $table->timestamp('removed_at')->nullable(),
                };
            }
        });

        if (! $this->hasUniqueIndex()) {
            Schema::table('announcements', function (Blueprint $table) {
                $table->unique(['source', 'external_id'], 'announcements_source_external_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            if ($this->hasUniqueIndex()) {
                $table->dropUnique('announcements_source_external_unique');
            }

            foreach (array_keys(self::COLUMNS) as $name) {
                if (Schema::hasColumn('announcements', $name)) {
                    $table->dropColumn($name);
                }
            }
        });
    }

    private function hasUniqueIndex(): bool
    {
        return collect(Schema::getIndexes('announcements'))
            ->contains(fn ($index) => ($index['name'] ?? null) === 'announcements_source_external_unique');
    }
};
