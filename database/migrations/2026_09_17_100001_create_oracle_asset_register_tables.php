<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The laptops and desktops in Oracle's fixed-asset register, and which NOC
 * employee and asset each one is.
 *
 * - oracle_assets: one row per UNIT. An Oracle asset number is a purchase
 *   line, not an item: asset 1001946 is thirty HP laptops, one per holder. A
 *   unit is keyed by (asset_number, emp_no, unit), `unit` counting the rare
 *   case of one person holding two units of the same asset. Oracle's own
 *   columns are kept as written; employee_* and device_* say which NOC
 *   employee and asset the unit is, and how that was decided. A unit a later
 *   export no longer lists is stamped removed_at, never deleted.
 *   Only laptops and desktops are kept: the register's software licences,
 *   monitors and the like are counted by the import and left out.
 * - oracle_asset_imports: one row per imported file, with what it changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oracle_asset_imports', function (Blueprint $table) {
            $table->id();
            $table->string('filename')->nullable();
            $table->unsignedInteger('rows')->default(0);
            $table->json('summary')->nullable();
            $table->json('notes')->nullable();
            $table->unsignedBigInteger('imported_by')->nullable();
            $table->timestamps();
        });

        Schema::create('oracle_assets', function (Blueprint $table) {
            $table->id();

            // Oracle's columns.
            $table->string('asset_number', 40);
            $table->unsignedSmallInteger('unit')->default(1);
            $table->string('description', 255);
            $table->date('purchase_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('emp_no', 50)->index();
            $table->string('emp_name', 255)->nullable();

            // The NOC's reading of the description: laptop or desktop.
            $table->string('category', 20)->index();

            $table->unsignedBigInteger('employee_id')->nullable()->index();
            $table->foreign('employee_id')->references('id')->on('employees')->nullOnDelete();
            $table->string('employee_match', 20)->nullable();
            $table->json('employee_candidates')->nullable();

            $table->unsignedBigInteger('device_id')->nullable()->index();
            $table->foreign('device_id')->references('id')->on('devices')->nullOnDelete();
            $table->string('device_match', 20)->nullable();
            $table->json('match_evidence')->nullable();

            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();

            $table->unsignedBigInteger('first_import_id')->nullable();
            $table->unsignedBigInteger('last_import_id')->nullable();
            $table->timestamp('removed_at')->nullable();
            $table->timestamps();

            $table->unique(['asset_number', 'emp_no', 'unit']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oracle_assets');
        Schema::dropIfExists('oracle_asset_imports');
    }
};
