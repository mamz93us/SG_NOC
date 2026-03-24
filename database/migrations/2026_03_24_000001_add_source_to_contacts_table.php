<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            // 'gdms' = synced from GDMS SIP accounts (auto-managed, deletable)
            // 'manual' = created by admin (never auto-deleted)
            $table->string('source', 20)->default('manual')->after('branch_id');
            $table->timestamp('gdms_synced_at')->nullable()->after('source');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn(['source', 'gdms_synced_at']);
        });
    }
};
