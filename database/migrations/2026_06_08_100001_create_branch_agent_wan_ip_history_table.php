<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Audit trail of every WAN-IP change reported by a branch agent's DDNS
     * reporter, and whether the resulting DNS + VPN-tunnel updates applied
     * cleanly. One row per observed change.
     */
    public function up(): void
    {
        Schema::create('branch_agent_wan_ip_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_agent_id')
                ->constrained('branch_agents')
                ->cascadeOnDelete();
            $table->string('ip', 45);
            $table->string('previous_ip', 45)->nullable();
            $table->boolean('applied_dns')->default(false);
            $table->boolean('applied_tunnel')->default(false);
            $table->text('note')->nullable()
                ->comment('Error detail when an apply step failed, else null.');
            $table->timestamp('changed_at');
            $table->timestamps();

            $table->index(['branch_agent_id', 'changed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_agent_wan_ip_history');
    }
};
