<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            // Path (on the public disk) of an uploaded login/2FA wallpaper.
            // Null → the bundled default at public/images/login-bg.svg.
            $table->string('login_wallpaper')->nullable()->after('company_logo');
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn('login_wallpaper');
        });
    }
};
