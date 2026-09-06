<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Recurring-subscription billing on `licenses`.
 *
 * Three things the table could not express before, all of which finance needs
 * in order to actually pay a subscription rather than just know it exists:
 *
 *  - `license_type` widened from an ENUM to VARCHAR so 'ai' (and whatever
 *    category comes next) can be added in the model without a schema change.
 *    Same move as workflow_requests.type. Validation lives in the controller.
 *  - `billing_cycle` — a per-seat cost means nothing to finance without the
 *    period it covers. 'one_time' is the default so every existing perpetual /
 *    OEM / freeware row keeps meaning exactly what it meant before.
 *  - `payment_method` + `payment_account` — a card-billed subscription needs
 *    nobody to act on renewal day; a wire transfer needs somebody to raise a
 *    payment. That difference is the entire point of the payments report, so
 *    it is a column and not a note.
 *
 * Deliberately NOT enums: adding a payment method should not need a migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Widen license_type. MySQL takes a MODIFY; SQLite (test suite) rebuilds
        // the column, which is why this is not a bare DB::statement.
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE licenses MODIFY COLUMN license_type VARCHAR(20) NOT NULL DEFAULT 'perpetual'");
        } else {
            Schema::table('licenses', function (Blueprint $table) {
                $table->string('license_type', 20)->default('perpetual')->change();
            });
        }

        Schema::table('licenses', function (Blueprint $table) {
            $table->string('billing_cycle', 20)->default('one_time')->after('license_type');
            $table->string('payment_method', 20)->nullable()->after('currency');
            $table->string('payment_account', 100)->nullable()->after('payment_method');
        });
    }

    public function down(): void
    {
        Schema::table('licenses', function (Blueprint $table) {
            $table->dropColumn(['billing_cycle', 'payment_method', 'payment_account']);
        });

        // Anything typed 'ai' has no home in the original enum — park it back on
        // 'subscription' so the column can narrow without losing rows.
        DB::table('licenses')->whereNotIn('license_type', ['subscription', 'perpetual', 'oem', 'freeware'])
            ->update(['license_type' => 'subscription']);

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE licenses MODIFY COLUMN license_type ENUM('subscription','perpetual','oem','freeware') NOT NULL DEFAULT 'perpetual'");
        }
    }
};
