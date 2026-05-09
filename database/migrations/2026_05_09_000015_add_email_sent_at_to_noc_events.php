<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('noc_events', function (Blueprint $table) {
            $table->timestamp('email_sent_at')->nullable()->after('cooldown_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('noc_events', function (Blueprint $table) {
            $table->dropColumn('email_sent_at');
        });
    }
};
