<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->string('gdms_base_url')->nullable()->after('profile_phone_template');
            $table->string('gdms_client_id')->nullable()->after('gdms_base_url');
            $table->text('gdms_client_secret')->nullable()->after('gdms_client_id');
            $table->string('gdms_org_id')->nullable()->after('gdms_client_secret');
            $table->string('gdms_username')->nullable()->after('gdms_org_id');
            $table->string('gdms_password_hash')->nullable()->after('gdms_username');
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn([
                'gdms_base_url',
                'gdms_client_id',
                'gdms_client_secret',
                'gdms_org_id',
                'gdms_username',
                'gdms_password_hash',
            ]);
        });
    }
};
