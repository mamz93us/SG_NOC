<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->boolean('sophos_central_enabled')->default(false);
            $table->string('sophos_central_client_id')->nullable();
            $table->text('sophos_central_client_secret')->nullable();
            // Discovered via /whoami on first successful connection — cached here
            $table->string('sophos_central_tenant_id')->nullable();
            $table->string('sophos_central_data_region')->nullable();
            $table->unsignedInteger('sophos_central_sync_interval')->default(15);
            $table->boolean('sophos_central_alerts_enabled')->default(true);
            $table->timestamp('sophos_central_last_sync_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn([
                'sophos_central_enabled',
                'sophos_central_client_id',
                'sophos_central_client_secret',
                'sophos_central_tenant_id',
                'sophos_central_data_region',
                'sophos_central_sync_interval',
                'sophos_central_alerts_enabled',
                'sophos_central_last_sync_at',
            ]);
        });
    }
};
