<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Local record of every ticket the NOC submitted to the external ticketing
 * system. The ticketing system is the system of record — this table exists so
 * that (a) you can see what was raised from here without logging into it, and
 * (b) a failed submit leaves evidence instead of just a flash message.
 *
 * Failures are rows too (`status = failed`), which is the whole point.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('noc_tickets', function (Blueprint $table) {
            $table->id();
            // Remote ticket id — null while the call is in flight or if it failed.
            $table->unsignedBigInteger('ticket_id')->nullable()->index();

            $table->string('title', 255);
            $table->text('description')->nullable();

            $table->unsignedInteger('category_id')->nullable();
            $table->string('category_name', 120)->nullable();
            $table->unsignedInteger('subcategory_id')->nullable();
            $table->string('subcategory_name', 120)->nullable();
            $table->unsignedInteger('type_id')->nullable();
            $table->string('type_name', 120)->nullable();
            $table->unsignedInteger('priority_id')->nullable();
            $table->string('priority_name', 120)->nullable();
            $table->unsignedInteger('channel_id')->nullable();

            // Who the ticket is FOR (may differ from who pressed submit).
            $table->string('requester_email', 255)->index();
            $table->string('requester_name', 255)->nullable();
            $table->string('requester_azure_id', 36)->nullable();

            $table->string('attachment_name', 255)->nullable();
            $table->unsignedBigInteger('attachment_size')->nullable();

            // Who pressed submit.
            $table->unsignedBigInteger('submitted_by_user_id')->nullable()->index();
            $table->string('submitted_by_name', 255)->nullable();

            $table->string('status', 20)->default('created')->index(); // created | failed
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->text('error')->nullable();
            $table->json('response')->nullable();

            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('noc_tickets');
    }
};
