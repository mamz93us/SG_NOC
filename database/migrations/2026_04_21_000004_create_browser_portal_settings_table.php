<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('browser_portal_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('idle_minutes')->default(240);
            $table->unsignedTinyInteger('max_concurrent_sessions')->default(10);
            $table->unsignedSmallInteger('udp_port_range_start')->default(52000);
            $table->unsignedSmallInteger('udp_port_range_end')->default(52100);
            $table->unsignedTinyInteger('ports_per_session')->default(10);
            $table->string('neko_image', 191)->default('ghcr.io/m1k1o/neko/chromium:latest');
            $table->string('desktop_resolution', 32)->default('1920x1080@30');
            $table->boolean('auto_request_control')->default(true);
            $table->boolean('hide_neko_branding')->default(true);
            $table->timestamps();
        });

        DB::table('browser_portal_settings')->insert([
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('browser_portal_settings');
    }
};
