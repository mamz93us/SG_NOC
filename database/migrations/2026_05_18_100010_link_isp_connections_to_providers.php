<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('isp_connections', function (Blueprint $table) {
            if (! Schema::hasColumn('isp_connections', 'isp_provider_id')) {
                $table->unsignedBigInteger('isp_provider_id')->nullable()->after('branch_id');
                $table->foreign('isp_provider_id')->references('id')->on('isp_providers')->nullOnDelete();
                $table->index('isp_provider_id');
            }

            if (! Schema::hasColumn('isp_connections', 'isp_provider_package_id')) {
                $table->unsignedBigInteger('isp_provider_package_id')->nullable()->after('isp_provider_id');
                $table->foreign('isp_provider_package_id')->references('id')->on('isp_provider_packages')->nullOnDelete();
                $table->index('isp_provider_package_id');
            }
        });

        // Backfill: create an IspProvider for every distinct existing provider
        // string, then point the connection at it. Packages are NOT backfilled
        // automatically — the admin sets those up per provider.
        $distinct = DB::table('isp_connections')
            ->whereNotNull('provider')
            ->where('provider', '!=', '')
            ->whereNull('isp_provider_id')
            ->select('provider')
            ->distinct()
            ->pluck('provider');

        foreach ($distinct as $name) {
            $providerId = DB::table('isp_providers')->where('name', $name)->value('id');
            if (! $providerId) {
                $providerId = DB::table('isp_providers')->insertGetId([
                    'name' => $name,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            DB::table('isp_connections')
                ->where('provider', $name)
                ->whereNull('isp_provider_id')
                ->update(['isp_provider_id' => $providerId]);
        }
    }

    public function down(): void
    {
        Schema::table('isp_connections', function (Blueprint $table) {
            if (Schema::hasColumn('isp_connections', 'isp_provider_package_id')) {
                $table->dropForeign(['isp_provider_package_id']);
                $table->dropIndex(['isp_provider_package_id']);
                $table->dropColumn('isp_provider_package_id');
            }
            if (Schema::hasColumn('isp_connections', 'isp_provider_id')) {
                $table->dropForeign(['isp_provider_id']);
                $table->dropIndex(['isp_provider_id']);
                $table->dropColumn('isp_provider_id');
            }
        });
    }
};
