<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ip_reservations', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('branch_id');
            $table->foreign('branch_id')->references('id')->on('branches')->onDelete('cascade');
            $table->string('ip_address');
            $table->string('subnet')->nullable();
            $table->string('device_type')->nullable();       // server, printer, switch, ap, phone, etc.
            $table->string('device_name')->nullable();
            $table->string('mac_address')->nullable();
            $table->integer('vlan')->nullable();
            $table->string('assigned_to')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['branch_id', 'ip_address']);
            $table->index('vlan');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ip_reservations');
    }
};
