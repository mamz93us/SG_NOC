<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds gender as a third optional dimension to the auto-provisioning group
 * mappings, so a branch can hand female staff one set of distribution groups
 * and male staff another.
 *
 * NULL means "any", exactly like branch_id and department_id already do — so
 * every mapping that exists today keeps matching everyone, unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branch_department_group_mappings', function (Blueprint $table) {
            $table->string('gender', 10)->nullable()->after('department_id');
        });
    }

    public function down(): void
    {
        Schema::table('branch_department_group_mappings', function (Blueprint $table) {
            $table->dropColumn('gender');
        });
    }
};
