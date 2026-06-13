<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sophos_central_access_points', function (Blueprint $table) {
            $table->id();
            $table->string('central_id')->unique();      // Sophos Central AP id
            $table->string('name')->nullable();           // label / name in Central
            $table->string('serial_number')->nullable()->index();
            $table->string('mac_address')->nullable();
            $table->string('model')->nullable();
            $table->string('firmware_version')->nullable();
            $table->string('status')->nullable()->index(); // online / offline / pending / …
            $table->string('site_id')->nullable();
            $table->string('site_name')->nullable();
            $table->string('ip_address')->nullable();
            $table->timestamp('central_last_seen_at')->nullable();
            $table->json('raw')->nullable();              // full Central payload for fields we don't map
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sophos_central_access_points');
    }
};
