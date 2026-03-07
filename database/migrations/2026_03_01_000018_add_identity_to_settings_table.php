<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->string('graph_tenant_id')->nullable()->after('meraki_polling_interval');
            $table->string('graph_client_id')->nullable()->after('graph_tenant_id');
            $table->text('graph_client_secret')->nullable()->after('graph_client_id');  // encrypted
            $table->string('graph_default_password')->nullable()->after('graph_client_secret');
            $table->string('graph_default_license_sku')->nullable()->after('graph_default_password');
            $table->boolean('identity_sync_enabled')->default(false)->after('graph_default_license_sku');
            $table->unsignedSmallInteger('identity_sync_interval')->default(60)->after('identity_sync_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn([
                'graph_tenant_id',
                'graph_client_id',
                'graph_client_secret',
                'graph_default_password',
                'graph_default_license_sku',
                'identity_sync_enabled',
                'identity_sync_interval',
            ]);
        });
    }
};
