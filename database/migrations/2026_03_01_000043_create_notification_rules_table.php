<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_rules', function (Blueprint $table) {
            $table->id();
            $table->string('event_type', 50);
            $table->enum('recipient_type', ['role', 'user']);
            $table->string('recipient_role', 20)->nullable();
            $table->foreignId('recipient_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('send_email')->default(true);
            $table->boolean('send_in_app')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('event_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_rules');
    }
};
