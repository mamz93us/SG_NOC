<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where "you have training outstanding" sends people.
 *
 * KnowBe4's learner portal address differs per tenant, so it cannot be
 * hardcoded. Left blank the portal still shows the warning — it just tells the
 * person to use the link in their KnowBe4 email instead of offering a button
 * that might go nowhere.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->string('knowbe4_training_url', 500)->nullable()->after('knowbe4_last_sync_at');
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn('knowbe4_training_url');
        });
    }
};
