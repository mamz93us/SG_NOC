<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('identity_users', function (Blueprint $table) {
            $table->id();
            $table->string('azure_id', 36)->unique()->index();
            $table->string('display_name');
            $table->string('user_principal_name')->unique()->index();
            $table->string('mail')->nullable()->index();
            $table->string('job_title')->nullable();
            $table->string('department')->nullable()->index();
            $table->boolean('account_enabled')->default(true)->index();
            $table->unsignedSmallInteger('licenses_count')->default(0);
            $table->unsignedSmallInteger('groups_count')->default(0);
            $table->string('usage_location', 5)->nullable();
            $table->json('assigned_licenses')->nullable();  // cached SKU IDs
            $table->json('member_of')->nullable();          // cached group IDs
            $table->json('raw_data')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('identity_users');
    }
};
