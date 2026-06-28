<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_visits', function (Blueprint $table) {
            $table->id();
            $table->timestamp('occurred_at')->index();

            // Who. user_id is denormalised (no FK) plus a name/email snapshot so the
            // log survives user deletion and renders without a join.
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('user_name')->nullable();
            $table->string('user_email')->nullable();

            $table->string('app', 16)->index();          // noc | em | portal
            $table->string('event', 16)->index();         // login | access
            $table->string('path')->nullable();

            $table->string('ip_address', 45)->nullable()->index();
            $table->string('branch')->nullable()->index(); // resolved from IP CIDR map; 'unknown' fallback
            $table->text('user_agent')->nullable();
            $table->string('browser')->nullable();
            $table->string('platform')->nullable();
            $table->string('device_type')->nullable();
            $table->string('session_id', 64)->nullable()->index();

            $table->timestamps();

            $table->index(['app', 'occurred_at']);
            $table->index(['user_id', 'app']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_visits');
    }
};
