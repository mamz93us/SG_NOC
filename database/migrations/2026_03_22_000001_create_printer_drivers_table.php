<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('printer_drivers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('printer_id')->nullable();
            $table->string('manufacturer', 100)->nullable();
            $table->string('model_pattern', 200)->nullable();
            $table->string('driver_name', 255);
            $table->string('inf_path', 500)->nullable();
            $table->string('driver_file_path', 500)->nullable();
            $table->string('original_filename', 255)->nullable();
            $table->string('os_type', 20)->default('windows_x64');
            $table->string('version', 50)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->timestamps();

            $table->foreign('printer_id')->references('id')->on('printers')->nullOnDelete();
            $table->foreign('uploaded_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('printer_drivers');
    }
};
