<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // SFTPGo device-backup-ingestion config. Credentials are encrypted by the
        // Setting model's mutators (text columns hold the ciphertext). The NOC
        // talks to SFTPGo's REST API at sftpgo_base_url (localhost-bound).
        Schema::table('settings', function (Blueprint $table) {
            $table->boolean('sftpgo_enabled')->default(false);
            $table->string('sftpgo_base_url')->nullable();
            $table->string('sftpgo_admin_username')->nullable();
            $table->text('sftpgo_admin_password')->nullable();   // encrypted
            $table->text('sftpgo_api_key')->nullable();          // encrypted
            $table->boolean('sftpgo_sftp_enabled')->default(true);
            $table->boolean('sftpgo_ftp_enabled')->default(false);
            $table->text('sftpgo_webhook_secret')->nullable();   // encrypted
            $table->unsignedInteger('sftpgo_default_quota_mb')->nullable();
            $table->string('sftpgo_home_root')->default('/srv/backups');
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn([
                'sftpgo_enabled',
                'sftpgo_base_url',
                'sftpgo_admin_username',
                'sftpgo_admin_password',
                'sftpgo_api_key',
                'sftpgo_sftp_enabled',
                'sftpgo_ftp_enabled',
                'sftpgo_webhook_secret',
                'sftpgo_default_quota_mb',
                'sftpgo_home_root',
            ]);
        });
    }
};
