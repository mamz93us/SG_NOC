<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('switch_drop_stats', function (Blueprint $table) {
            $table->id();
            $table->string('device_name')->index();
            $table->string('device_ip')->index();
            $table->string('branch')->nullable();
            $table->unsignedInteger('branch_id')->nullable();
            $table->foreign('branch_id')->references('id')->on('branches')->nullOnDelete();
            $table->string('interface_name')->nullable();
            $table->integer('interface_index')->nullable();
            $table->unsignedBigInteger('in_discards')->default(0);
            $table->unsignedBigInteger('out_discards')->default(0);
            $table->unsignedBigInteger('in_errors')->default(0);
            $table->unsignedBigInteger('out_errors')->default(0);
            $table->unsignedBigInteger('in_octets')->default(0);
            $table->unsignedBigInteger('out_octets')->default(0);
            $table->unsignedBigInteger('crc_errors')->default(0);
            $table->unsignedBigInteger('runts')->default(0);
            $table->unsignedBigInteger('giants')->default(0);
            $table->timestamp('polled_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('switch_drop_stats');
    }
};
