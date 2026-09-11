<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The terminals a code punched on, like `areas` — so a pin that matches two
 * employees can be settled by a terminal's branch when there is no area.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('biotime_employees', function (Blueprint $table) {
            $table->json('terminals')->nullable()->after('areas');
        });
    }

    public function down(): void
    {
        Schema::table('biotime_employees', function (Blueprint $table) {
            $table->dropColumn('terminals');
        });
    }
};
