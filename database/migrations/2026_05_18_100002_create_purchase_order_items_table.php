<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->cascadeOnDelete();
            $table->enum('line_type', ['device', 'accessory', 'license']);
            $table->unsignedBigInteger('asset_id')->nullable();
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->decimal('unit_cost', 12, 2)->default(0);
            $table->unsignedInteger('branch_id')->nullable();

            // Shared fields
            $table->string('name');
            $table->string('manufacturer')->nullable();
            $table->string('model')->nullable();
            $table->string('serial_number')->nullable();

            // Device-only
            $table->string('device_type', 50)->nullable();

            // Accessory-only
            $table->string('category', 50)->nullable();

            // License-only
            $table->string('license_type', 30)->nullable();
            $table->unsignedInteger('seats')->nullable();
            $table->date('expiry_date')->nullable();

            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['purchase_order_id', 'line_type']);
            $table->index('serial_number');
            $table->index('asset_id');
            $table->foreign('branch_id')->references('id')->on('branches')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_items');
    }
};
