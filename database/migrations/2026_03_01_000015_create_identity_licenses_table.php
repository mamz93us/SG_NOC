<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('identity_licenses', function (Blueprint $table) {
            $table->id();
            $table->string('sku_id', 36)->unique()->index();
            $table->string('sku_part_number');
            $table->string('display_name');
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('consumed')->default(0);
            $table->unsignedInteger('available')->default(0);
            $table->string('applies_to')->nullable();        // User / Company
            $table->enum('capability_status', ['Enabled', 'Suspended', 'Deleted', 'LockedOut'])->default('Enabled');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('identity_licenses');
    }
};
