<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitored_hosts', function (Blueprint $table) {
            $table->unsignedBigInteger('device_id')->nullable()->after('id');
            $table->foreign('device_id')->references('id')->on('devices')->nullOnDelete();
            $table->index('device_id');
        });
    }

    public function down(): void
    {
        Schema::table('monitored_hosts', function (Blueprint $table) {
            $table->dropForeign(['device_id']);
            $table->dropIndex(['device_id']);
            $table->dropColumn('device_id');
        });
    }
};
