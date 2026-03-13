<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('devices', 'asset_code')) {
            Schema::table('devices', function (Blueprint $table) {
                $table->string('asset_code', 50)->nullable()->unique()->index()->after('id');
                $table->decimal('purchase_cost', 15, 2)->nullable();
                $table->unsignedBigInteger('supplier_id')->nullable()->index();
                $table->enum('condition', ['new', 'used', 'refurbished', 'damaged'])->default('new');
                $table->enum('depreciation_method', ['straight_line', 'none'])->default('none');
                $table->unsignedSmallInteger('depreciation_years')->nullable();
                $table->decimal('current_value', 15, 2)->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $columns = [
                'asset_code',
                'purchase_cost',
                'supplier_id',
                'condition',
                'depreciation_method',
                'depreciation_years',
                'current_value',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('devices', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
