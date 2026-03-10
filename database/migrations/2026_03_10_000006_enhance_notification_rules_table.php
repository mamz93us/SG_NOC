<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_rules', function (Blueprint $table) {
            $table->string('module', 50)->nullable()->after('event_type');
            $table->unsignedBigInteger('sensor_id')->nullable()->after('module');
            $table->string('severity', 20)->nullable()->after('sensor_id');
            $table->boolean('notify_telegram')->default(false)->after('send_in_app');
            $table->boolean('notify_sms')->default(false)->after('notify_telegram');
            $table->boolean('notify_dashboard')->default(true)->after('notify_sms');
            $table->integer('cooldown_minutes')->default(0)->after('notify_dashboard');
        });
    }

    public function down(): void
    {
        Schema::table('notification_rules', function (Blueprint $table) {
            $table->dropColumn([
                'module', 'sensor_id', 'severity',
                'notify_telegram', 'notify_sms', 'notify_dashboard', 'cooldown_minutes',
            ]);
        });
    }
};
