<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Connection settings for the Samir Employee Portal API (Oracle HR).
 *
 * Its own singleton table, not columns on `settings`: that row is already at
 * roughly 64.7 KB of InnoDB's hard 65,535-byte limit, and the Samsung Wallet
 * migration died there on errno 1118. Same escape hatch as `ai_settings`.
 *
 * The API key is encrypted by the model's accessor pair, so this column holds
 * ciphertext and must be TEXT rather than a sized varchar.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('oracle_portal_settings')) {
            return;
        }

        Schema::create('oracle_portal_settings', function (Blueprint $table) {
            $table->id();

            $table->boolean('enabled')->default(false);
            $table->text('base_url')->nullable();
            $table->text('api_key')->nullable();

            // One switch per feed, so a misbehaving one can be stopped without
            // a deploy and without taking the other two down with it.
            $table->boolean('sync_announcements')->default(false);
            $table->boolean('sync_employees')->default(false);
            $table->boolean('sync_vacations')->default(false);

            $table->timestamp('last_announcements_sync_at')->nullable();
            $table->timestamp('last_employees_sync_at')->nullable();
            $table->timestamp('last_vacations_sync_at')->nullable();

            // What the last good pull of each feed counted. The half-size guard
            // measures against these, so a first run has nothing to compare and
            // is allowed through.
            $table->unsignedInteger('last_announcements_count')->nullable();
            $table->unsignedInteger('last_employees_count')->nullable();
            $table->unsignedInteger('last_vacation_balances_count')->nullable();
            $table->unsignedInteger('last_vacation_records_count')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oracle_portal_settings');
    }
};
