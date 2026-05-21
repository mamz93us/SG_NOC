<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('isp_connections', function (Blueprint $table) {
            if (! Schema::hasColumn('isp_connections', 'currency')) {
                $table->string('currency', 3)->default('EGP')->after('monthly_cost');
            }
        });

        Schema::table('isp_providers', function (Blueprint $table) {
            if (! Schema::hasColumn('isp_providers', 'default_currency')) {
                $table->string('default_currency', 3)->default('EGP')->after('name');
            }
        });

        Schema::table('isp_provider_packages', function (Blueprint $table) {
            if (! Schema::hasColumn('isp_provider_packages', 'currency')) {
                $table->string('currency', 3)->default('EGP')->after('monthly_cost');
            }
        });
    }

    public function down(): void
    {
        Schema::table('isp_connections', function (Blueprint $table) {
            if (Schema::hasColumn('isp_connections', 'currency')) {
                $table->dropColumn('currency');
            }
        });

        Schema::table('isp_providers', function (Blueprint $table) {
            if (Schema::hasColumn('isp_providers', 'default_currency')) {
                $table->dropColumn('default_currency');
            }
        });

        Schema::table('isp_provider_packages', function (Blueprint $table) {
            if (Schema::hasColumn('isp_provider_packages', 'currency')) {
                $table->dropColumn('currency');
            }
        });
    }
};
