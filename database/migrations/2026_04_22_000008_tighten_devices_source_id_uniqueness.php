<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * MySQL treats NULLs as distinct in UNIQUE indexes, which defeats the
     * unique(source, source_id) constraint on manual devices where source_id
     * is typically NULL. Backfill nulls to '' and make the column NOT NULL
     * so the unique index actually prevents duplicates per source.
     */
    public function up(): void
    {
        DB::table('devices')->whereNull('source_id')->update(['source_id' => '']);

        Schema::table('devices', function (Blueprint $table) {
            $table->string('source_id')->default('')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->string('source_id')->nullable()->default(null)->change();
        });
    }
};
