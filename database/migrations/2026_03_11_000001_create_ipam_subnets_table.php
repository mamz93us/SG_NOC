<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ipam_subnets', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('branch_id');
            $table->foreign('branch_id')->references('id')->on('branches')->onDelete('cascade');
            $table->integer('vlan')->nullable();
            $table->string('cidr');                          // e.g. "192.168.1.0/24"
            $table->string('gateway')->nullable();
            $table->string('description')->nullable();
            $table->string('source')->default('manual');     // manual, meraki, sophos
            $table->unsignedInteger('total_ips')->default(0);
            $table->timestamps();

            $table->unique(['branch_id', 'cidr']);
            $table->index('vlan');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ipam_subnets');
    }
};
