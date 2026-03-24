<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('printer_supplies', function (Blueprint $table) {
            $table->unsignedTinyInteger('low_alert_threshold')->nullable()->after('critical_threshold')
                  ->comment('Override threshold for low-toner alert (null = use warning_threshold)');
            $table->boolean('is_low_alert_active')->default(false)->after('low_alert_threshold')
                  ->comment('True while toner is below threshold and alert has been sent');
            $table->timestamp('low_alert_sent_at')->nullable()->after('is_low_alert_active')
                  ->comment('When the last low-toner alert was sent');
        });
    }

    public function down(): void
    {
        Schema::table('printer_supplies', function (Blueprint $table) {
            $table->dropColumn(['low_alert_threshold', 'is_low_alert_active', 'low_alert_sent_at']);
        });
    }
};
