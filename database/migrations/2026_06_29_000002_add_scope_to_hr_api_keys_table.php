<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_api_keys', function (Blueprint $table) {
            // null = legacy HR integration (unchanged behaviour)
            // 'hr'        = explicitly scoped to HR APIs
            // 'signature' = Signature API (Intune device script / Graph nightly job)
            $table->string('scope', 50)->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('hr_api_keys', function (Blueprint $table) {
            $table->dropColumn('scope');
        });
    }
};
