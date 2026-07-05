<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * NOC-AGW (Access Gateway) tables. The gateway is a separate FastAPI
     * service (noc-agw/) that fronts the legacy IIS app on
     * arcmate.samirgroup.net; it reads these tables directly from phonebook2:
     *
     *   agw_allowlist   — source IPs/CIDRs allowed to reach the app. Branch
     *                     WAN IPs are synced in as source='dynamic' by the
     *                     `agw:sync-allowlist` command; NOC staff add fixed
     *                     ranges as source='manual'.
     *   agw_audit       — one row per request decision (allow / deny_ip),
     *                     written by the gateway, surfaced read-only in the NOC.
     *   agw_ip_history  — per-branch dynamic-IP change trail for troubleshooting.
     */
    public function up(): void
    {
        Schema::create('agw_allowlist', function (Blueprint $table) {
            $table->id();
            $table->string('cidr', 43)->unique()
                ->comment('IPv4/IPv6 with prefix, e.g. 197.x.x.x/32');
            $table->string('branch', 64)->nullable()
                ->comment('lowercase branch code, e.g. jed — matches branch_agents.code');
            $table->enum('source', ['dynamic', 'manual'])->default('dynamic')
                ->comment('dynamic = synced from branch WAN IPs; manual = never overwritten');
            $table->boolean('active')->default(true);
            $table->string('note', 255)->nullable();
            $table->timestamps();

            $table->index(['active', 'source']);
        });

        Schema::create('agw_audit', function (Blueprint $table) {
            $table->id();
            $table->dateTime('ts', 3);
            $table->string('client_ip', 45);
            $table->string('user_email', 255)->nullable()
                ->comment('from Entra via oauth2-proxy once SSO is enabled; null in IP-only mode');
            $table->string('user_name', 255)->nullable();
            $table->string('method', 8)->nullable();
            $table->string('path', 1024)->nullable();
            $table->smallInteger('status')->nullable()
                ->comment('upstream HTTP status');
            $table->enum('decision', ['allow', 'deny_ip', 'deny_auth']);
            $table->string('reason', 255)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->integer('latency_ms')->nullable();

            $table->index('ts');
            $table->index('client_ip');
            $table->index('decision');
        });

        Schema::create('agw_ip_history', function (Blueprint $table) {
            $table->id();
            $table->string('branch', 64)->nullable();
            $table->string('old_ip', 45)->nullable();
            $table->string('new_ip', 45)->nullable();
            $table->timestamp('changed_at')->useCurrent();

            $table->index('branch');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agw_ip_history');
        Schema::dropIfExists('agw_audit');
        Schema::dropIfExists('agw_allowlist');
    }
};
