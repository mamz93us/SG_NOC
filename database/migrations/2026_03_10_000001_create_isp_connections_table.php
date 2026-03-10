<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('isp_connections', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('branch_id');
            $table->foreign('branch_id')->references('id')->on('branches')->onDelete('cascade');
            $table->string('provider');
            $table->string('circuit_id')->nullable();
            $table->integer('speed_down')->nullable();      // Mbps
            $table->integer('speed_up')->nullable();         // Mbps
            $table->string('static_ip')->nullable();
            $table->string('gateway')->nullable();
            $table->string('subnet')->nullable();
            $table->foreignId('router_device_id')->nullable()->constrained('devices')->nullOnDelete();
            $table->date('contract_start')->nullable();
            $table->date('contract_end')->nullable();
            $table->decimal('monthly_cost', 10, 2)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('branch_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('isp_connections');
    }
};
