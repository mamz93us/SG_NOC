<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('printer_maintenance_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('printer_id')->constrained('printers')->cascadeOnDelete();
            $table->enum('type', ['toner_change', 'repair', 'service', 'inspection']);
            $table->text('description')->nullable();
            $table->foreignId('performed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('performed_by_name')->nullable();
            $table->decimal('cost', 10, 2)->nullable();
            $table->date('performed_at');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('printer_id');
            $table->index('performed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('printer_maintenance_logs');
    }
};
