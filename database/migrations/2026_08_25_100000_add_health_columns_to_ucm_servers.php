<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Durable UCM reachability, so the NOC dashboard never has to ask a PBX
 * whether it is alive while rendering a page.
 *
 * SyncUcmExtensionsJob already talks to every active UCM every 15 seconds and,
 * until now, threw the outcome away — success was a Log::info and failure was a
 * Log::error in a per-server catch block. Nothing was written to the server row,
 * so the only way to know a UCM's state was IppbxApiService::getCachedStats(),
 * which makes a live HTTP call per server.
 *
 * These three columns turn that existing sweep into the health signal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ucm_servers', function (Blueprint $table) {
            $table->boolean('last_health_ok')->nullable()->after('is_active');
            $table->timestamp('last_health_at')->nullable()->after('last_health_ok');
            // Sanitized before it is stored — see SyncUcmExtensionsJob. API
            // errors can echo back credentials and must not land here.
            $table->string('last_health_error', 255)->nullable()->after('last_health_at');
        });
    }

    public function down(): void
    {
        Schema::table('ucm_servers', function (Blueprint $table) {
            $table->dropColumn(['last_health_ok', 'last_health_at', 'last_health_error']);
        });
    }
};
