<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vq_alert_events', function (Blueprint $table) {
            $table->id();
            $table->enum('source_type', ['voice','switch']);
            $table->string('source_ref')->nullable();
            $table->string('branch')->nullable();
            $table->string('metric')->nullable();
            $table->float('value')->nullable();
            $table->float('threshold')->nullable();
            $table->enum('severity', ['warning','critical'])->default('warning');
            $table->text('message')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vq_alert_events');
    }
};
