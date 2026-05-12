<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds avepoint_location (NAM/EUR/FRA/ARE/etc.) for multi-geo tenants.
 * Per AvePoint Graph API Documentation: the location query parameter
 * scopes results to a specific geographic region. Optional — leaving it
 * blank queries across all locations in the tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->string('avepoint_location', 20)->nullable()->after('avepoint_region');
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn('avepoint_location');
        });
    }
};
