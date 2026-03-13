<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            if (!Schema::hasColumn('settings', 'itam_asset_prefix')) {
                $table->string('itam_asset_prefix', 10)->nullable()->default('SG');
            }
            if (!Schema::hasColumn('settings', 'itam_code_padding')) {
                $table->unsignedTinyInteger('itam_code_padding')->default(6);
            }
            if (!Schema::hasColumn('settings', 'itam_company_url')) {
                $table->string('itam_company_url')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $columns = ['itam_asset_prefix', 'itam_code_padding', 'itam_company_url'];

            foreach ($columns as $column) {
                if (Schema::hasColumn('settings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
