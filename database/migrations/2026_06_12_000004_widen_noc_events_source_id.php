<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sophos Central alert IDs are UUID strings; source_id was an unsigned
     * bigint. Widen to varchar — existing integer IDs keep matching because
     * MySQL coerces '5' = 5 in comparisons.
     */
    public function up(): void
    {
        Schema::table('noc_events', function (Blueprint $table) {
            $table->string('source_id', 64)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('noc_events', function (Blueprint $table) {
            $table->unsignedBigInteger('source_id')->nullable()->change();
        });
    }
};
