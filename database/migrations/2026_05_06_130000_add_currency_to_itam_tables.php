<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('licenses', function (Blueprint $table) {
            $table->string('currency', 3)->default('USD')->after('cost');
        });

        Schema::table('devices', function (Blueprint $table) {
            $table->string('currency', 3)->default('USD')->after('purchase_cost');
        });

        Schema::table('accessories', function (Blueprint $table) {
            $table->string('currency', 3)->default('USD')->after('purchase_cost');
        });

        DB::table('licenses')->whereNull('currency')->update(['currency' => 'USD']);
        DB::table('devices')->whereNull('currency')->update(['currency' => 'USD']);
        DB::table('accessories')->whereNull('currency')->update(['currency' => 'USD']);
    }

    public function down(): void
    {
        Schema::table('licenses', function (Blueprint $table) {
            $table->dropColumn('currency');
        });

        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn('currency');
        });

        Schema::table('accessories', function (Blueprint $table) {
            $table->dropColumn('currency');
        });
    }
};
