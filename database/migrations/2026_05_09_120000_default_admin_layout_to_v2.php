<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Make the new sidebar+palette layout the default. Existing users on
 * 'classic' are flipped to 'v2' so everyone gets the new experience
 * without having to find the toggle. They can opt back to 'classic'
 * from the v2 profile dropdown.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('admin_layout_version', 16)->default('v2')->change();
        });

        DB::table('users')
            ->where('admin_layout_version', 'classic')
            ->update(['admin_layout_version' => 'v2']);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('admin_layout_version', 16)->default('classic')->change();
        });
    }
};
