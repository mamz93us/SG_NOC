<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->boolean('sophos_sync_enabled')->default(false)->after('meraki_polling_interval');
            $table->unsignedSmallInteger('sophos_sync_interval')->default(15)->after('sophos_sync_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn(['sophos_sync_enabled', 'sophos_sync_interval']);
        });
    }
};
