<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_events', function (Blueprint $table) {
            $table->string('country_code', 2)->nullable()->after('ip_address');
            $table->string('country_name', 100)->nullable()->after('country_code');
            $table->index('country_code');
        });
    }

    public function down(): void
    {
        Schema::table('email_events', function (Blueprint $table) {
            $table->dropIndex(['country_code']);
            $table->dropColumn(['country_code', 'country_name']);
        });
    }
};
