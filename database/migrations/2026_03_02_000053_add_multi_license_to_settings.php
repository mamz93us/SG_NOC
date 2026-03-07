<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            // JSON array of multiple default license SKUs assigned on provisioning
            $table->json('graph_default_license_skus')->nullable()->after('graph_default_license_sku');
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn('graph_default_license_skus');
        });
    }
};
