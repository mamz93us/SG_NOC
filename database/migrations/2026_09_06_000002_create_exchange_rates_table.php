<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Exchange rates for the finance reports' combined totals.
 *
 * Its own table rather than columns on `settings`: that table is one wide row
 * already sitting at ~64.7 KB of InnoDB's 65,535-byte limit (see the Samsung
 * Wallet migration), so it has no room. It would also be the wrong home — a
 * rate used to produce a number finance acts on needs to say who set it, when,
 * and from what source, and a settings column cannot carry that.
 *
 * One row per currency, holding units of that currency per 1 base unit (USD).
 * Cross-rates are computed through the base, so EGP -> SAR is one division and
 * one multiplication rather than a pair that can drift out of step with EGP ->
 * USD. `rate_date` is the day the rate applies to, which is not the day the row
 * was edited — a rate keyed in on the 6th for the 1st is normal at month end.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->string('currency', 3)->unique();
            // 6dp: EGP/USD needs 2, but a cross-rate through a weak base loses
            // precision fast, and rounding a rate is not the report's job.
            $table->decimal('units_per_base', 18, 6);
            $table->date('rate_date');
            $table->string('source', 150)->nullable();
            $table->unsignedBigInteger('updated_by_user_id')->nullable();
            $table->timestamps();

            $table->foreign('updated_by_user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');
    }
};
