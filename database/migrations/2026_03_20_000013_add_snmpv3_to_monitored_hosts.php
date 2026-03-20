<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitored_hosts', function (Blueprint $table) {
            $table->string('snmp_auth_user')->nullable()->after('snmp_community');
            $table->text('snmp_auth_password')->nullable()->after('snmp_auth_user');       // encrypted
            $table->string('snmp_auth_protocol')->default('sha')->after('snmp_auth_password'); // md5, sha, sha256
            $table->text('snmp_priv_password')->nullable()->after('snmp_auth_protocol');   // encrypted
            $table->string('snmp_priv_protocol')->default('aes')->after('snmp_priv_password'); // des, aes, aes256
            $table->string('snmp_security_level')->default('authPriv')->after('snmp_priv_protocol'); // noAuthNoPriv, authNoPriv, authPriv
            $table->string('snmp_context_name')->nullable()->after('snmp_security_level'); // optional context
        });
    }

    public function down(): void
    {
        Schema::table('monitored_hosts', function (Blueprint $table) {
            $table->dropColumn([
                'snmp_auth_user',
                'snmp_auth_password',
                'snmp_auth_protocol',
                'snmp_priv_password',
                'snmp_priv_protocol',
                'snmp_security_level',
                'snmp_context_name',
            ]);
        });
    }
};
