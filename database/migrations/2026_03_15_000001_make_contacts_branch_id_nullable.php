<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            // Drop the existing foreign key first
            $table->dropForeign(['branch_id']);

            // Make branch_id nullable (some GDMS contacts may not map to a branch)
            $table->unsignedInteger('branch_id')->nullable()->change();

            // Re-add FK with nullOnDelete
            $table->foreign('branch_id')
                  ->references('id')
                  ->on('branches')
                  ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropForeign(['branch_id']);
            $table->unsignedInteger('branch_id')->nullable(false)->change();
            $table->foreign('branch_id')
                  ->references('id')
                  ->on('branches')
                  ->onDelete('cascade');
        });
    }
};
