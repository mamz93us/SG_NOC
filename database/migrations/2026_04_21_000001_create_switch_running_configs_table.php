<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('switch_running_configs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('device_id')->index();
            $table->string('device_name');
            $table->string('device_ip', 45)->index();
            $table->unsignedBigInteger('branch_id')->nullable()->index();
            // Full running-config text. MEDIUMTEXT on MySQL (16MB) to cover large chassis configs.
            $table->mediumText('config_text');
            // sha256 of config_text — used to dedupe and to detect drift.
            $table->string('config_hash', 64)->index();
            $table->unsignedInteger('size_bytes');
            $table->timestamp('captured_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('switch_running_configs');
    }
};
