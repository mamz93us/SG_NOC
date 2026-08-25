<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Branch-scope NOC events.
 *
 * Until now an event could only be tied to a branch by resolving its
 * source_type/source_id (or entity_type/entity_id) pair back through whichever
 * table owned it — a different join per producer. NocController::branch()
 * already assumed a relation existed and called NocEvent::whereHas('branch'),
 * which threw, because neither the column nor the relation was ever added.
 *
 * The branch health score needs "does this branch have an open critical
 * firewall alert?" to be one indexed query, so the attribution moves onto the
 * row itself. Producers stamp it; 2026_08_25_100300 backfills what already exists.
 *
 * NOTE: branches.id is unsignedInteger (manual, non-incrementing), so this must
 * be unsignedInteger too. foreignId() emits a bigint and MySQL rejects the
 * constraint with errno 3780.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('noc_events', function (Blueprint $table) {
            $table->unsignedInteger('branch_id')->nullable()->after('module');
            $table->index(['branch_id', 'status', 'severity'], 'noc_events_branch_status_sev_idx');
        });

        // Added separately so the column type is already settled — see the
        // width note above.
        Schema::table('noc_events', function (Blueprint $table) {
            $table->foreign('branch_id')->references('id')->on('branches')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('noc_events', function (Blueprint $table) {
            $table->dropForeign(['branch_id']);
            $table->dropIndex('noc_events_branch_status_sev_idx');
            $table->dropColumn('branch_id');
        });
    }
};
