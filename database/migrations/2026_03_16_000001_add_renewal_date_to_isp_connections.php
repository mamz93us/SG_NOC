<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('isp_connections', function (Blueprint $table) {
            $table->date('renewal_date')->nullable()->after('contract_end');
            $table->integer('renewal_remind_days')->default(2)->after('renewal_date');
            $table->timestamp('renewal_reminded_at')->nullable()->after('renewal_remind_days');
        });
    }

    public function down(): void
    {
        Schema::table('isp_connections', function (Blueprint $table) {
            $table->dropColumn(['renewal_date', 'renewal_remind_days', 'renewal_reminded_at']);
        });
    }
};
