<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            if (! Schema::hasColumn('settings', 'marketing_domain')) {
                // Isolated subdomain that serves the email-marketing portal.
                // Editable in the UI; resolved by App\Support\Marketing.
                $table->string('marketing_domain')->nullable()->default('em.samirgroup.net');
            }
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            if (Schema::hasColumn('settings', 'marketing_domain')) {
                $table->dropColumn('marketing_domain');
            }
        });
    }
};
