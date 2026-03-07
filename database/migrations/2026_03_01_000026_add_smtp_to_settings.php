<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->string('smtp_host')->nullable()->after('identity_sync_interval');
            $table->unsignedSmallInteger('smtp_port')->default(587)->after('smtp_host');
            $table->enum('smtp_encryption', ['tls', 'ssl', 'none'])->default('tls')->after('smtp_port');
            $table->string('smtp_username')->nullable()->after('smtp_encryption');
            $table->text('smtp_password')->nullable()->after('smtp_username');
            $table->string('smtp_from_address')->nullable()->after('smtp_password');
            $table->string('smtp_from_name')->nullable()->after('smtp_from_address');
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn([
                'smtp_host', 'smtp_port', 'smtp_encryption',
                'smtp_username', 'smtp_password',
                'smtp_from_address', 'smtp_from_name',
            ]);
        });
    }
};
