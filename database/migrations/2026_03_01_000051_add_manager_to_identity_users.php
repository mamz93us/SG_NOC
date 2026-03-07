<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('identity_users', function (Blueprint $table) {
            $table->string('manager_azure_id', 36)->nullable()->after('azure_id');
            $table->index('manager_azure_id');
        });
    }

    public function down(): void
    {
        Schema::table('identity_users', function (Blueprint $table) {
            $table->dropIndex(['manager_azure_id']);
            $table->dropColumn('manager_azure_id');
        });
    }
};
