<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voice_quality_reports', function (Blueprint $table) {
            $table->id();
            $table->string('extension')->index();
            $table->string('remote_extension')->nullable();
            $table->string('remote_ip')->nullable();
            $table->string('branch')->nullable();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('codec')->nullable();
            $table->float('mos_lq')->nullable();
            $table->float('mos_cq')->nullable();
            $table->float('r_factor')->nullable();
            $table->float('jitter_avg')->nullable();
            $table->float('jitter_max')->nullable();
            $table->float('packet_loss')->nullable();
            $table->float('burst_loss')->nullable();
            $table->integer('rtt')->nullable()->comment('ms');
            $table->enum('quality_label', ['excellent','good','fair','poor','bad'])->nullable()->index();
            $table->timestamp('call_start')->nullable();
            $table->timestamp('call_end')->nullable();
            $table->integer('call_duration_seconds')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voice_quality_reports');
    }
};
