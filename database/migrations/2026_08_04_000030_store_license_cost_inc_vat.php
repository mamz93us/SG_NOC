<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Store the VAT-inclusive price directly and drop the separate VAT field.
 *
 * The short-lived vat_rate column kept cost as the ex-VAT figure and derived
 * the rest. The owner's decision is the simpler model: one number, already
 * including tax, as it was before.
 *
 * Conversion runs BEFORE the drop, so the rate is still available to apply.
 * Anything with no rate recorded is left untouched rather than assumed to be
 * ex-VAT and inflated.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('licenses', 'vat_rate')) {
            DB::statement('
                UPDATE licenses
                SET cost = ROUND(cost * (1 + (vat_rate / 100)), 2)
                WHERE cost IS NOT NULL AND vat_rate IS NOT NULL AND vat_rate > 0
            ');

            Schema::table('licenses', function (Blueprint $table) {
                $table->dropColumn('vat_rate');
            });
        }
    }

    /**
     * Restores the column and backs the 15% out again. Only safe because every
     * row that was converted carried 15%; a mixed-rate estate could not be
     * reversed from a single stored number, which is part of why the
     * single-figure model is simpler to live with than to undo.
     */
    public function down(): void
    {
        Schema::table('licenses', function (Blueprint $table) {
            $table->decimal('vat_rate', 5, 2)->nullable()->after('cost');
        });

        DB::statement("
            UPDATE licenses
            SET vat_rate = 15.00,
                cost = ROUND(cost / 1.15, 2)
            WHERE cost IS NOT NULL AND notes LIKE 'PO 44679%'
        ");
    }
};
