<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('browser_session_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Nullable so we can log events not tied to a session (e.g. permission-denied launches).
            $table->foreignId('browser_session_id')->nullable()->constrained('browser_sessions')->nullOnDelete();
            $table->string('session_id', 12)->nullable()->index();  // Denormalized copy so we can display history after the session row is deleted.
            $table->string('event_type', 48)->index();
            $table->string('message')->nullable();
            $table->json('metadata')->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('browser_session_events');
    }
};
