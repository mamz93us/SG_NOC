<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('printers', function (Blueprint $table) {
            $table->unsignedBigInteger('department_id')->nullable()->after('office_id')->index();
            $table->foreign('department_id')
                  ->references('id')->on('departments')
                  ->nullOnDelete();
        });

        Schema::table('devices', function (Blueprint $table) {
            $table->unsignedBigInteger('department_id')->nullable()->after('office_id')->index();
            $table->foreign('department_id')
                  ->references('id')->on('departments')
                  ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('printers', function (Blueprint $table) {
            $table->dropForeign(['department_id']);
            $table->dropColumn('department_id');
        });

        Schema::table('devices', function (Blueprint $table) {
            $table->dropForeign(['department_id']);
            $table->dropColumn('department_id');
        });
    }
};
