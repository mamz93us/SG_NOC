<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alert_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('severity')->default('warning'); // ok, warning, critical
            $table->string('target_type')->default('sensor'); // sensor, printer, host
            // Condition: sensor_class comparison threshold
            $table->string('sensor_class')->nullable(); // e.g. 'toner', 'temperature', 'traffic'
            $table->string('operator')->default('<=');  // <=, >=, ==, !=, <, >
            $table->float('threshold_value')->nullable();
            // Timing controls
            $table->unsignedSmallInteger('delay_seconds')->default(300);    // wait N sec before firing
            $table->unsignedSmallInteger('interval_seconds')->default(3600); // re-notify every N sec
            $table->boolean('recovery_alert')->default(true); // notify on recovery
            $table->boolean('disabled')->default(false);
            // Notification
            $table->boolean('notify_email')->default(true);
            $table->string('notify_emails')->nullable(); // comma-separated extra emails
            $table->boolean('notify_slack')->default(false);
            $table->string('slack_webhook')->nullable();
            $table->timestamps();
        });

        Schema::create('alert_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('alert_rule_id')->constrained()->cascadeOnDelete();
            $table->string('entity_type'); // sensor, printer, host
            $table->unsignedBigInteger('entity_id');
            $table->string('state')->default('ok'); // ok, alerted, acknowledged
            $table->float('triggered_value')->nullable();
            $table->timestamp('first_triggered_at')->nullable();
            $table->timestamp('last_alerted_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->string('acknowledged_by')->nullable();
            $table->timestamp('recovered_at')->nullable();
            $table->unsignedSmallInteger('alert_count')->default(0);
            $table->timestamps();

            $table->unique(['alert_rule_id', 'entity_type', 'entity_id']);
            $table->index(['state', 'entity_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_states');
        Schema::dropIfExists('alert_rules');
    }
};
