<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Default GDMS site/project used when claiming new devices from the NOC.
 * These mirror config('services.gdms.site_id' / 'project_id') and let an admin
 * set them from Settings → GDMS without editing .env.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->string('gdms_site_id')->nullable()->after('gdms_password_hash');
            $table->string('gdms_project_id')->nullable()->after('gdms_site_id');
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn(['gdms_site_id', 'gdms_project_id']);
        });
    }
};
