<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per branch VM running `sg-branch-agent` (the consolidated Go
     * agent that replaces deployment/branch-vm/). This table owns the
     * agent's identity, enrollment, heartbeat health and DDNS state.
     *
     * It links 1:1 (by `code`) to branch_log_collectors, which still powers
     * the NOC's on-demand log search — enrollment auto-provisions that row
     * so /admin/logs/branches can query the agent with no extra setup.
     *
     * Auth model: a single long-lived `api_token` (encrypted at rest) is the
     * shared secret in BOTH directions — the agent sends it to the NOC
     * (heartbeat/ddns/config) and the NOC sends it back to the agent
     * (log search). Issued by the NOC at enrollment.
     */
    public function up(): void
    {
        Schema::create('branch_agents', function (Blueprint $table) {
            $table->id();
            $table->string('code', 8)->unique()
                ->comment('lowercase branch code, e.g. jed. Matches branch_log_collectors.code.');
            $table->string('name', 100);

            // Network endpoint of the agent over the IPsec tunnel. Used to
            // populate the linked branch_log_collectors row for log search.
            $table->string('hostname', 255)->nullable()
                ->comment('IPsec tunnel-side IP/host of the branch VM; set at enrollment.');
            $table->unsignedSmallInteger('port')->default(8080)
                ->comment('Port the agent serves its UI + machine API on.');

            // Long-lived token issued on enrollment (encrypted via model cast).
            $table->text('api_token')->nullable();

            // One-time enrollment handshake.
            $table->string('enrollment_code', 32)->nullable()->unique()
                ->comment('Short single-use code shown in the NOC UI, entered in the agent wizard.');
            $table->timestamp('enrollment_expires_at')->nullable();

            $table->boolean('enabled')->default(true);

            // Heartbeat / health.
            $table->string('agent_version', 32)->nullable();
            $table->timestamp('last_heartbeat_at')->nullable();
            $table->json('last_health')->nullable()
                ->comment('Last heartbeat snapshot: disk/ram %, ingest rate, device summary.');
            $table->string('status', 16)->default('pending')
                ->comment('pending | healthy | stale | down');

            // DDNS state.
            $table->string('wan_ip', 45)->nullable();
            $table->timestamp('wan_ip_updated_at')->nullable();
            $table->string('dns_domain', 255)->nullable()
                ->comment('e.g. branch.samirgroup.net');
            $table->string('dns_subdomain', 100)->nullable()
                ->comment('e.g. jed → jed.branch.samirgroup.net');
            $table->unsignedBigInteger('dns_account_id')->nullable()
                ->comment('FK dns_accounts — which GoDaddy account holds the zone.');
            $table->unsignedBigInteger('vpn_tunnel_id')->nullable()
                ->comment('FK vpn_tunnels — tunnel whose remote endpoint tracks this WAN IP.');

            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('enabled');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_agents');
    }
};
