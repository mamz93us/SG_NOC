<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('azure_devices', function (Blueprint $table) {
            $table->timestamp('last_activity_at')->nullable()->after('last_sync_at');
        });
    }

    public function down(): void
    {
        Schema::table('azure_devices', function (Blueprint $table) {
            $table->dropColumn('last_activity_at');
        });
    }
};
