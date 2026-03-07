<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('item_name', 100);
            $table->enum('item_type', ['laptop', 'desktop', 'phone', 'headset', 'tablet', 'keyboard', 'mouse', 'other'])->default('other');
            $table->string('serial_number', 100)->nullable();
            $table->string('model', 100)->nullable();
            $table->enum('condition', ['good', 'fair', 'poor'])->default('good');
            $table->date('assigned_date');
            $table->date('returned_date')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('employee_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_items');
    }
};
