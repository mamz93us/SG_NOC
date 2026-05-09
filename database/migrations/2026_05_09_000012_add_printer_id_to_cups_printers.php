<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cups_printers', function (Blueprint $table) {
            $table->unsignedBigInteger('printer_id')->nullable()->after('branch_id');

            $table->foreign('printer_id')->references('id')->on('printers')->nullOnDelete();
            $table->index('printer_id');
        });
    }

    public function down(): void
    {
        Schema::table('cups_printers', function (Blueprint $table) {
            $table->dropForeign(['printer_id']);
            $table->dropIndex(['printer_id']);
            $table->dropColumn('printer_id');
        });
    }
};
