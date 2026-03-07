<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->string('upn_domain')->nullable()->after('smtp_from_name');
            $table->unsignedBigInteger('default_ucm_id')->nullable()->after('upn_domain');
            $table->unsignedInteger('ext_range_start')->default(1000)->after('default_ucm_id');
            $table->unsignedInteger('ext_range_end')->default(1999)->after('ext_range_start');
            $table->string('ext_default_secret')->nullable()->after('ext_range_end');
            $table->enum('ext_default_permission', ['internal', 'local', 'national', 'international'])->default('local')->after('ext_default_secret');
            $table->string('profile_office_template')->nullable()->after('ext_default_permission');
            $table->string('profile_phone_template')->nullable()->after('profile_office_template');

            $table->foreign('default_ucm_id')->references('id')->on('ucm_servers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropForeign(['default_ucm_id']);
            $table->dropColumn([
                'upn_domain', 'default_ucm_id', 'ext_range_start', 'ext_range_end',
                'ext_default_secret', 'ext_default_permission',
                'profile_office_template', 'profile_phone_template',
            ]);
        });
    }
};
