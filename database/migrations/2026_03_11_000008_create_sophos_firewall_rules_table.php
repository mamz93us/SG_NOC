<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sophos_firewall_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('firewall_id')->constrained('sophos_firewalls')->cascadeOnDelete();
            $table->string('rule_name');
            $table->unsignedInteger('position')->default(0);
            $table->string('source_zone')->nullable();
            $table->string('dest_zone')->nullable();
            $table->text('source_networks')->nullable();     // JSON
            $table->text('dest_networks')->nullable();       // JSON
            $table->text('services')->nullable();            // JSON
            $table->string('action')->default('drop');       // accept, drop, reject
            $table->boolean('enabled')->default(true);
            $table->boolean('log_traffic')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sophos_firewall_rules');
    }
};
