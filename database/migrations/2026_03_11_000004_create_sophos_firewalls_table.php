<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sophos_firewalls', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('branch_id')->nullable();
            $table->foreign('branch_id')->references('id')->on('branches')->nullOnDelete();
            $table->string('name');
            $table->string('ip');
            $table->unsignedSmallInteger('port')->default(4444);
            $table->string('serial_number')->nullable();
            $table->string('firmware_version')->nullable();
            $table->string('model')->nullable();
            $table->foreignId('monitored_host_id')->nullable()->constrained('monitored_hosts')->nullOnDelete();
            $table->text('api_username');                    // encrypted
            $table->text('api_password');                    // encrypted
            $table->boolean('sync_enabled')->default(true);
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique('ip');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sophos_firewalls');
    }
};
