<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            if (! Schema::hasColumn('settings', 'vq_retention_days')) {
                $table->unsignedSmallInteger('vq_retention_days')->default(90)->after('metrics_retention_days');
            }
            if (! Schema::hasColumn('settings', 'switch_drop_retention_days')) {
                $table->unsignedSmallInteger('switch_drop_retention_days')->default(30)->after('vq_retention_days');
            }
            if (! Schema::hasColumn('settings', 'workflow_retention_days')) {
                $table->unsignedSmallInteger('workflow_retention_days')->default(365)->after('switch_drop_retention_days');
            }
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $cols = array_filter(
                ['vq_retention_days', 'switch_drop_retention_days', 'workflow_retention_days'],
                fn ($c) => Schema::hasColumn('settings', $c)
            );
            if ($cols) {
                $table->dropColumn(array_values($cols));
            }
        });
    }
};
