<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit log of every hit to the signature API — who (which mailbox/UPN) requested a
 * signature and what NOC served back (template, resolved name, domain, status). Powers
 * the "Signature Request Log" admin page. One row per API call; no updated_at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signature_request_logs', function (Blueprint $table) {
            $table->id();
            $table->string('upn')->nullable()->index();           // requested mailbox / UPN
            $table->string('endpoint', 32)->default('render');    // render | transport_rule | gender_members
            $table->string('type', 16)->nullable();               // new_email | reply
            $table->string('domain', 120)->nullable();
            $table->string('gender', 10)->nullable();
            $table->unsignedBigInteger('template_id')->nullable();
            $table->string('template_name')->nullable();
            $table->string('resolved_name')->nullable();          // name NOC actually served
            $table->string('status', 16)->default('ok')->index(); // ok | not_found | unauthorized | bad_request | error
            $table->string('ip', 45)->nullable();
            $table->unsignedBigInteger('api_key_id')->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signature_request_logs');
    }
};
