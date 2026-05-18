<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('identity_licenses', function (Blueprint $table) {
            $table->unsignedBigInteger('license_id')->nullable()->after('capability_status');
            $table->foreign('license_id')->references('id')->on('licenses')->nullOnDelete();
            $table->index('license_id');
        });
    }

    public function down(): void
    {
        Schema::table('identity_licenses', function (Blueprint $table) {
            $table->dropForeign(['license_id']);
            $table->dropIndex(['license_id']);
            $table->dropColumn('license_id');
        });
    }
};
