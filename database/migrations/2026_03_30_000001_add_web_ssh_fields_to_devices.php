<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            // Web management interface
            $table->boolean('proxy_enabled')->default(false)->after('ip_address');
            $table->enum('web_protocol', ['http', 'https'])->default('http')->after('proxy_enabled');
            $table->unsignedSmallInteger('web_port')->default(80)->after('web_protocol');
            $table->string('web_path', 200)->default('/')->after('web_port');

            // SSH access
            $table->unsignedSmallInteger('ssh_port')->default(22)->after('web_path');
            $table->string('ssh_username', 100)->nullable()->after('ssh_port');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn(['proxy_enabled', 'web_protocol', 'web_port', 'web_path', 'ssh_port', 'ssh_username']);
        });
    }
};
