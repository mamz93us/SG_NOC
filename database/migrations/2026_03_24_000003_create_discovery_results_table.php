<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discovery_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('discovery_scan_id')->constrained()->cascadeOnDelete();
            $table->string('ip_address', 45);
            $table->string('hostname')->nullable();
            $table->string('mac_address', 17)->nullable();
            $table->string('vendor')->nullable();
            $table->string('model')->nullable();
            $table->string('sys_name')->nullable();
            $table->text('sys_descr')->nullable();
            $table->enum('device_type', ['printer', 'switch', 'device', 'unknown'])->default('unknown');
            $table->boolean('is_reachable')->default(false);
            $table->boolean('snmp_accessible')->default(false);
            $table->boolean('already_imported')->default(false);
            $table->string('imported_type')->nullable();  // printer/switch/device
            $table->unsignedBigInteger('imported_id')->nullable();
            $table->json('raw_data')->nullable();
            $table->timestamps();

            $table->index(['discovery_scan_id', 'ip_address']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discovery_results');
    }
};
