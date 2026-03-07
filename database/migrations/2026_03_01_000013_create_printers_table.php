<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('printers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('device_id')->unique();   // 1-to-1 with devices
            $table->string('printer_name');
            $table->string('manufacturer')->nullable();
            $table->string('model')->nullable();
            $table->string('serial_number')->nullable()->index();
            $table->string('mac_address', 20)->nullable()->index();
            $table->string('ip_address', 45)->nullable()->index();
            $table->unsignedInteger('branch_id')->nullable()->index();
            $table->string('floor')->nullable();
            $table->string('room')->nullable();
            $table->string('department')->nullable()->index();
            $table->string('toner_model')->nullable();
            // SNMP placeholder for future monitoring
            $table->string('snmp_community')->nullable();
            $table->unsignedSmallInteger('snmp_version')->default(2);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('device_id')->references('id')->on('devices')->cascadeOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('printers');
    }
};
