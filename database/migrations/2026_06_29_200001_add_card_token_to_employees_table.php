<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->uuid('card_token')->nullable()->unique()->after('notes');
        });

        // Backfill tokens for existing employees
        DB::table('employees')->whereNull('card_token')->orderBy('id')->each(function ($row) {
            DB::table('employees')->where('id', $row->id)->update(['card_token' => Str::uuid()->toString()]);
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('card_token');
        });
    }
};
