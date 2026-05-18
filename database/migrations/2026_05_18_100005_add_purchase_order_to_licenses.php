<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('licenses', function (Blueprint $table) {
            $table->unsignedBigInteger('purchase_order_id')->nullable()->after('supplier_id');
            $table->foreign('purchase_order_id')->references('id')->on('purchase_orders')->nullOnDelete();
            $table->index('purchase_order_id');

            // currency was missing on licenses (form/Currency::CODES sets it but no column)
            if (! Schema::hasColumn('licenses', 'currency')) {
                $table->string('currency', 3)->nullable()->after('cost');
            }
        });
    }

    public function down(): void
    {
        Schema::table('licenses', function (Blueprint $table) {
            $table->dropForeign(['purchase_order_id']);
            $table->dropIndex(['purchase_order_id']);
            $table->dropColumn('purchase_order_id');
        });
    }
};
