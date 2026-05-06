<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('onboarding_manager_tokens', function (Blueprint $table) {
            $table->timestamp('reminded_at')->nullable()->after('responded_at');
            $table->unsignedTinyInteger('reminder_count')->default(0)->after('reminded_at');
        });
    }

    public function down(): void
    {
        Schema::table('onboarding_manager_tokens', function (Blueprint $table) {
            $table->dropColumn(['reminded_at', 'reminder_count']);
        });
    }
};
