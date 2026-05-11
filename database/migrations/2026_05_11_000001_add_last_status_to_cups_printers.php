<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cups_printers', function (Blueprint $table) {
            $table->string('last_status', 30)->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('cups_printers', function (Blueprint $table) {
            $table->dropColumn('last_status');
        });
    }
};
