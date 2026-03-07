<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->boolean('sso_enabled')->default(false)->after('company_logo');
            $table->string('sso_tenant_id')->nullable()->after('sso_enabled');
            $table->string('sso_client_id')->nullable()->after('sso_tenant_id');
            $table->text('sso_client_secret')->nullable()->after('sso_client_id');
            $table->string('sso_default_role')->default('viewer')->after('sso_client_secret');
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn(['sso_enabled', 'sso_tenant_id', 'sso_client_id', 'sso_client_secret', 'sso_default_role']);
        });
    }
};
