<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->unsignedBigInteger('ucm_server_id')->nullable()->after('phone_number');
            $table->unsignedInteger('ext_range_start')->nullable()->after('ucm_server_id');
            $table->unsignedInteger('ext_range_end')->nullable()->after('ext_range_start');
            $table->string('profile_office_template')->nullable()->after('ext_range_end');
            $table->string('profile_phone_template')->nullable()->after('profile_office_template');

            $table->foreign('ucm_server_id')
                  ->references('id')->on('ucm_servers')
                  ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->dropForeign(['ucm_server_id']);
            $table->dropColumn([
                'ucm_server_id', 'ext_range_start', 'ext_range_end',
                'profile_office_template', 'profile_phone_template',
            ]);
        });
    }
};
