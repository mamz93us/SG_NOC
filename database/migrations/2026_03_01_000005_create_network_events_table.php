<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('network_events', function (Blueprint $table) {
            $table->id();
            $table->string('network_id', 50)->nullable()->index();
            $table->string('switch_serial', 20)->nullable()->index();
            $table->string('event_type', 100)->nullable()->index();
            $table->timestamp('occurred_at')->nullable()->index();
            $table->text('description')->nullable();
            $table->json('details')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('network_events');
    }
};
