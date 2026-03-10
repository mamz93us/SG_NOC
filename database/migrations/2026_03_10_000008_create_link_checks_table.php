<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('link_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('isp_id')->constrained('isp_connections')->onDelete('cascade');
            $table->float('latency')->nullable();          // ms
            $table->float('packet_loss')->default(0);      // percentage
            $table->boolean('success')->default(true);
            $table->timestamp('checked_at')->useCurrent();
            $table->timestamps();

            $table->index(['isp_id', 'checked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('link_checks');
    }
};
