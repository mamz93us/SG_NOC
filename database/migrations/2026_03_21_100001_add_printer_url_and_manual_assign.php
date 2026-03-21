<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add printer_url to printers table
        Schema::table('printers', function (Blueprint $table) {
            $table->string('printer_url', 500)->nullable()->after('ip_address')
                  ->comment('Web admin/management URL for this printer, e.g. http://192.168.1.10');
        });

        // Manual employee ↔ printer assignment (admin can pin specific printers to specific users)
        Schema::dropIfExists('employee_printer');
        Schema::create('employee_printer', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('employee_id');
            $table->unsignedBigInteger('printer_id');
            $table->unsignedBigInteger('assigned_by')->nullable(); // admin user who assigned
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'printer_id']);
            $table->foreign('employee_id')->references('id')->on('employees')->cascadeOnDelete();
            $table->foreign('printer_id')->references('id')->on('printers')->cascadeOnDelete();
            $table->foreign('assigned_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('printers', function (Blueprint $table) {
            $table->dropColumn('printer_url');
        });
        Schema::dropIfExists('employee_printer');
    }
};
