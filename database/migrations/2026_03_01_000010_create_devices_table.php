<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['ucm', 'switch', 'router', 'firewall', 'ap', 'printer', 'server', 'other'])->index();
            $table->string('name');
            $table->string('model')->nullable();
            $table->string('serial_number')->nullable()->index();
            $table->string('mac_address', 20)->nullable()->index();
            $table->string('ip_address', 45)->nullable()->index();
            $table->unsignedInteger('branch_id')->nullable()->index();
            $table->string('location_description')->nullable();
            $table->text('notes')->nullable();

            // Source linkage — tracks which module "owns" this record
            $table->enum('source', ['manual', 'meraki', 'ucm', 'printer'])->default('manual')->index();
            $table->string('source_id')->nullable()->index(); // serial / Azure ID / etc.

            $table->enum('status', ['active', 'retired', 'maintenance'])->default('active')->index();
            $table->timestamps();

            $table->foreign('branch_id')->references('id')->on('branches')->nullOnDelete();
            $table->unique(['source', 'source_id']);     // prevent duplicates per source
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devices');
    }
};
