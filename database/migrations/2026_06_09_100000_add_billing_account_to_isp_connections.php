<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Some ISP services are billed individually; others are consolidated under
     * one paying account (e.g. one Mobily account pays for several FiberNet
     * circuits across branches). `billing_account_number` captures that payer
     * (null = pays on its own account). `purpose` is the human "Use" label.
     */
    public function up(): void
    {
        Schema::table('isp_connections', function (Blueprint $table) {
            $table->string('billing_account_number', 64)->nullable()->after('account_number')
                ->comment('Consolidated payer account; null = billed on its own account_number');
            $table->string('purpose', 191)->nullable()->after('billing_account_number')
                ->comment('What the link is used for, e.g. "Primary Connection", "SG Open"');

            $table->index('billing_account_number');
        });
    }

    public function down(): void
    {
        Schema::table('isp_connections', function (Blueprint $table) {
            $table->dropIndex(['billing_account_number']);
            $table->dropColumn(['billing_account_number', 'purpose']);
        });
    }
};
