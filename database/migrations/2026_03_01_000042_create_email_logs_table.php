<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_logs', function (Blueprint $table) {
            $table->id();
            $table->string('to_email', 150);
            $table->string('to_name', 100)->nullable();
            $table->string('subject', 255);
            $table->string('notification_type', 50);
            $table->unsignedBigInteger('notification_id')->nullable()->index();
            $table->foreign('notification_id')->references('id')->on('notifications')->nullOnDelete();
            $table->enum('status', ['sent', 'failed']);
            $table->text('error_message')->nullable();
            $table->timestamp('sent_at')->useCurrent();

            $table->index(['notification_type', 'status']);
            $table->index('to_email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_logs');
    }
};
