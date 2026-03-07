<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('printers', function (Blueprint $table) {
            $table->date('toner_last_changed')->nullable()->after('toner_model');
            $table->unsignedInteger('expected_page_yield')->nullable()->after('toner_last_changed');
            $table->date('last_service_date')->nullable()->after('expected_page_yield');
            $table->unsignedInteger('service_interval_days')->nullable()->default(90)->after('last_service_date');
        });
    }

    public function down(): void
    {
        Schema::table('printers', function (Blueprint $table) {
            $table->dropColumn([
                'toner_last_changed', 'expected_page_yield',
                'last_service_date', 'service_interval_days',
            ]);
        });
    }
};
