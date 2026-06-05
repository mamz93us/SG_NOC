<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Link each swept file back to the BackupAccount it came from (the sweeper
        // resolves this from the top folder = SFTPGo username). nullOnDelete so a
        // hard-purged account leaves its audit rows intact (account_id = null).
        Schema::table('sftp_backups', function (Blueprint $table) {
            $table->foreignId('account_id')->nullable()->after('id')
                ->constrained('backup_accounts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sftp_backups', function (Blueprint $table) {
            $table->dropForeign(['account_id']);
            $table->dropColumn('account_id');
        });
    }
};
