<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('printer_alert_emails', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('noc_event_id');
            $table->unsignedBigInteger('printer_id');
            $table->json('to_emails');
            $table->json('cc_emails')->nullable();
            $table->string('subject', 255);
            $table->enum('status', ['sent', 'failed'])->default('sent');
            $table->text('error')->nullable();
            $table->timestamp('sent_at')->useCurrent();
            $table->timestamps();

            $table->foreign('noc_event_id')->references('id')->on('noc_events')->cascadeOnDelete();
            $table->foreign('printer_id')->references('id')->on('printers')->cascadeOnDelete();
            $table->index(['printer_id', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('printer_alert_emails');
    }
};
