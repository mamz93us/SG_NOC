<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credential_access_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('credential_id')->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->enum('action', ['viewed', 'copied', 'created', 'edited', 'deleted']);
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->useCurrent()->index();

            // No updated_at — this is an immutable audit log
            $table->foreign('credential_id')->references('id')->on('credentials')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credential_access_logs');
    }
};
