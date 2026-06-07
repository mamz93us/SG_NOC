<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Microsoft SKUs such as POWER_BI_STANDARD / FLOW_FREE report 1,000,000
        // seats via Graph prepaidUnits — far past unsignedSmallInteger's 65,535
        // ceiling, which made the Azure license auto-create blow up with
        // SQLSTATE[22003] Out of range value for 'seats'. Widen to unsignedInteger.
        Schema::table('licenses', function (Blueprint $table) {
            $table->unsignedInteger('seats')->default(1)->change();
        });
    }

    public function down(): void
    {
        Schema::table('licenses', function (Blueprint $table) {
            $table->unsignedSmallInteger('seats')->default(1)->change();
        });
    }
};
