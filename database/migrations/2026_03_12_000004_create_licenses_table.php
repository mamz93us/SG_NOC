<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('licenses', function (Blueprint $table) {
            $table->id();
            $table->string('license_name');
            $table->string('vendor')->nullable();
            $table->text('license_key')->nullable();
            $table->enum('license_type', ['subscription', 'perpetual', 'oem', 'freeware'])->default('perpetual');
            $table->date('purchase_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->decimal('cost', 15, 2)->nullable();
            $table->unsignedSmallInteger('seats')->default(1);
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('licenses');
    }
};
