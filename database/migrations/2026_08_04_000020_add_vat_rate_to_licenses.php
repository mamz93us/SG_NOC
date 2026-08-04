<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * VAT rate per licence, so cost can stay the ex-VAT unit price the PO quotes
 * while the system can still show what was actually paid.
 *
 * Kept per-row rather than as a global 15% setting: not every licence is bought
 * in-Kingdom, and a zero-rated or foreign-invoiced subscription needs to sit
 * alongside the VAT-bearing ones without a special case.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('licenses', function (Blueprint $table) {
            $table->decimal('vat_rate', 5, 2)->nullable()->after('cost');
        });
    }

    public function down(): void
    {
        Schema::table('licenses', function (Blueprint $table) {
            $table->dropColumn('vat_rate');
        });
    }
};
