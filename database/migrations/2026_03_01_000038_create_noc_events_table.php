<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('noc_events', function (Blueprint $table) {
            $table->id();
            $table->enum('module', ['network', 'identity', 'voip', 'assets'])->index();
            $table->string('entity_type')->nullable();
            $table->string('entity_id')->nullable();
            $table->enum('severity', ['info', 'warning', 'critical'])->default('warning')->index();
            $table->string('title');
            $table->text('message');
            $table->timestamp('first_seen')->useCurrent();
            $table->timestamp('last_seen')->useCurrent();
            $table->enum('status', ['open', 'acknowledged', 'resolved'])->default('open')->index();
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['module', 'entity_type', 'entity_id']);
            $table->index(['status', 'severity']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('noc_events');
    }
};
