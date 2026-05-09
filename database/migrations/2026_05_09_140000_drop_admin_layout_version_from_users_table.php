<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remove the admin_layout_version column. The v2 sidebar layout was
 * removed; the welcome screen is now rendered inside the classic top-nav
 * for everyone, so the per-user layout flag is no longer needed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'admin_layout_version')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('admin_layout_version');
            });
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('admin_layout_version', 16)->default('classic')->after('dark_mode');
        });
    }
};
