<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * MySQL treats NULLs as distinct in UNIQUE indexes, which defeats the
     * unique(source, source_id) constraint whenever source_id is NULL —
     * two rows with (source='azure', source_id=NULL) both get inserted.
     *
     * We can't simply backfill NULL → '' because manual devices are expected
     * to share "no external id" (source='manual', source_id=NULL is the norm),
     * so collapsing them all to '' would immediately collide under the unique
     * index. Instead: stamp each NULL row with a unique synthetic id tagged
     * by its primary key, then flip the column to NOT NULL so the unique
     * constraint becomes meaningful for future inserts.
     */
    public function up(): void
    {
        DB::table('devices')
            ->whereNull('source_id')
            ->orderBy('id')
            ->lazyById()
            ->each(function ($row) {
                DB::table('devices')
                    ->where('id', $row->id)
                    ->update(['source_id' => 'legacy-' . $row->id]);
            });

        Schema::table('devices', function (Blueprint $table) {
            $table->string('source_id')->default('')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->string('source_id')->nullable()->default(null)->change();
        });

        DB::table('devices')->where('source_id', 'like', 'legacy-%')->update(['source_id' => null]);
    }
};
