<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('network_floors', function (Blueprint $table) {
            $table->unsignedInteger('ext_range_start')->nullable()->after('sort_order')
                  ->comment('First extension number available on this floor');
            $table->unsignedInteger('ext_range_end')->nullable()->after('ext_range_start')
                  ->comment('Last extension number available on this floor');
        });
    }

    public function down(): void
    {
        Schema::table('network_floors', function (Blueprint $table) {
            $table->dropColumn(['ext_range_start', 'ext_range_end']);
        });
    }
};
