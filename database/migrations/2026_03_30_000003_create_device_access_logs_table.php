<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_access_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained('devices')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('access_type', ['ssh', 'web', 'telnet'])->index();
            $table->string('action', 60);        // session_start, session_end, browse, connect, disconnect
            $table->string('client_ip', 45)->nullable();
            $table->json('meta')->nullable();    // extra context: path, duration, session_id, etc.
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_access_logs');
    }
};
