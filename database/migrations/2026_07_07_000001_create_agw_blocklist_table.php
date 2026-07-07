<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Access-Gateway blocklist: source IPs/CIDRs that are ALWAYS denied,
     * regardless of the allowlist or the enforce-IP-ACL toggle. The gateway
     * checks this first, so a blocked IP stays blocked even while the ACL is
     * in allow-all mode for testing. IPs are typically added straight from the
     * audit log.
     */
    public function up(): void
    {
        Schema::create('agw_blocklist', function (Blueprint $table) {
            $table->id();
            $table->string('cidr', 43)->unique()
                ->comment('IPv4/IPv6 with prefix, e.g. 203.0.113.7/32');
            $table->boolean('active')->default(true);
            $table->string('note', 255)->nullable();
            $table->timestamps();

            $table->index('active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agw_blocklist');
    }
};
