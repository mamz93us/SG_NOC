<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Each Schema::table is its own ALTER statement so a partial re-run
        // after a failure (e.g. currency already exists from a prior schema
        // edit) doesn't fail on columns that were already added.

        if (! Schema::hasColumn('accessories', 'purchase_order_id')) {
            Schema::table('accessories', function (Blueprint $table) {
                $table->unsignedBigInteger('purchase_order_id')->nullable()->after('supplier_id');
                $table->foreign('purchase_order_id')->references('id')->on('purchase_orders')->nullOnDelete();
                $table->index('purchase_order_id');
            });
        }

        if (! Schema::hasColumn('accessories', 'branch_id')) {
            Schema::table('accessories', function (Blueprint $table) {
                // Accessories today live in a global pool — adding branch_id so PO entries
                // can stock a specific branch store ("which store to add to").
                $table->unsignedInteger('branch_id')->nullable()->after('purchase_order_id');
                $table->foreign('branch_id')->references('id')->on('branches')->nullOnDelete();
                $table->index('branch_id');
            });
        }

        if (! Schema::hasColumn('accessories', 'currency')) {
            Schema::table('accessories', function (Blueprint $table) {
                $table->string('currency', 3)->nullable()->after('purchase_cost');
            });
        }
    }

    public function down(): void
    {
        Schema::table('accessories', function (Blueprint $table) {
            if (Schema::hasColumn('accessories', 'purchase_order_id')) {
                $table->dropForeign(['purchase_order_id']);
                $table->dropIndex(['purchase_order_id']);
                $table->dropColumn('purchase_order_id');
            }

            if (Schema::hasColumn('accessories', 'branch_id')) {
                $table->dropForeign(['branch_id']);
                $table->dropIndex(['branch_id']);
                $table->dropColumn('branch_id');
            }
        });
        // Note: we don't drop currency on rollback — it may have been there
        // before this migration ran on some environments.
    }
};
