<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per device backup login ("user & password manager"). Each maps
        // 1:1 to an SFTPGo virtual user the NOC provisions over REST. The password
        // is encrypted by the model's mutators; monitoring fields are stamped by
        // the upload webhook (last_received_at) and the sweeper (last_archived_at).
        Schema::create('backup_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('device_type')->nullable();          // morph: linked device class
            $table->unsignedBigInteger('device_id')->nullable();
            $table->string('label')->nullable();                // WHM / non-inventory sources
            $table->string('sftpgo_username')->unique();
            $table->text('password')->nullable();               // encrypted
            $table->json('protocols')->nullable();              // ['SFTP','FTP']
            $table->string('home_dir')->nullable();
            $table->unsignedInteger('quota_mb')->nullable();
            $table->string('expected_frequency')->default('daily'); // daily|weekly|monthly|manual
            $table->unsignedInteger('grace_minutes')->default(0);
            $table->timestamp('last_received_at')->nullable();
            $table->timestamp('last_archived_at')->nullable();
            $table->string('last_status')->nullable();          // received|archived|overdue|failed
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['device_type', 'device_id']);
            $table->index('is_active');
            $table->index('last_archived_at');
            $table->index('last_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_accounts');
    }
};
