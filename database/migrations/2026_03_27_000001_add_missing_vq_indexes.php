<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('voice_quality_reports', function (Blueprint $table) {
            // Dashboard queries heavily filter on branch_id and (branch + created_at)
            if (! $this->indexExists('voice_quality_reports', 'voice_quality_reports_branch_id_index')) {
                $table->index('branch_id');
            }
            if (! $this->indexExists('voice_quality_reports', 'voice_quality_reports_branch_created_at_index')) {
                $table->index(['branch', 'created_at']);
            }
        });

        Schema::table('switch_drop_stats', function (Blueprint $table) {
            // Device detail + statistics queries filter heavily on device_ip + polled_at
            if (! $this->indexExists('switch_drop_stats', 'switch_drop_stats_device_ip_polled_at_index')) {
                $table->index(['device_ip', 'polled_at']);
            }
            if (! $this->indexExists('switch_drop_stats', 'switch_drop_stats_branch_polled_at_index')) {
                $table->index(['branch', 'polled_at']);
            }
        });
    }

    public function down(): void
    {
        Schema::table('voice_quality_reports', function (Blueprint $table) {
            $table->dropIndexIfExists('voice_quality_reports_branch_id_index');
            $table->dropIndexIfExists('voice_quality_reports_branch_created_at_index');
        });

        Schema::table('switch_drop_stats', function (Blueprint $table) {
            $table->dropIndexIfExists('switch_drop_stats_device_ip_polled_at_index');
            $table->dropIndexIfExists('switch_drop_stats_branch_polled_at_index');
        });
    }

    private function indexExists(string $table, string $index): bool
    {
        return collect(\Illuminate\Support\Facades\DB::select("SHOW INDEX FROM `{$table}`"))
            ->contains('Key_name', $index);
    }
};
