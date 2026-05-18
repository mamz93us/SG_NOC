<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accessories', function (Blueprint $table) {
            $table->string('asset_code', 64)->nullable()->after('id');
            $table->string('status', 20)->default('active')->index()->after('notes');
            $table->unsignedBigInteger('scrap_workflow_id')->nullable()->index()->after('status');
        });

        DB::transaction(function () {
            $codeService = new \App\Services\AssetCodeService;

            \App\Models\Accessory::whereNull('asset_code')
                ->orderBy('id')
                ->chunkById(200, function ($chunk) use ($codeService) {
                    foreach ($chunk as $accessory) {
                        $accessory->asset_code = $codeService->generateForAccessory();
                        $accessory->save();
                    }
                });
        });

        Schema::table('accessories', function (Blueprint $table) {
            $table->unique('asset_code');
        });
    }

    public function down(): void
    {
        Schema::table('accessories', function (Blueprint $table) {
            $table->dropUnique(['asset_code']);
            $table->dropIndex(['status']);
            $table->dropIndex(['scrap_workflow_id']);
            $table->dropColumn(['asset_code', 'status', 'scrap_workflow_id']);
        });
    }
};
