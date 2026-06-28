<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_visits', function (Blueprint $table) {
            $table->id();
            $table->timestamp('visited_at')->index();
            $table->string('ip_address', 45)->nullable()->index();   // 45 = max IPv6 length
            $table->string('branch')->nullable()->index();           // resolved from CIDR map; 'unknown' fallback
            $table->text('user_agent')->nullable();
            $table->string('browser')->nullable();
            $table->string('platform')->nullable();
            $table->string('device_type')->nullable();               // desktop|mobile|tablet|bot|unknown
            $table->string('referrer')->nullable();
            $table->string('session_id', 64)->nullable()->index();   // cookie-based visitor id
            $table->boolean('is_unique_today')->default(false);
            $table->string('country')->nullable();                   // optional, only if GeoIP configured
            $table->string('city')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_visits');
    }
};
