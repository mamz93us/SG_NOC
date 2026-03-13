<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accessories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('category', 50)->nullable();
            $table->unsignedInteger('quantity_total')->default(0);
            $table->unsignedInteger('quantity_available')->default(0);
            $table->unsignedBigInteger('supplier_id')->nullable();
            $table->decimal('purchase_cost', 15, 2)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('supplier_id')->references('id')->on('suppliers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accessories');
    }
};
