<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Access-Gateway runtime config, editable from the NOC "Access Gateway"
     * admin page. The noc-agw FastAPI service reads these two columns from the
     * settings singleton (row 1) on its refresh loop, so the upstream URL and
     * the IP-ACL toggle can change with no gateway restart.
     */
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->string('agw_backend_url')->nullable()
                ->comment('Legacy IIS app upstream, e.g. http://10.0.0.20:8891');
            $table->boolean('agw_enforce_ip_acl')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn(['agw_backend_url', 'agw_enforce_ip_acl']);
        });
    }
};
