<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Branch-level log-collector VMs (deployment/branch-vm/).
     * One row per branch VM. Drives the /admin/logs/branches search UI.
     *
     * Separate from the existing `branches` table because that's the
     * office identity (UCM, switches, employees). This table only
     * carries the IPsec-side host + bearer token needed for log queries.
     */
    public function up(): void
    {
        Schema::create('branch_log_collectors', function (Blueprint $table) {
            $table->id();
            $table->string('code', 8)->unique()
                ->comment('lowercase branch code, e.g. jed, ryd. Must match BRANCH_ID on the VM.');
            $table->string('name', 100);
            $table->string('host', 255)
                ->comment('IPsec tunnel-side IP or hostname of the branch VM');
            $table->unsignedSmallInteger('port')->default(8514);
            $table->text('api_token')->nullable()
                ->comment('Bearer token; encrypted at rest via the model cast.');
            $table->boolean('enabled')->default(true);
            $table->timestamp('last_seen_at')->nullable()
                ->comment('Updated by /admin/branches/log-collectors/{id}/test');
            $table->string('last_health_status', 16)->nullable()
                ->comment('healthy | unreachable | unauthorized | error');
            $table->text('last_error')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('enabled');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_log_collectors');
    }
};
