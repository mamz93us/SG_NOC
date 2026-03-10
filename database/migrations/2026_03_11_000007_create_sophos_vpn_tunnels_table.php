<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sophos_vpn_tunnels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('firewall_id')->constrained('sophos_firewalls')->cascadeOnDelete();
            $table->string('name');
            $table->string('connection_type')->nullable();   // site-to-site, remote-access
            $table->string('policy')->nullable();
            $table->string('remote_gateway')->nullable();
            $table->string('local_subnet')->nullable();
            $table->string('remote_subnet')->nullable();
            $table->string('status')->default('unknown');    // up, down, unknown
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sophos_vpn_tunnels');
    }
};
