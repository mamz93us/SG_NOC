<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->boolean('meraki_enabled')->default(false)->after('sso_default_role');
            $table->text('meraki_api_key')->nullable()->after('meraki_enabled');       // encrypted
            $table->string('meraki_org_id', 100)->nullable()->after('meraki_api_key');
            $table->unsignedInteger('meraki_polling_interval')->default(15)->after('meraki_org_id'); // minutes
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn(['meraki_enabled', 'meraki_api_key', 'meraki_org_id', 'meraki_polling_interval']);
        });
    }
};
