<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_lists', function (Blueprint $table) {
            // When set, the list is a "dynamic" list: its membership is
            // reconciled from the employees table by EmailObserver +
            // SyncDynamicListsCommand. Manual adds/removes from the portal
            // will be wiped on the next sync, so the UI hides those controls.
            $table->string('auto_domain', 191)->nullable()->after('double_opt_in');
            $table->index('auto_domain');
        });

        // Seed the two dynamic lists used by Samir Group. firstOrCreate-equivalent:
        // if a row with the same name already exists, just stamp the auto_domain.
        $now = now();
        foreach (
            [
                ['name' => 'Samir Group employees', 'domain' => 'samirgroup.com'],
                ['name' => 'SSS Egypt employees',   'domain' => 'sssegypt.com'],
            ] as $row
        ) {
            $existing = DB::table('email_lists')->where('name', $row['name'])->first();
            if ($existing) {
                DB::table('email_lists')
                    ->where('id', $existing->id)
                    ->update(['auto_domain' => $row['domain'], 'updated_at' => $now]);
            } else {
                DB::table('email_lists')->insert([
                    'name'          => $row['name'],
                    'description'   => "Auto-synced from employees with @{$row['domain']} address.",
                    'double_opt_in' => false,
                    'auto_domain'   => $row['domain'],
                    'created_at'    => $now,
                    'updated_at'    => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('email_lists', function (Blueprint $table) {
            $table->dropIndex(['auto_domain']);
            $table->dropColumn('auto_domain');
        });
    }
};
