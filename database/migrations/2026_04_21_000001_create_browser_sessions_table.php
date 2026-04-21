<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('browser_sessions', function (Blueprint $table) {
            $table->id();

            // Public-facing short id used in URLs (/s/{session_id}/) and container
            // names (neko-{session_id}). [a-z0-9]{12}.
            $table->string('session_id', 12)->unique();

            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            // Full docker container name, e.g. neko-<session_id>.
            $table->string('container_name')->unique();

            // Named docker volume used for Chromium profile persistence.
            // Reused across this user's sessions: neko-user-<user_id>.
            $table->string('volume_name');

            // 172.30.x.x bridge IP; read from `docker inspect` after start.
            $table->string('internal_ip', 45)->nullable();

            // WebRTC UDP port chunk reserved for this session out of 52000-52100.
            $table->unsignedSmallInteger('webrtc_port_start');
            $table->unsignedSmallInteger('webrtc_port_end');

            // starting -> running -> stopped, or error on launch failure.
            $table->enum('status', ['starting', 'running', 'stopped', 'error'])
                  ->default('starting');

            // Per-session Neko "user"-role password (admin password is env-global).
            // Stored as hash so leaks don't expose plaintext; we regenerate it
            // on every launch so the user only ever knows the current session's.
            $table->string('neko_user_password_hash')->nullable();

            $table->timestamp('last_active_at')->nullable();
            $table->timestamp('stopped_at')->nullable();
            $table->text('error_message')->nullable();

            $table->timestamps();

            $table->index(['status', 'last_active_at']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('browser_sessions');
    }
};
