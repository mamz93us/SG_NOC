<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('workflow_requests', function (Blueprint $table) {
            // Allow null for API-submitted workflows that have no authenticated user
            $table->unsignedBigInteger('requested_by')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('workflow_requests', function (Blueprint $table) {
            $table->unsignedBigInteger('requested_by')->nullable(false)->change();
        });
    }
};
