<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            // Set when Azure AD account is disabled (but user not yet removed/terminated)
            $table->timestamp('azure_disabled_at')->nullable()->after('terminated_date');
            // Set when the Azure AD account is fully deleted from the directory
            $table->timestamp('azure_removed_at')->nullable()->after('azure_disabled_at');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn(['azure_disabled_at', 'azure_removed_at']);
        });
    }
};
