<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Makes activity_logs usable as the audit record it is now being asked to be.
 *
 * Two columns:
 *  - actor_label: who acted when there is no user_id. Every unattended write
 *    (scheduler, queue, webhook) previously either wasn't logged at all or was
 *    logged against "the first user in the table" — see ActivityLog::log(),
 *    which falls back to `User::orderBy('id')->first()`. That silently
 *    attributes the 03:00 HR import to whoever signed up first.
 *  - model_label: a human name for the touched record, captured at write time.
 *    Without it the log page can only show `App\Models\Device #4117`, and the
 *    row it refers to may since have been deleted.
 *
 * And the indexes. /admin/activity-logs filters on user_id, action, model_type
 * and a created_at range and sorts by created_at — on a table with only
 * (model_type, model_id), every one of those was a full scan. This is also the
 * database that runs the queue, the cache and the sessions, so a scan here is
 * felt everywhere.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            if (! Schema::hasColumn('activity_logs', 'actor_label')) {
                $table->string('actor_label', 100)->nullable()->after('user_id');
            }

            if (! Schema::hasColumn('activity_logs', 'model_label')) {
                $table->string('model_label', 150)->nullable()->after('model_id');
            }
        });

        Schema::table('activity_logs', function (Blueprint $table) {
            // Sorted-newest-first listing, and the retention prune's WHERE.
            $table->index('created_at', 'activity_logs_created_at_index');
            // "What did this person do", the most-used filter on the page.
            $table->index(['user_id', 'created_at'], 'activity_logs_user_created_index');
            // The action filter, and the security-actions retention split.
            $table->index(['action', 'created_at'], 'activity_logs_action_created_index');
            // The model_type filter dropdown.
            $table->index(['model_type', 'created_at'], 'activity_logs_type_created_index');
        });
    }

    public function down(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->dropIndex('activity_logs_created_at_index');
            $table->dropIndex('activity_logs_user_created_index');
            $table->dropIndex('activity_logs_action_created_index');
            $table->dropIndex('activity_logs_type_created_index');
        });

        Schema::table('activity_logs', function (Blueprint $table) {
            $table->dropColumn(['actor_label', 'model_label']);
        });
    }
};
