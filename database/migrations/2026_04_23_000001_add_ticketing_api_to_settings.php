<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->string('ticketing_api_url', 500)->nullable()->after('workflow_retention_days');
            $table->text('ticketing_api_key')->nullable()->after('ticketing_api_url');
            $table->boolean('ticketing_api_enabled')->default(false)->after('ticketing_api_key');
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn(['ticketing_api_url', 'ticketing_api_key', 'ticketing_api_enabled']);
        });
    }
};
