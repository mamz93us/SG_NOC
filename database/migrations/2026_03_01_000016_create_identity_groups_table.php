<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('identity_groups', function (Blueprint $table) {
            $table->id();
            $table->string('azure_id', 36)->unique()->index();
            $table->string('display_name');
            $table->text('description')->nullable();
            $table->unsignedInteger('members_count')->default(0);
            $table->string('group_type')->nullable();        // Unified / Security / etc.
            $table->boolean('mail_enabled')->default(false);
            $table->boolean('security_enabled')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('identity_groups');
    }
};
