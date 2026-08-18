<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Many recipients per rule.
 *
 * A rule could previously name exactly one user (`recipient_user_id`) or one
 * role. Paging three people about a dead UCM meant three near-identical rows
 * on the Rules page, each with its own cooldown, and deleting one silently
 * dropped a recipient nobody noticed was there.
 *
 * `recipient_user_id` is kept, populated with the first selected user, so any
 * code or report still reading the column keeps working; resolution reads this
 * pivot first and only falls back to the column when the pivot is empty.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_rule_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notification_rule_id')->constrained('notification_rules')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['notification_rule_id', 'user_id'], 'notif_rule_recipient_unique');
        });

        // Backfill: every existing single-user rule becomes a one-row pivot,
        // so resolution can read the pivot uniformly from day one.
        $now = now();
        $rows = DB::table('notification_rules')
            ->where('recipient_type', 'user')
            ->whereNotNull('recipient_user_id')
            ->get(['id', 'recipient_user_id']);

        $userIds = DB::table('users')->pluck('id')->all();

        $insert = [];
        foreach ($rows as $row) {
            // A rule pointing at a deleted user would violate the FK.
            if (! in_array($row->recipient_user_id, $userIds)) {
                continue;
            }
            $insert[] = [
                'notification_rule_id' => $row->id,
                'user_id' => $row->recipient_user_id,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($insert, 500) as $chunk) {
            DB::table('notification_rule_recipients')->insert($chunk);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_rule_recipients');
    }
};
