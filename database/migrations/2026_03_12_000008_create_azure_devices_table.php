<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('azure_devices', function (Blueprint $table) {
            $table->id();
            $table->string('azure_device_id')->unique();
            $table->string('display_name');
            $table->string('device_type', 50)->nullable();
            $table->string('os', 50)->nullable();
            $table->string('os_version', 100)->nullable();
            $table->string('upn')->nullable();
            $table->string('serial_number', 100)->nullable()->index();
            $table->timestamp('enrolled_date')->nullable();
            $table->timestamp('last_sync_at')->nullable();
            $table->unsignedBigInteger('device_id')->nullable();
            $table->enum('link_status', ['unlinked', 'linked', 'pending', 'rejected'])->default('unlinked')->index();
            $table->json('raw_data')->nullable();
            $table->timestamps();

            $table->foreign('device_id')->references('id')->on('devices')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('azure_devices');
    }
};
