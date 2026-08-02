<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-service "From" addresses, so onboarding/offboarding can send as
 * it@samirgroup.com while monitoring alerts send as noc@samirgroup.com,
 * instead of everything sharing the single global smtp_from_address.
 *
 * Rows are seeded here and edited in Admin → Sender Addresses. The service_key
 * set is fixed in code (App\Models\MailSender::SERVICES) — the table stores the
 * addresses, not the catalogue.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_senders', function (Blueprint $table) {
            $table->id();
            $table->string('service_key', 64)->unique();
            $table->string('from_address', 191)->nullable();
            $table->string('from_name', 191)->nullable();
            $table->string('reply_to', 191)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Seed one row per known service. Addresses start NULL, which means
        // "inherit the global default" — so behaviour is unchanged until an
        // admin actually fills one in.
        $now = now();
        $rows = collect([
            'onboarding',
            'offboarding',
            'workflows',
            'alerts',
            'printers',
            'notifications',
            'backups',
        ])->map(fn ($key) => [
            'service_key' => $key,
            'from_address' => null,
            'from_name' => null,
            'reply_to' => null,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        DB::table('mail_senders')->insert($rows);
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_senders');
    }
};
