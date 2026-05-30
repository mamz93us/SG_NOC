<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->text('teamtailor_api_key')->nullable();        // encrypted at rest
            $table->string('teamtailor_base_url')->nullable();
            $table->string('teamtailor_api_version')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn([
                'teamtailor_api_key',
                'teamtailor_base_url',
                'teamtailor_api_version',
            ]);
        });
    }
};
