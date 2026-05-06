<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cache the most recent /api/stats response per branch so the index
     * page can show disk / DB / ingestion at a glance without making
     * the user click "Test" on every row.
     */
    public function up(): void
    {
        Schema::table('branch_log_collectors', function (Blueprint $table) {
            $table->unsignedTinyInteger('last_disk_used_pct')->nullable()->after('last_error');
            $table->decimal('last_db_size_gb', 8, 2)->nullable()->after('last_disk_used_pct');
            $table->unsignedInteger('last_db_rows')->nullable()->after('last_db_size_gb');
            $table->unsignedInteger('last_rows_5min')->nullable()->after('last_db_rows');
            $table->unsignedTinyInteger('last_ram_used_pct')->nullable()->after('last_rows_5min');
            $table->timestamp('last_stats_at')->nullable()->after('last_ram_used_pct');
        });
    }

    public function down(): void
    {
        Schema::table('branch_log_collectors', function (Blueprint $table) {
            $table->dropColumn([
                'last_disk_used_pct', 'last_db_size_gb', 'last_db_rows',
                'last_rows_5min', 'last_ram_used_pct', 'last_stats_at',
            ]);
        });
    }
};
