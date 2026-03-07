<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('network_switches', function (Blueprint $table) {
            $table->id();
            $table->string('serial', 20)->unique();
            $table->string('network_id', 50)->nullable()->index();
            $table->string('network_name')->nullable();
            $table->string('name')->nullable();
            $table->string('model', 50)->nullable();
            $table->string('mac', 20)->nullable();
            $table->string('lan_ip', 45)->nullable();
            $table->string('firmware')->nullable();
            $table->string('status', 20)->default('unknown')->index(); // online/offline/alerting/unknown
            $table->unsignedSmallInteger('port_count')->default(0);
            $table->unsignedSmallInteger('clients_count')->default(0);
            $table->timestamp('last_reported_at')->nullable();
            $table->unsignedInteger('branch_id')->nullable();
            $table->json('raw_data')->nullable();
            $table->timestamps();

            $table->foreign('branch_id')->references('id')->on('branches')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('network_switches');
    }
};
