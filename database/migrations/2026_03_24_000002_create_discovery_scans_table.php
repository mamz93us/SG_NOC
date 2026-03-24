<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discovery_scans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('range_input');                        // e.g. 192.168.1.0/24 or 192.168.1.1-254
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('snmp_community')->default('public');
            $table->unsignedTinyInteger('snmp_timeout')->default(2); // seconds per host
            $table->enum('status', ['pending', 'running', 'completed', 'failed'])->default('pending');
            $table->unsignedSmallInteger('total_hosts')->default(0);
            $table->unsignedSmallInteger('reachable_count')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discovery_scans');
    }
};
