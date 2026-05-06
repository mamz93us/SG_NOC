<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RADIUS NAS clients (switches / APs / NACs allowed to query us).
 *
 * Laravel owns this table — admins create rows from the /admin/radius/nas UI.
 * FreeRADIUS reads it on startup and on `radmin reload clients`. Column
 * names `nasname`, `shortname`, `type`, `secret` match FreeRADIUS' default
 * `nas` schema so the SQL clients query in mods-enabled/sql is minimal.
 *
 * Source IP enforcement happens inside FreeRADIUS itself: a packet from an
 * IP not in this table (after `is_active=1` filter) is silently dropped —
 * the shared secret is never even consulted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('radius_nas_clients', function (Blueprint $table) {
            $table->id();

            // FreeRADIUS-compatible columns — referenced by mods-enabled/sql client_query.
            // nasname is either an IP (10.10.4.5) or a CIDR (10.10.4.0/24).
            $table->string('nasname', 128)->unique();
            $table->string('shortname', 64);
            $table->string('type', 32)->default('other');     // cisco|aruba|meraki|mikrotik|other
            $table->string('secret', 120);                    // shared secret (MVP: plaintext, MySQL-restricted)
            $table->string('description', 255)->nullable();

            // Branch context — used by VLAN policy resolution and the UI.
            $table->unsignedInteger('branch_id')->nullable();
            $table->foreign('branch_id')
                  ->references('id')->on('branches')
                  ->nullOnDelete();

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('branch_id');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('radius_nas_clients');
    }
};
