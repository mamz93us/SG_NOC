<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_history', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('device_id')->index();
            $table->enum('event_type', [
                'created',
                'assigned',
                'returned',
                'maintenance',
                'repair',
                'retired',
                'disposed',
                'license_assigned',
                'license_removed',
                'note_added',
            ]);
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->text('description');
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->foreign('device_id')->references('id')->on('devices')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_history');
    }
};
